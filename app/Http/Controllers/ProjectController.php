<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\Project\ProjectResource;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\ClientCompany;
use App\Models\Comment;
use App\Models\Currency;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Project::class, 'project');
    }

    public function index(Request $request)
    {
        return Inertia::render('Projects/Index', [
            'items' => ProjectResource::collection(
                Project::searchByQueryString()
                    ->when($request->user()->isNotAdmin(), function ($query) {
                        $query->whereHas('clientCompany.clients', fn ($query) => $query->where('users.id', auth()->id()))
                            ->orWhereHas('users', fn ($query) => $query->where('id', auth()->id()));
                    })
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->with([
                        'clientCompany:id,name',
                        'clientCompany.clients:id,name,avatar',
                        'users:id,name,avatar',
                    ])
                    ->withCount([
                        'tasks AS all_tasks_count',
                        'tasks AS completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at'),
                        'tasks AS overdue_tasks_count' => fn ($query) => $query->whereNull('completed_at')->whereDate('due_on', '<', now()),
                    ])
                    ->withExists('favoritedByAuthUser AS favorite')
                    ->orderBy('favorite', 'desc')
                    ->orderBy('name', 'asc')
                    ->get()
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('Projects/Create', [
            'dropdowns' => [
                'companies' => ClientCompany::dropdownValues(),
                'users' => User::userDropdownValues(),
                'currencies' => Currency::dropdownValues(['with' => ['clientCompanies:id,currency_id']]),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();

        $data['rate'] *= 100;

        $project = Project::create($data);

        $project->users()->attach($data['users']);

        $project->taskGroups()->createMany([
            ['name' => 'Backlog'],
            ['name' => 'Todo'],
            ['name' => 'In progress'],
            ['name' => 'QA'],
            ['name' => 'Done'],
            ['name' => 'Deployed'],
        ]);

        return redirect()->route('projects.index')->success('Project created', 'A new project was successfully created.');
    }

    public function edit(Project $project)
    {
        return Inertia::render('Projects/Edit', [
            'item' => $project,
            'dropdowns' => [
                'companies' => ClientCompany::dropdownValues(),
                'users' => User::userDropdownValues(),
                'currencies' => Currency::dropdownValues(['with' => ['clientCompanies:id,currency_id']]),
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $data = $request->validated();

        $data['rate'] *= 100;

        $project->update($data);

        $project->users()->sync($data['users']);

        return redirect()->route('projects.index')->success('Project updated', 'The project was successfully updated.');
    }

    public function destroy(Project $project)
    {
        $project->archive();

        return redirect()->back()->success('Project archived', 'The project was successfully archived.');
    }

    public function restore(int $projectId)
    {
        $project = Project::withArchived()->findOrFail($projectId);

        $this->authorize('restore', $project);

        $project->unArchive();

        return redirect()->back()->success('Project restored', 'The restoring of the project was completed successfully.');
    }

    public function permanentDelete(int $projectId)
    {
        try {
            return DB::transaction(function () use ($projectId) {
                $project = Project::withArchived()->lockForUpdate()->findOrFail($projectId);

                $this->authorize('forceDelete', $project);

                if (! $project->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The project is not archived. Only archived projects can be permanently deleted.');
                }

                $hasInvoicedTasks = Task::withArchived()
                    ->where('project_id', $project->id)
                    ->whereNotNull('invoice_id')
                    ->exists();

                if ($hasInvoicedTasks) {
                    return redirect()->back()->warning('Action stopped', 'Cannot permanently delete this project because it contains tasks that have been billed on an invoice. Invoiced tasks must be retained for financial records.');
                }

                $taskIds = Task::withArchived()->where('project_id', $project->id)->pluck('id');

                // Attachments
                $attachments = Attachment::whereIn('task_id', $taskIds)->get();
                foreach ($attachments as $attachment) {
                    if (! empty($attachment->path) && File::exists(public_path($attachment->path))) {
                        File::delete(public_path($attachment->path));
                    }
                    if (! empty($attachment->thumb) && File::exists(public_path($attachment->thumb))) {
                        File::delete(public_path($attachment->thumb));
                    }
                    $attachment->delete();
                }

                // Comments & comment activities
                $comments = Comment::whereIn('task_id', $taskIds)->get();
                foreach ($comments as $comment) {
                    Activity::where('activity_capable_type', Comment::class)->where('activity_capable_id', $comment->id)->delete();
                    $comment->delete();
                }

                // Time logs
                TimeLog::whereIn('task_id', $taskIds)->delete();

                // Task pivots
                DB::table('label_task')->whereIn('task_id', $taskIds)->delete();
                DB::table('subscribe_task')->whereIn('task_id', $taskIds)->delete();

                // Task activities & Tasks
                Activity::where('activity_capable_type', Task::class)->whereIn('activity_capable_id', $taskIds)->delete();
                Task::withArchived()->where('project_id', $project->id)->delete();

                // Task groups & group activities
                $groupIds = TaskGroup::withArchived()->where('project_id', $project->id)->pluck('id');
                Activity::where('activity_capable_type', TaskGroup::class)->whereIn('activity_capable_id', $groupIds)->delete();
                TaskGroup::withArchived()->where('project_id', $project->id)->delete();

                // Project user access
                DB::table('project_user_access')->where('project_id', $project->id)->delete();

                // Project notes
                Note::where('project_id', $project->id)->delete();

                // Project activities
                Activity::where('project_id', $project->id)->delete();
                Activity::where('activity_capable_type', Project::class)->where('activity_capable_id', $project->id)->delete();

                // Favorites
                DB::table('favorites')->where('favoriteable_type', Project::class)->where('favoriteable_id', $project->id)->delete();

                $projectName = $project->name;
                $project->delete();

                Log::info("Project #{$projectId} ('{$projectName}') permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Project deleted', 'The project was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete project #{$projectId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This project cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete project #{$projectId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the project: ' . $e->getMessage());
        }
    }

    public function favoriteToggle(Project $project)
    {
        $this->authorize('view', $project);

        auth()->user()->toggleFavorite($project);

        return redirect()->back();
    }

    public function userAccess(Request $request, Project $project)
    {
        $this->authorize('editUserAccess', $project);

        $userIds = array_merge(
            $request->get('users', []),
            $request->get('clients', [])
        );

        (new ProjectService($project))->updateUserAccess($userIds);

        return redirect()->back();
    }
}
