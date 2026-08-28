<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TaskPriority;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LabelSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskPrioritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(CountrySeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(LabelSeeder::class);
    $this->seed(TaskPrioritySeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->clientUser = User::factory()->create();
    $this->clientUser->assignRole('client');

    $this->clientCompany = ClientCompany::factory()->create([
        'currency_id' => Currency::first()->id,
    ]);
    $this->clientCompany->clients()->attach($this->clientUser->id);

    $this->project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Main Task Project',
        'rate' => 6000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'To Do',
        'color' => '#112233',
    ]);

    $this->taskGroup2 = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'In Progress',
        'color' => '#445566',
    ]);
});

it('lists tasks in project for admin', function () {
    $this->actingAs($this->admin);

    Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Feature 1',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $response = $this->get("/projects/{$this->project->id}/tasks");

    $response->assertStatus(200);
});

it('creates a task with full attributes and relationships', function () {
    $this->actingAs($this->admin);

    $assignee = User::factory()->create();
    $subscriber = User::factory()->create();
    $label = Label::first();
    $priority = TaskPriority::first();

    $response = $this->post("/projects/{$this->project->id}/tasks", [
        'name' => 'Comprehensive Test Task',
        'description' => 'Detailed description of task',
        'group_id' => $this->taskGroup->id,
        'assigned_to_user_id' => $assignee->id,
        'due_on' => now()->addDays(5)->format('Y-m-d'),
        'estimation' => 12.5,
        'priority_id' => $priority->id,
        'pricing_type' => 'fixed',
        'fixed_price' => 500, // 500 dollars -> 50000 cents
        'hidden_from_clients' => false,
        'billable' => true,
        'labels' => [$label->id],
        'subscribed_users' => [$subscriber->id],
        'attachments' => [],
    ]);

    $response->assertRedirect();

    $task = Task::where('name', 'Comprehensive Test Task')->first();
    expect($task)->not->toBeNull()
        ->and($task->number)->toBe(1)
        ->and((float) $task->estimation)->toBe(12.5)
        ->and($task->fixed_price)->toBe(50000)
        ->and($task->priority_id)->toBe($priority->id)
        ->and($task->labels()->where('labels.id', $label->id)->exists())->toBeTrue()
        ->and($task->subscribedUsers()->where('users.id', $subscriber->id)->exists())->toBeTrue();
});

it('validates task creation input', function () {
    $this->actingAs($this->admin);

    $response = $this->post("/projects/{$this->project->id}/tasks", [
        'name' => '',
        'group_id' => 999999, // non-existent
        'pricing_type' => 'invalid-type',
    ]);

    $response->assertSessionHasErrors(['name', 'group_id', 'pricing_type']);
});

it('updates task attributes via PUT', function () {
    $this->actingAs($this->admin);

    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Initial Task Name',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $response = $this->putJson("/projects/{$this->project->id}/tasks/{$task->id}", [
        'name' => 'Updated Task Name',
    ]);

    $response->assertOk();
    $task->refresh();
    expect($task->name)->toBe('Updated Task Name');
});

it('completes and uncompletes a task', function () {
    $this->actingAs($this->admin);

    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Task to Complete',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    // Complete
    $response = $this->postJson("/projects/{$this->project->id}/tasks/{$task->id}/complete", [
        'completed' => true,
    ]);
    $response->assertOk();
    $task->refresh();
    expect($task->completed_at)->not->toBeNull();

    // Uncomplete
    $response = $this->postJson("/projects/{$this->project->id}/tasks/{$task->id}/complete", [
        'completed' => false,
    ]);
    $response->assertOk();
    $task->refresh();
    expect($task->completed_at)->toBeNull();
});

it('moves task to another task group', function () {
    $this->actingAs($this->admin);

    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Task to Move',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $response = $this->postJson("/projects/{$this->project->id}/tasks/move", [
        'ids' => [$task->id],
        'from_group_id' => $this->taskGroup->id,
        'to_group_id' => $this->taskGroup2->id,
        'from_index' => 0,
        'to_index' => 0,
    ]);

    $response->assertOk();
    $task->refresh();
    expect($task->group_id)->toBe($this->taskGroup2->id);
});

it('reorders tasks within group', function () {
    $this->actingAs($this->admin);

    $task1 = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Task 1',
        'number' => 1,
        'order_column' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $task2 = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Task 2',
        'number' => 2,
        'order_column' => 2,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $response = $this->postJson("/projects/{$this->project->id}/tasks/reorder", [
        'ids' => [$task2->id, $task1->id],
        'group_id' => $this->taskGroup->id,
        'from_index' => 0,
        'to_index' => 1,
    ]);

    $response->assertOk();
});

it('archives and restores a task', function () {
    $this->actingAs($this->admin);

    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Task To Archive',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    // Archive
    $response = $this->delete("/projects/{$this->project->id}/tasks/{$task->id}");
    $response->assertRedirect();
    $task->refresh();
    expect($task->archived_at)->not->toBeNull();

    // Restore
    $response = $this->post("/projects/{$this->project->id}/tasks/{$task->id}/restore");
    $response->assertRedirect();
    $task->refresh();
    expect($task->archived_at)->toBeNull();
});

it('hides tasks from client when hidden_from_clients is true', function () {
    $this->actingAs($this->admin);

    $visibleTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Public Client Task',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $hiddenTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Internal Developer Only Task',
        'number' => 2,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => true,
        'billable' => true,
    ]);

    $this->actingAs($this->clientUser);

    $response = $this->get("/projects/{$this->project->id}/tasks");
    $response->assertStatus(200);

    // Verify inertia page props do not include hidden task
    $pageProps = $response->original->getData()['page']['props'];
    $tasksInGroup = $pageProps['groupedTasks'][$this->taskGroup->id];

    expect(collect($tasksInGroup)->pluck('name')->toArray())
        ->toContain('Public Client Task')
        ->not->toContain('Internal Developer Only Task');
});
