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
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

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
        'name' => 'Activity Test Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);
    $this->project->users()->attach($this->developer->id);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Sprint Group',
        'color' => '#112233',
    ]);

    $this->task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'assigned_to_user_id' => $this->developer->id,
        'name' => 'Dev Active Task',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);
});

it('renders main dashboard with project stats and assigned tasks', function () {
    $this->actingAs($this->developer);

    $response = $this->get('/dashboard');

    $response->assertStatus(200);
});

it('renders my work tasks page', function () {
    $this->actingAs($this->developer);

    $response = $this->get('/my-work/tasks');

    $response->assertStatus(200);
});

it('renders activity feed across accessible projects', function () {
    $this->actingAs($this->developer);

    $response = $this->get('/my-work/activity');

    $response->assertStatus(200);
});

it('manages notifications read and read all status', function () {
    $this->actingAs($this->developer);

    $notification = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TaskAssignedNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->developer->id,
        'data' => ['message' => 'Task assigned to you', 'task_id' => $this->task->id],
        'read_at' => null,
    ]);

    $notification2 = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TaskCommentNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->developer->id,
        'data' => ['message' => 'Comment on your task'],
        'read_at' => null,
    ]);

    // View index
    $indexRes = $this->get('/notifications');
    $indexRes->assertStatus(200);

    // Mark single as read
    $readRes = $this->putJson("/notifications/{$notification->id}/read");
    $readRes->assertOk();
    expect($notification->fresh()->read_at)->not->toBeNull();

    // Mark all as read
    $readAllRes = $this->putJson('/notifications/read/all');
    $readAllRes->assertOk();
    expect($notification2->fresh()->read_at)->not->toBeNull();
});

it('forbids user from marking another user notification as read', function () {
    $this->actingAs($this->admin);

    $otherNotification = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TaskAssignedNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $this->developer->id,
        'data' => ['message' => 'Secret message'],
        'read_at' => null,
    ]);

    $response = $this->putJson("/notifications/{$otherNotification->id}/read");
    $response->assertStatus(403);
});
