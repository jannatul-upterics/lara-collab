<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\CreateClient;
use App\Actions\Client\UpdateClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Resources\Client\ClientResource;
use App\Models\ClientCompany;
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

class ClientUserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_if(! $request->user()->can('view client users'), 401);

        return Inertia::render('Clients/Users/Index', [
            'items' => ClientResource::collection(
                User::searchByQueryString()
                    ->sortByQueryString()
                    ->role('client')
                    ->with('clientCompanies')
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->paginate(12)
            ),
        ]);
    }

    public function create()
    {
        abort_if(! request()->user()->can('create client user'), 401);

        return Inertia::render('Clients/Users/Create', [
            'dropdowns' => [
                'companies' => ClientCompany::dropdownValues(),
            ],
        ]);
    }

    public function store(StoreClientRequest $request)
    {
        abort_if(! request()->user()->can('create client user'), 401);

        $client = (new CreateClient)->create($request->validated());

        if (empty($request->companies)) {
            return redirect()
                ->route('clients.companies.create', ['client_id' => $client->id])
                ->success('Client created', 'A new client was successfully created. Now you can create a company for the client.');
        }

        return redirect()->route('clients.users.index')->success('Client created', 'A new client was successfully created.');
    }

    public function edit(User $user)
    {
        abort_if(! request()->user()->can('edit client user'), 401);

        return Inertia::render('Clients/Users/Edit', [
            'item' => new ClientResource($user),
            'dropdowns' => [
                'companies' => ClientCompany::dropdownValues(),
            ],
        ]);
    }

    public function update(User $user, UpdateClientRequest $request)
    {
        abort_if(! request()->user()->can('edit client user'), 401);

        (new UpdateClient)->update($user, $request->validated());

        return redirect()->route('clients.users.index')->success('Client updated', 'The client was successfully updated.');
    }

    public function destroy(User $user)
    {
        abort_if(! request()->user()->can('archive client user'), 401);

        if (auth()->id() === $user->id) {
            return redirect()->route('clients.users.index')->warning('Action stopped', 'You cannot archive the client with whom you are currently logged in.');
        }
        $user->archive();

        return redirect()->back()->success('Client archived', 'The client was successfully archived.');
    }

    public function restore(int $userId)
    {
        abort_if(! request()->user()->can('restore client user'), 401);

        $user = User::withArchived()->findOrFail($userId);

        $user->unArchive();

        return redirect()->back()->success('Client restored', 'The restoring of the client was completed successfully.');
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

                Log::info("Client User #{$userId} ('{$userName}') permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Client deleted', 'The client was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete client user #{$userId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This client user cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete client user #{$userId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the client: ' . $e->getMessage());
        }
    }
}
