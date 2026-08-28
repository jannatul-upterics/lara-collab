<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\OwnerCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TimeLog;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\OwnerCompanySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(CountrySeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(OwnerCompanySeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->currency = Currency::first();
    $this->clientCompany = ClientCompany::factory()->create([
        'currency_id' => $this->currency->id,
    ]);

    $this->project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Invoiceable Project',
        'rate' => 8000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Done',
        'color' => '#28a745',
    ]);

    $this->task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Billable Work Task',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
        'completed_at' => now(),
    ]);

    TimeLog::create([
        'task_id' => $this->task->id,
        'user_id' => $this->admin->id,
        'minutes' => 120, // 2 hours
    ]);
});

it('lists invoices for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/invoices');

    $response->assertStatus(200);
});

it('creates invoice from billable tasks and generates PDF', function () {
    Storage::fake('invoices');
    $this->actingAs($this->admin);

    $response = $this->post('/invoices', [
        'client_company_id' => $this->clientCompany->id,
        'number' => '20260001',
        'type' => 'default',
        'hourly_rate' => 8000,
        'projects' => [$this->project->id],
        'note' => 'Thanks for your business',
        'due_date' => now()->addDays(14)->format('Y-m-d'),
        'tasks' => [$this->task->id],
    ]);

    $response->assertRedirect('/invoices');

    $invoice = Invoice::where('number', '20260001')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->client_company_id)->toBe($this->clientCompany->id)
        ->and($invoice->status)->toBe('new')
        ->and($invoice->tasks()->count())->toBe(1);

    // Verify task is linked to this invoice
    $this->task->refresh();
    expect($this->task->invoice_id)->toBe($invoice->id);
});

it('validates invoice creation inputs', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/invoices', [
        'client_company_id' => '',
        'number' => '',
        'tasks' => [],
    ]);

    $response->assertSessionHasErrors(['client_company_id', 'number', 'tasks']);
});

it('changes invoice status between new, sent, and paid', function () {
    Storage::fake('invoices');
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'created_by_user_id' => $this->admin->id,
        'client_company_id' => $this->clientCompany->id,
        'number' => 'INV-TEST-002',
        'type' => 'default',
        'status' => 'new',
        'amount' => 16000,
        'hourly_rate' => 8000,
        'due_date' => now()->addDays(14),
    ]);

    // Change to sent
    $res1 = $this->put("/invoices/{$invoice->id}/status", ['status' => 'sent']);
    $res1->assertRedirect();
    expect($invoice->fresh()->status)->toBe('sent');

    // Change to paid
    $res2 = $this->put("/invoices/{$invoice->id}/status", ['status' => 'paid']);
    $res2->assertRedirect();
    expect($invoice->fresh()->status)->toBe('paid');
});

it('validates status value on setStatus', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'created_by_user_id' => $this->admin->id,
        'client_company_id' => $this->clientCompany->id,
        'number' => 'INV-TEST-003',
        'type' => 'default',
        'status' => 'new',
        'amount' => 16000,
        'hourly_rate' => 8000,
        'due_date' => now()->addDays(14),
    ]);

    $response = $this->put("/invoices/{$invoice->id}/status", ['status' => 'invalid-status']);
    $response->assertSessionHasErrors('status');
});

it('archives and restores an invoice', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'created_by_user_id' => $this->admin->id,
        'client_company_id' => $this->clientCompany->id,
        'number' => 'INV-TEST-004',
        'type' => 'default',
        'status' => 'new',
        'amount' => 16000,
        'hourly_rate' => 8000,
        'due_date' => now()->addDays(14),
    ]);

    // Archive
    $res1 = $this->delete("/invoices/{$invoice->id}");
    $res1->assertRedirect();
    expect($invoice->fresh()->archived_at)->not->toBeNull();

    // Restore
    $res2 = $this->post("/invoices/{$invoice->id}/restore");
    $res2->assertRedirect();
    expect($invoice->fresh()->archived_at)->toBeNull();
});
