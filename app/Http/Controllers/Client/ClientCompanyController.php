<?php

namespace App\Http\Controllers\Client;

use App\Actions\ClientCompany\CreateClientCompany;
use App\Actions\ClientCompany\UpdateClientCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientCompany\StoreClientCompanyRequest;
use App\Http\Requests\ClientCompany\UpdateClientCompanyRequest;
use App\Http\Resources\ClientCompany\ClientCompanyResource;
use App\Models\ClientCompany;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ClientCompanyController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ClientCompany::class, 'company');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Clients/Companies/Index', [
            'items' => ClientCompanyResource::collection(
                ClientCompany::searchByQueryString()
                    ->sortByQueryString()
                    ->with(['clients', 'currency'])
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->paginate(12)
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('Clients/Companies/Create', [
            'dropdowns' => [
                'clients' => User::clientDropdownValues(),
                'countries' => Country::dropdownValues(),
                'currencies' => Currency::dropdownValues(),
            ],
        ]);
    }

    public function store(StoreClientCompanyRequest $request)
    {
        (new CreateClientCompany)->create($request->validated());

        return redirect()->route('clients.companies.index')->success('Company created', 'A new company was successfully created.');
    }

    public function edit(ClientCompany $company)
    {
        return Inertia::render('Clients/Companies/Edit', [
            'item' => new ClientCompanyResource($company),
            'dropdowns' => [
                'clients' => User::clientDropdownValues(),
                'countries' => Country::dropdownValues(),
                'currencies' => Currency::dropdownValues(),
            ],
        ]);
    }

    public function update(ClientCompany $company, UpdateClientCompanyRequest $request)
    {
        (new UpdateClientCompany)->update($company, $request->validated());

        return redirect()->route('clients.companies.index')->success('Company updated', 'The company was successfully updated.');
    }

    public function destroy(ClientCompany $company)
    {
        $company->archive();

        return redirect()->back()->success('Company archived', 'The company was successfully archived.');
    }

    public function restore(int $companyId)
    {
        $clientCompany = ClientCompany::withArchived()->findOrFail($companyId);

        $this->authorize('restore', $clientCompany);

        $clientCompany->unArchive();

        return redirect()->back()->success('Company restored', 'The restoring of the company was completed successfully.');
    }

    public function permanentDelete(int $companyId)
    {
        try {
            return DB::transaction(function () use ($companyId) {
                $company = ClientCompany::withArchived()->lockForUpdate()->findOrFail($companyId);

                $this->authorize('forceDelete', $company);

                if (! $company->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The company is not archived. Only archived companies can be permanently deleted.');
                }

                if ($company->projects()->withArchived()->exists()) {
                    return redirect()->back()->warning('Action stopped', 'Cannot permanently delete this company because it has existing projects (active or archived). Please permanently delete those projects first.');
                }

                if ($company->invoices()->withArchived()->exists()) {
                    return redirect()->back()->warning('Action stopped', 'Cannot permanently delete this company because it has issued invoices. Financial and billing records must be preserved.');
                }

                // Detach client users from client_company pivot table
                $company->clients()->detach();

                $companyName = $company->name;
                $company->delete();

                Log::info("Client Company #{$companyId} ('{$companyName}') permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Company deleted', 'The company was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete client company #{$companyId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This company cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete client company #{$companyId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the company: ' . $e->getMessage());
        }
    }
}
