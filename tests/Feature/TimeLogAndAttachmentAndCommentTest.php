<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TimeLog;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        'name' => 'Collaboration Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'In Progress',
        'color' => '#123456',
    ]);

    $this->task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'name' => 'Core Feature Task',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);
});

it('logs manual time on task', function () {
    $this->actingAs($this->admin);

    $response = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log", [
        'minutes' => 90,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('time_logs', [
        'task_id' => $this->task->id,
        'user_id' => $this->admin->id,
        'minutes' => 90,
    ]);
});

it('starts and stops live timer on task', function () {
    $this->actingAs($this->admin);

    // Start timer
    $startResponse = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log/timer/start");
    $startResponse->assertOk();

    $timeLogId = $startResponse->json('timeLog.id');
    $timeLog = TimeLog::find($timeLogId);
    expect($timeLog)->not->toBeNull()
        ->and($timeLog->timer_start)->not->toBeNull()
        ->and($timeLog->minutes)->toBeNull();

    // Fast forward time slightly
    $this->travel(10)->minutes();

    // Stop timer
    $stopResponse = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log/{$timeLog->id}/timer/stop");
    $stopResponse->assertOk();

    $timeLog->refresh();
    expect($timeLog->timer_stop)->not->toBeNull()
        ->and((int) $timeLog->minutes)->toBe(10);
});

it('forbids stopping another user active timer', function () {
    $this->actingAs($this->admin);

    // Admin starts timer
    $startResponse = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log/timer/start");
    $timeLogId = $startResponse->json('timeLog.id');

    // Developer tries to stop admin's timer
    $this->actingAs($this->developer);
    $stopResponse = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log/{$timeLogId}/timer/stop");
    $stopResponse->assertStatus(403);
});

it('deletes time log', function () {
    $this->actingAs($this->admin);

    $timeLog = TimeLog::create([
        'task_id' => $this->task->id,
        'user_id' => $this->admin->id,
        'minutes' => 45,
    ]);

    $response = $this->deleteJson("/projects/{$this->project->id}/tasks/{$this->task->id}/time-log/{$timeLog->id}");
    $response->assertOk();

    $this->assertDatabaseMissing('time_logs', ['id' => $timeLog->id]);
});

it('uploads attachment to task', function () {
    Storage::fake('public');
    $this->actingAs($this->admin);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $response = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/upload", [
        'attachments' => [$file],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('attachments', [
        'task_id' => $this->task->id,
        'name' => 'document.pdf',
    ]);
});

it('posts comment on task and retrieves comments list', function () {
    $this->actingAs($this->admin);

    // Post comment
    $postResponse = $this->postJson("/projects/{$this->project->id}/tasks/{$this->task->id}/comment", [
        'content' => 'Review completed. Ready for QA testing.',
    ]);
    $postResponse->assertOk();

    $this->assertDatabaseHas('comments', [
        'task_id' => $this->task->id,
        'user_id' => $this->admin->id,
        'content' => 'Review completed. Ready for QA testing.',
    ]);

    // List comments
    $listResponse = $this->getJson("/projects/{$this->project->id}/tasks/{$this->task->id}/comment");
    $listResponse->assertOk();
    $comments = $listResponse->json();
    expect(count($comments))->toBe(1)
        ->and($comments[0]['content'])->toBe('Review completed. Ready for QA testing.');
});
