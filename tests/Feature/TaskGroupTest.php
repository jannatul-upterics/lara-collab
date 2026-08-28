<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(CountrySeeder::class);
    $this->seed(CurrencySeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->developer = User::factory()->create();
    $this->developer->assignRole('developer');

    $this->clientCompany = ClientCompany::factory()->create([
        'currency_id' => Currency::first()->id,
    ]);

    $this->project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Project with Groups',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);
});

it('creates a task group for project', function () {
    $this->actingAs($this->admin);

    $response = $this->post("/projects/{$this->project->id}/task-groups", [
        'name' => 'Sprint 1',
        'color' => '#123456',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('flash');

    $this->assertDatabaseHas('task_groups', [
        'project_id' => $this->project->id,
        'name' => 'Sprint 1',
        'color' => '#123456',
    ]);
});

it('updates a task group', function () {
    $this->actingAs($this->admin);

    $group = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Initial Group',
        'color' => '#000000',
    ]);

    $response = $this->put("/projects/{$this->project->id}/task-groups/{$group->id}", [
        'name' => 'Updated Group Name',
        'color' => '#FFFFFF',
    ]);

    $response->assertRedirect();
    $group->refresh();
    expect($group->name)->toBe('Updated Group Name')
        ->and($group->color)->toBe('#FFFFFF');
});

it('archives an empty task group', function () {
    $this->actingAs($this->admin);

    $group = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Empty Group',
        'color' => '#000000',
    ]);

    $response = $this->delete("/projects/{$this->project->id}/task-groups/{$group->id}");

    $response->assertRedirect();
    $group->refresh();
    expect($group->archived_at)->not->toBeNull();
});

it('prevents archiving a task group that contains tasks', function () {
    $this->actingAs($this->admin);

    $group = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Active Group',
        'color' => '#000000',
    ]);

    Task::create([
        'project_id' => $this->project->id,
        'group_id' => $group->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Some task inside group',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $response = $this->delete("/projects/{$this->project->id}/task-groups/{$group->id}");

    $response->assertRedirect();
    $response->assertSessionHas('flash.title', 'Action stopped');

    $group->refresh();
    expect($group->archived_at)->toBeNull();
});

it('restores an archived task group', function () {
    $this->actingAs($this->admin);

    $group = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Archived Group',
        'color' => '#000000',
        'archived_at' => now(),
    ]);

    $response = $this->post("/projects/{$this->project->id}/task-groups/{$group->id}/restore");

    $response->assertRedirect();
    $group->refresh();
    expect($group->archived_at)->toBeNull();
});

it('reorders task groups', function () {
    $this->actingAs($this->admin);

    $group1 = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'G1', 'order_column' => 1]);
    $group2 = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'G2', 'order_column' => 2]);

    $response = $this->postJson("/projects/{$this->project->id}/task-groups/reorder", [
        'ids' => [$group2->id, $group1->id],
    ]);

    $response->assertOk();
});

it('forbids developers without permission from creating task groups', function () {
    $this->actingAs($this->developer);

    $response = $this->postJson("/projects/{$this->project->id}/task-groups", [
        'name' => 'Unauthorized Group',
    ]);

    $response->assertStatus(403);
});
