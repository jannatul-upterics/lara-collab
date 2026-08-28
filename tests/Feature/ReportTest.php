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
        'name' => 'Report Analytics Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'Done Group',
        'color' => '#123456',
    ]);

    $this->completedTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'assigned_to_user_id' => $this->developer->id,
        'name' => 'Hourly Finished Task',
        'number' => 1,
        'pricing_type' => 'hourly',
        'hidden_from_clients' => false,
        'billable' => true,
        'completed_at' => now(),
    ]);

    TimeLog::create([
        'task_id' => $this->completedTask->id,
        'user_id' => $this->developer->id,
        'minutes' => 180, // 3 hours
    ]);

    $this->fixedTask = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->taskGroup->id,
        'created_by_user_id' => $this->admin->id,
        'assigned_to_user_id' => $this->developer->id,
        'name' => 'Fixed Price Finished Task',
        'number' => 2,
        'pricing_type' => 'fixed',
        'fixed_price' => 25000,
        'hidden_from_clients' => false,
        'billable' => true,
        'completed_at' => now(),
    ]);
});

it('renders logged time sum report for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/reports/logged-time/sum');

    $response->assertStatus(200);
});

it('renders daily logged time report for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/reports/logged-time/daily');

    $response->assertStatus(200);
});

it('renders fixed price sum report for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/reports/fixed-price/sum');

    $response->assertStatus(200);
});

it('forbids developers without report permissions from viewing reports', function () {
    $this->actingAs($this->developer);

    $res1 = $this->getJson('/reports/logged-time/sum');
    $res1->assertStatus(403);

    $res2 = $this->getJson('/reports/logged-time/daily');
    $res2->assertStatus(403);

    $res3 = $this->getJson('/reports/fixed-price/sum');
    $res3->assertStatus(403);
});
