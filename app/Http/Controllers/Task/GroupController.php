<?php

namespace App\Http\Controllers\Task;

use App\Events\TaskGroup\TaskGroupCreated;
use App\Events\TaskGroup\TaskGroupDeleted;
use App\Events\TaskGroup\TaskGroupOrderChanged;
use App\Events\TaskGroup\TaskGroupRestored;
use App\Events\TaskGroup\TaskGroupUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskGroup\StoreTaskGroupRequest;
use App\Http\Requests\TaskGroup\UpdateTaskGroupRequest;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TaskGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupController extends Controller
{
    public function store(StoreTaskGroupRequest $request, Project $project)
    {
        $this->authorize('create', [TaskGroup::class, $project]);

        $taskGroup = $project->taskGroups()->create($request->validated());

        TaskGroupCreated::dispatch($taskGroup);

        return redirect()->route('projects.tasks', $project)->success('Tasks group created', 'A new tasks group was successfully created.');
    }

    public function update(UpdateTaskGroupRequest $request, Project $project, TaskGroup $taskGroup)
    {
        $this->authorize('update', [$taskGroup, $project]);

        $taskGroup->update($request->validated());

        TaskGroupUpdated::dispatch($taskGroup);

        return redirect()->route('projects.tasks', $project)->success('Tasks group updated', 'The tasks group was successfully updated.');
    }

    public function destroy(Project $project, TaskGroup $taskGroup)
    {
        $this->authorize('delete', [$taskGroup, $project]);

        if ($taskGroup->tasks->isNotEmpty()) {
            return redirect()->route('projects.tasks', $project)->warning('Action stopped', 'You cannot archive a task group that still contains tasks.');
        }

        $taskGroup->archive();

        TaskGroupDeleted::dispatch($taskGroup->id, $project->id);

        return redirect()->route('projects.tasks', $project)->success('Tasks group archived', 'The tasks group was successfully archived.');
    }

    public function restore(Project $project, int $taskGroupId)
    {
        $taskGroup = TaskGroup::withArchived()->findOrFail($taskGroupId);

        $this->authorize('restore', [$taskGroup, $project]);

        $taskGroup->unArchive();

        TaskGroupRestored::dispatch($taskGroup);

        return redirect()->back()->success('Tasks group restored', 'The restoring of the tasks group was completed successfully.');
    }

    public function permanentDelete(Project $project, int $taskGroupId)
    {
        try {
            return DB::transaction(function () use ($project, $taskGroupId) {
                $taskGroup = TaskGroup::withArchived()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->findOrFail($taskGroupId);

                $this->authorize('forceDelete', [$taskGroup, $project]);

                if (! $taskGroup->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The task group is not archived. Only archived task groups can be permanently deleted.');
                }

                if ($taskGroup->tasks()->withArchived()->exists()) {
                    return redirect()->back()->warning('Action stopped', 'You cannot permanently delete a task group that still contains tasks (active or archived). Please reassign or permanently delete the tasks first.');
                }

                // Delete activities for this group
                Activity::where('activity_capable_type', TaskGroup::class)
                    ->where('activity_capable_id', $taskGroup->id)
                    ->delete();

                $groupName = $taskGroup->name;
                $groupId = $taskGroup->id;
                $taskGroup->delete();

                TaskGroupDeleted::dispatch($groupId, $project->id);

                Log::info("Task Group #{$groupId} ('{$groupName}') in Project #{$project->id} permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Tasks group deleted', 'The tasks group was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete task group #{$taskGroupId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This task group cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete task group #{$taskGroupId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the task group: ' . $e->getMessage());
        }
    }

    public function reorder(Request $request, Project $project)
    {
        $this->authorize('reorder', [TaskGroup::class, $project]);

        TaskGroup::setNewOrder($request->ids);

        TaskGroupOrderChanged::dispatch($project->id, $request->ids);

        return response()->json();
    }
}
