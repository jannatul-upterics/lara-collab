<?php

namespace App\Http\Controllers;

use App\Actions\Task\CreateTask;
use App\Actions\Task\UpdateTask;
use App\Events\Task\TaskDeleted;
use App\Events\Task\TaskGroupChanged;
use App\Events\Task\TaskOrderChanged;
use App\Events\Task\TaskRestored;
use App\Events\Task\TaskUpdated;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskPriorityResource;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Label;
use App\Models\OwnerCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TaskPriority;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, Project $project, ?Task $task = null): Response
    {
        $this->authorize('viewAny', [Task::class, $project]);

        $groups = $project
            ->taskGroups()
            ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
            ->get();

        $groupedTasks = $project
            ->taskGroups()
            ->with(['project' => fn ($query) => $query->withArchived()])
            ->get()
            ->mapWithKeys(function (TaskGroup $group) use ($request, $project) {
                $prioritySort = data_get($request->input('sort', []), 'priority');

                return [
                    $group->id => Task::where('project_id', $project->id)
                        ->where('group_id', $group->id)
                        ->searchByQueryString()
                        ->filterByQueryString()
                        ->when($request->user()->hasRole('client'), fn ($query) => $query->where('hidden_from_clients', false))
                        ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                        ->when(! $request->has('status'), fn ($query) => $query->whereNull('completed_at'))
                        ->withDefault()
                        ->when($project->isArchived(), fn ($query) => $query->with(['project' => fn ($query) => $query->withArchived()]))
                        ->when($prioritySort, function ($query, $direction) {
                            $direction = $direction === 'asc' ? 'asc' : 'desc';

                            $query
                                ->leftJoin('task_priorities', 'tasks.priority_id', '=', 'task_priorities.id')
                                ->orderByRaw('tasks.priority_id IS NULL')
                                ->orderBy('task_priorities.order', $direction)
                                ->orderByDesc('tasks.created_at')
                                ->select('tasks.*');
                        }, function ($query) {
                            $query->orderByDesc('created_at');
                        })
                        ->get(),
                ];
            });

        return Inertia::render('Projects/Tasks/Index', [
            'project' => $project,
            'usersWithAccessToProject' => PermissionService::usersWithAccessToProject($project),
            'labels' => Label::get(['id', 'name', 'color']),
            'priorities' => TaskPriorityResource::collection(TaskPriority::orderBy('order')->get()),
            'taskGroups' => $groups,
            'groupedTasks' => $groupedTasks,
            'openedTask' => $task ? $task->loadDefault() : null,
            'currency' => [
                'symbol' => OwnerCompany::with('currency')->first()?->currency?->symbol ?? '$',
            ],
        ]);
    }

    public function store(StoreTaskRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Task::class, $project]);

        (new CreateTask)->create($project, $request->validated());

        return redirect()->route('projects.tasks', $project)->success('Task added', 'A new task was successfully added.');
    }

    public function update(UpdateTaskRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('update', [$task, $project]);

        (new UpdateTask)->update($task, $request->validated());

        return response()->json();
    }

    public function reorder(Request $request, Project $project): JsonResponse
    {
        $this->authorize('reorder', [Task::class, $project]);

        Task::setNewOrder($request->ids);

        TaskOrderChanged::dispatch(
            $project->id,
            $request->group_id,
            $request->from_index,
            $request->to_index,
        );

        return response()->json();
    }

    public function move(Request $request, Project $project): JsonResponse
    {
        $this->authorize('reorder', [Task::class, $project]);

        Task::setNewOrder($request->ids);
        Task::whereIn('id', $request->ids)->update(['group_id' => $request->to_group_id]);

        TaskGroupChanged::dispatch(
            $project->id,
            $request->from_group_id,
            $request->to_group_id,
            $request->from_index,
            $request->to_index,
        );

        return response()->json();
    }

    public function complete(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('complete', [Task::class, $project]);

        $task->update([
            'completed_at' => ($request->completed === true) ? now() : null,
        ]);
        TaskUpdated::dispatch($task, 'completed_at');

        return response()->json();
    }

    public function destroy(Project $project, Task $task): RedirectResponse
    {
        $this->authorize('delete', [$task, $project]);

        $task->archive();
        TaskDeleted::dispatch($task->id, $task->project_id);

        return redirect()->back()->success('Task archived', 'The task was successfully archived.');
    }

    public function restore(Project $project, Task $task)
    {

        $this->authorize('restore', [$task, $project]);

        $task->unArchive();
        TaskRestored::dispatch($task);

        return redirect()->back()->success('Task restored', 'The restoring of the Task was completed successfully.');
    }

    public function permanentDelete(Project $project, Task $task): RedirectResponse
    {
        try {
            return DB::transaction(function () use ($project, $task) {
                $task = Task::withArchived()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->findOrFail($task->id);

                $this->authorize('forceDelete', [$task, $project]);

                if (! $task->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The task is not archived. Only archived tasks can be permanently deleted.');
                }

                if ($task->invoice_id !== null) {
                    $invoiceNumber = $task->invoice?->number ?? $task->invoice_id;
                    return redirect()->back()->warning('Action stopped', "Cannot permanently delete this task because it is linked to an invoice (#{$invoiceNumber}). Invoiced tasks must be retained for financial records.");
                }

                // Attachments & physical files
                foreach ($task->attachments as $attachment) {
                    if (! empty($attachment->path) && File::exists(public_path($attachment->path))) {
                        File::delete(public_path($attachment->path));
                    }
                    if (! empty($attachment->thumb) && File::exists(public_path($attachment->thumb))) {
                        File::delete(public_path($attachment->thumb));
                    }
                    $attachment->delete();
                }

                // Comments & their activities
                foreach ($task->comments as $comment) {
                    Activity::where('activity_capable_type', Comment::class)->where('activity_capable_id', $comment->id)->delete();
                    $comment->delete();
                }

                // Time logs
                $task->timeLogs()->delete();

                // Detach pivots
                DB::table('label_task')->where('task_id', $task->id)->delete();
                DB::table('subscribe_task')->where('task_id', $task->id)->delete();

                // Task activities
                Activity::where('activity_capable_type', Task::class)->where('activity_capable_id', $task->id)->delete();

                $taskName = $task->name;
                $taskId = $task->id;
                $task->delete();

                TaskDeleted::dispatch($taskId, $project->id);

                Log::info("Task #{$taskId} ('{$taskName}') in Project #{$project->id} permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Task deleted', 'The task was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete task #{$task->id}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This task cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete task #{$task->id}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the task: ' . $e->getMessage());
        }
    }
}
