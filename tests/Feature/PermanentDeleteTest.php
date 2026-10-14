<?php

use App\Models\ClientCompany;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Label;
use App\Models\OwnerCompany;
use App\Models\Project;
use App\Models\Role;
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

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(CountrySeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(OwnerCompanySeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->developer = User::factory()->create();
    $this->developer->assignRole('developer');

    $this->company = ClientCompany::create([
        'name' => 'Acme Corp',
        'currency_id' => Currency::first()->id,
        'country_id' => Country::first()->id,
    ]);

    $this->project = Project::create([
        'name' => 'Project Titan',
        'client_company_id' => $this->company->id,
        'rate' => 5000,
    ]);
});

it('prevents non-admins from permanently deleting a project', function () {
    $this->project->archive();
    $this->actingAs($this->developer);

    // JSON API request returns 403
    $jsonResponse = $this->deleteJson("/projects/{$this->project->id}/permanent-delete");
    $jsonResponse->assertStatus(403);

    // Web request redirects back with unauthorized flash message
    $webResponse = $this->delete("/projects/{$this->project->id}/permanent-delete");
    $webResponse->assertRedirect();
    $webResponse->assertSessionHas('flash.title', 'Unauthorized');

    expect(Project::withArchived()->find($this->project->id))->not->toBeNull();
});

it('prevents permanent deletion of unarchived project', function () {
    $this->actingAs($this->admin);

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Project::find($this->project->id))->not->toBeNull();
});

it('allows admin to permanently delete an archived project', function () {
    $this->actingAs($this->admin);

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Test Task',
        'number' => 1,
        'order_column' => 1,
    ]);

    $this->project->archive();

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(Project::withArchived()->find($this->project->id))->toBeNull();
    expect(Task::withArchived()->find($task->id))->toBeNull();
    expect(TaskGroup::withArchived()->find($group->id))->toBeNull();
});

it('blocks project permanent deletion if it has invoiced tasks', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-001',
        'status' => 'new',
        'type' => 'default',
        'amount' => 10000,
    ]);

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Billed Task',
        'number' => 1,
        'order_column' => 1,
        'invoice_id' => $invoice->id,
    ]);

    $this->project->archive();

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Project::withArchived()->find($this->project->id))->not->toBeNull();
});

it('allows admin to permanently delete an archived task', function () {
    $this->actingAs($this->admin);

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Task to Delete',
        'number' => 1,
        'order_column' => 1,
    ]);

    $task->archive();

    $response = $this->delete("/projects/{$this->project->id}/tasks/{$task->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(Task::withArchived()->find($task->id))->toBeNull();
});

it('blocks permanent deletion of invoiced task', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-002',
        'status' => 'new',
        'type' => 'default',
        'amount' => 10000,
    ]);

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Invoiced Task',
        'number' => 2,
        'order_column' => 1,
        'invoice_id' => $invoice->id,
    ]);

    $task->archive();

    $response = $this->delete("/projects/{$this->project->id}/tasks/{$task->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Task::withArchived()->find($task->id))->not->toBeNull();
});

it('blocks task group permanent deletion if it still contains tasks', function () {
    $this->actingAs($this->admin);

    $group = $this->project->taskGroups()->create(['name' => 'QA', 'order_column' => 1]);
    Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Remaining Task',
        'number' => 1,
        'order_column' => 1,
    ]);

    $group->archive();

    $response = $this->delete("/projects/{$this->project->id}/task-groups/{$group->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(TaskGroup::withArchived()->find($group->id))->not->toBeNull();
});

it('allows permanent deletion of an empty archived task group', function () {
    $this->actingAs($this->admin);

    $group = $this->project->taskGroups()->create(['name' => 'Empty Group', 'order_column' => 1]);
    $group->archive();

    $response = $this->delete("/projects/{$this->project->id}/task-groups/{$group->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(TaskGroup::withArchived()->find($group->id))->toBeNull();
});

it('blocks client company permanent deletion if it has projects', function () {
    $this->actingAs($this->admin);

    $this->company->archive();

    $response = $this->delete("/clients/companies/{$this->company->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(ClientCompany::withArchived()->find($this->company->id))->not->toBeNull();
});

it('allows client company permanent deletion if empty', function () {
    $this->actingAs($this->admin);

    $emptyCompany = ClientCompany::create([
        'name' => 'Empty Corp',
        'currency_id' => Currency::first()->id,
        'country_id' => Country::first()->id,
    ]);
    $emptyCompany->archive();

    $response = $this->delete("/clients/companies/{$emptyCompany->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(ClientCompany::withArchived()->find($emptyCompany->id))->toBeNull();
});

it('blocks user from permanently deleting their own account', function () {
    $this->actingAs($this->admin);

    $this->admin->archive();

    $response = $this->delete("/users/{$this->admin->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(User::withArchived()->find($this->admin->id))->not->toBeNull();
});

it('allows admin to permanently delete an archived user', function () {
    $this->actingAs($this->admin);

    $targetUser = User::factory()->create();
    $targetUser->archive();

    $response = $this->delete("/users/{$targetUser->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(User::withArchived()->find($targetUser->id))->toBeNull();
});

it('blocks permanent deletion of paid invoice', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-003',
        'status' => 'paid',
        'type' => 'default',
        'amount' => 50000,
    ]);
    $invoice->archive();

    $response = $this->delete("/invoices/{$invoice->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Invoice::withArchived()->find($invoice->id))->not->toBeNull();
});

it('allows permanent deletion of an archived unpaid invoice', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-004',
        'status' => 'sent',
        'type' => 'default',
        'amount' => 30000,
    ]);
    $invoice->archive();

    $response = $this->delete("/invoices/{$invoice->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(Invoice::withArchived()->find($invoice->id))->toBeNull();
});

it('blocks permanent deletion of admin or client system roles', function () {
    $this->actingAs($this->admin);

    $adminRole = Role::findByName('admin');
    $adminRole->archive();

    // JSON API request returns 403
    $jsonResponse = $this->deleteJson("/settings/roles/{$adminRole->id}/permanent-delete");
    $jsonResponse->assertStatus(403);

    // Web request redirects back with unauthorized error
    $webResponse = $this->delete("/settings/roles/{$adminRole->id}/permanent-delete");
    $webResponse->assertRedirect();
    $webResponse->assertSessionHas('flash.title', 'Unauthorized');

    expect(Role::withArchived()->find($adminRole->id))->not->toBeNull();
});

it('allows permanent deletion of an archived label', function () {
    $this->actingAs($this->admin);

    $label = Label::create(['name' => 'Bug', 'color' => '#ff0000']);
    $label->archive();

    $response = $this->delete("/settings/labels/{$label->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(Label::withArchived()->find($label->id))->toBeNull();
});

it('rejects unauthenticated requests to permanent delete endpoints', function () {
    $this->project->archive();

    // Guest web request redirects to login
    $webResponse = $this->delete("/projects/{$this->project->id}/permanent-delete");
    $webResponse->assertRedirect('/login');

    // Guest JSON request returns 401 Unauthorized
    $jsonResponse = $this->deleteJson("/projects/{$this->project->id}/permanent-delete");
    $jsonResponse->assertStatus(401);

    expect(Project::withArchived()->find($this->project->id))->not->toBeNull();
});

it('returns 404 not found for nonexistent record on permanent delete endpoint', function () {
    $this->actingAs($this->admin);

    $response = $this->delete("/projects/9999999/permanent-delete");
    $response->assertStatus(404);
});

it('prevents permanent deletion of a record that was restored between listing and deletion', function () {
    $this->actingAs($this->admin);

    $this->project->archive();
    expect($this->project->fresh()->isArchived())->toBeTrue();

    // Concurrently restored
    $this->project->unArchive();
    expect($this->project->fresh()->isArchived())->toBeFalse();

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Project::find($this->project->id))->not->toBeNull();
});

it('preserves related tasks and resets invoice_id when an invoice is permanently deleted', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-005',
        'status' => 'new',
        'type' => 'default',
        'amount' => 15000,
    ]);

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Task on Draft Invoice',
        'number' => 10,
        'order_column' => 1,
        'invoice_id' => $invoice->id,
    ]);

    $invoice->archive();

    $response = $this->delete("/invoices/{$invoice->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(Invoice::withArchived()->find($invoice->id))->toBeNull();

    // Verify task is preserved and unlinked
    $task->refresh();
    expect($task)->not->toBeNull()
        ->and($task->invoice_id)->toBeNull();
});

it('preserves tasks and nullifies user references when a user is permanently deleted', function () {
    $this->actingAs($this->admin);

    $staffUser = User::factory()->create();
    $staffUser->assignRole('developer');

    $group = $this->project->taskGroups()->create(['name' => 'Todo', 'order_column' => 1]);
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Assigned Task',
        'number' => 20,
        'order_column' => 1,
        'created_by_user_id' => $staffUser->id,
        'assigned_to_user_id' => $staffUser->id,
    ]);

    $staffUser->archive();

    $response = $this->delete("/users/{$staffUser->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'success');
    expect(User::withArchived()->find($staffUser->id))->toBeNull();

    $task->refresh();
    expect($task)->not->toBeNull()
        ->and($task->created_by_user_id)->toBeNull()
        ->and($task->assigned_to_user_id)->toBeNull();
});

it('does not affect unrelated projects, companies, or tasks when deleting a project', function () {
    $this->actingAs($this->admin);

    $otherCompany = ClientCompany::create([
        'name' => 'Other Corp',
        'currency_id' => Currency::first()->id,
        'country_id' => Country::first()->id,
    ]);

    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_company_id' => $otherCompany->id,
        'rate' => 6000,
    ]);
    $otherGroup = $otherProject->taskGroups()->create(['name' => 'Other Group', 'order_column' => 1]);
    $otherTask = Task::create([
        'project_id' => $otherProject->id,
        'group_id' => $otherGroup->id,
        'name' => 'Other Task',
        'number' => 1,
        'order_column' => 1,
    ]);

    $this->project->archive();

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");
    $response->assertSessionHas('flash.type', 'success');

    // Target deleted
    expect(Project::withArchived()->find($this->project->id))->toBeNull();

    // Other entities untouched
    expect(ClientCompany::find($otherCompany->id))->not->toBeNull();
    expect(Project::find($otherProject->id))->not->toBeNull();
    expect(TaskGroup::find($otherGroup->id))->not->toBeNull();
    expect(Task::find($otherTask->id))->not->toBeNull();
});

it('ensures dependency conflicts produce safe errors without any partial deletion', function () {
    $this->actingAs($this->admin);

    $invoice = Invoice::create([
        'client_company_id' => $this->company->id,
        'created_by_user_id' => $this->admin->id,
        'number' => 'INV-CONFLICT',
        'status' => 'new',
        'type' => 'default',
        'amount' => 10000,
    ]);

    $group = $this->project->taskGroups()->create(['name' => 'Dev Group', 'order_column' => 1]);
    $unbilledTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Unbilled Task',
        'number' => 101,
        'order_column' => 1,
    ]);
    $billedTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'name' => 'Billed Task',
        'number' => 102,
        'order_column' => 2,
        'invoice_id' => $invoice->id,
    ]);

    $this->project->archive();

    $response = $this->delete("/projects/{$this->project->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');

    // Verify ZERO records were deleted (no partial deletion)
    expect(Project::withArchived()->find($this->project->id))->not->toBeNull();
    expect(TaskGroup::withArchived()->find($group->id))->not->toBeNull();
    expect(Task::withArchived()->find($unbilledTask->id))->not->toBeNull();
    expect(Task::withArchived()->find($billedTask->id))->not->toBeNull();
});

it('rolls back all database changes if an exception occurs during multi-step deletion', function () {
    $this->actingAs($this->admin);

    $label = Label::create(['name' => 'Rollback Label', 'color' => '#123456']);
    $label->archive();

    // Hook into deleting event on Label to simulate a failure midway
    Label::deleting(function ($model) {
        if ($model->name === 'Rollback Label') {
            throw new \RuntimeException('Simulated catastrophic failure midway through deletion');
        }
    });

    $response = $this->delete("/settings/labels/{$label->id}/permanent-delete");

    $response->assertSessionHas('flash.type', 'warning');
    expect(Label::withArchived()->find($label->id))->not->toBeNull();
});

it('ensures existing restore and archive features remain intact', function () {
    $this->actingAs($this->admin);

    // Initial state: active
    expect($this->project->archived_at)->toBeNull();

    // Archive via existing route
    $archiveResponse = $this->delete("/projects/{$this->project->id}");
    $archiveResponse->assertRedirect();
    expect($this->project->fresh()->archived_at)->not->toBeNull();

    // Restore via existing route
    $restoreResponse = $this->post("/projects/{$this->project->id}/restore");
    $restoreResponse->assertRedirect();
    expect($this->project->fresh()->archived_at)->toBeNull();
});
