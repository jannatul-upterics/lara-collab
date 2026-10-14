<?php

namespace App\Http\Controllers;

use App\Actions\User\CreateUser;
use App\Actions\User\UpdateUser;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\UserResource;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Users/Index', [
            'items' => UserResource::collection(
                User::searchByQueryString()
                    ->sortByQueryString()
                    ->withoutRole('client')
                    ->with('roles:id,name')
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->paginate(12)
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Create');
    }

    public function store(StoreUserRequest $request)
    {
        (new CreateUser)->create($request->validated());

        return redirect()->route('users.index')->success('User created', 'A new user was successfully created.');
    }

    public function edit(User $user)
    {
        return Inertia::render('Users/Edit', ['item' => new UserResource($user)]);
    }

    public function update(User $user, UpdateUserRequest $request)
    {
        (new UpdateUser)->update($user, $request->validated());

        return redirect()->route('users.index')->success('User updated', 'The user was successfully updated.');
    }

    public function destroy(User $user)
    {
        if (auth()->id() === $user->id) {
            return redirect()->route('users.index')->warning('Action stopped', 'You cannot archive the user with whom you are currently logged in.');
        }
        $user->archive();

        return redirect()->back()->success('User archived', 'The user was successfully archived.');
    }

    public function restore(int $userId)
    {
        $user = User::withArchived()->findOrFail($userId);

        $this->authorize('restore', $user);

        $user->unArchive();

        return redirect()->back()->success('User restored', 'The restoring of the user was completed successfully.');
    }

    public function permanentDelete(int $userId)
    {
        try {
            return DB::transaction(function () use ($userId) {
                $user = User::withArchived()->lockForUpdate()->findOrFail($userId);

                $this->authorize('forceDelete', $user);

                if (! $user->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The user is not archived. Only archived users can be permanently deleted.');
                }

                if (auth()->id() === $user->id) {
                    return redirect()->back()->warning('Action stopped', 'You cannot delete the user with whom you are currently logged in.');
                }

                if ($user->hasRole('admin')) {
                    $activeAdminsCount = User::role('admin')->where('id', '!=', $user->id)->withoutArchived()->count();
                    if ($activeAdminsCount === 0) {
                        return redirect()->back()->warning('Action stopped', 'Cannot delete the sole remaining administrator account.');
                    }
                }

                if (Invoice::where('created_by_user_id', $user->id)->exists()) {
                    return redirect()->back()->warning('Action stopped', 'Cannot permanently delete this user because they are recorded as the creator of invoices. Invoices are legal records and their creator reference cannot be removed.');
                }

                if (TimeLog::where('user_id', $user->id)->exists()) {
                    return redirect()->back()->warning('Action stopped', 'Cannot permanently delete this user because they have recorded time logs on tasks.');
                }

                // Nullify task assignments & creations
                Task::where('assigned_to_user_id', $user->id)->update(['assigned_to_user_id' => null]);
                Task::where('created_by_user_id', $user->id)->update(['created_by_user_id' => null]);

                // Detach pivots
                $user->clientCompanies()->detach();
                $user->projects()->detach();
                $user->subscribedToTasks()->detach();
                $user->roles()->detach();
                $user->permissions()->detach();

                // Delete tokens, notifications, favorites
                $user->tokens()->delete();
                $user->notifications()->delete();
                DB::table('favorites')->where('user_id', $user->id)->delete();

                // Delete avatar from storage if exists
                if (! empty($user->avatar)) {
                    $files = glob(storage_path("app/public/avatars/{$user->id}.*"));
                    if ($files) {
                        File::delete($files);
                    }
                }

                $userName = $user->name;
                $user->delete();

                Log::info("User #{$userId} ('{$userName}') permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('User deleted', 'The user was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete user #{$userId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This user cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete user #{$userId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the user: ' . $e->getMessage());
        }
    }
}
