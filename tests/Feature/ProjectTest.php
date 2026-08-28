<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Project;
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

    $this->manager = User::factory()->create();
    $this->manager->assignRole('manager');

    $this->developer = User::factory()->create();
    $this->developer->assignRole('developer');

    $this->clientCompany = ClientCompany::factory()->create([
        'currency_id' => Currency::first()->id,
    ]);
});

it('allows admin to view project list', function () {
    $this->actingAs($this->admin);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Alpha Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $response = $this->get('/projects');

    $response->assertStatus(200);
});

it('denies unauthenticated users from viewing projects', function () {
    $response = $this->get('/projects');

    $response->assertRedirect('/login');
});

it('creates project with default task groups and users', function () {
    $this->actingAs($this->admin);

    $devUser = User::factory()->create();

    $response = $this->post('/projects', [
        'client_company_id' => $this->clientCompany->id,
        'name' => 'New Web Portal',
        'description' => 'Project description text',
        'rate' => 75,
        'default_pricing_type' => 'hourly',
        'users' => [$devUser->id],
    ]);

    $response->assertRedirect('/projects');
    $response->assertSessionHas('flash');

    $project = Project::where('name', 'New Web Portal')->first();
    expect($project)->not->toBeNull()
        ->and($project->rate)->toBe(7500)
        ->and($project->taskGroups()->count())->toBe(6)
        ->and($project->users()->where('users.id', $devUser->id)->exists())->toBeTrue();
});

it('validates required fields when creating project', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/projects', [
        'name' => '',
        'client_company_id' => '',
        'rate' => '',
        'default_pricing_type' => '',
    ]);

    $response->assertSessionHasErrors(['name', 'client_company_id', 'default_pricing_type']);
});

it('validates invalid client company ID on creation', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/projects', [
        'name' => 'Bad Company Project',
        'client_company_id' => 999999,
        'rate' => 50,
        'default_pricing_type' => 'hourly',
        'users' => [],
    ]);

    $response->assertSessionHasErrors('client_company_id');
});

it('updates project successfully', function () {
    $this->actingAs($this->admin);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Original Name',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $newDev = User::factory()->create();

    $response = $this->put("/projects/{$project->id}", [
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Updated Name',
        'description' => 'Updated desc',
        'rate' => 100,
        'default_pricing_type' => 'hourly',
        'users' => [$newDev->id],
    ]);

    $response->assertRedirect('/projects');
    $project->refresh();

    expect($project->name)->toBe('Updated Name')
        ->and($project->rate)->toBe(10000)
        ->and($project->users()->where('users.id', $newDev->id)->exists())->toBeTrue();
});

it('archives and restores a project', function () {
    $this->actingAs($this->admin);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Project To Archive',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    // Archive
    $response = $this->delete("/projects/{$project->id}");
    $response->assertRedirect();
    $project->refresh();
    expect($project->archived_at)->not->toBeNull();

    // Restore
    $response = $this->post("/projects/{$project->id}/restore");
    $response->assertRedirect();
    $project->refresh();
    expect($project->archived_at)->toBeNull();
});

it('toggles favorite status for project', function () {
    $this->actingAs($this->admin);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Favorited Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    // Toggle on
    $response = $this->put("/projects/{$project->id}/favorite/toggle");
    $response->assertRedirect();
    expect($this->admin->hasFavorited($project))->toBeTrue();

    // Toggle off
    $response = $this->put("/projects/{$project->id}/favorite/toggle");
    $response->assertRedirect();
    expect($this->admin->hasFavorited($project))->toBeFalse();
});

it('updates user access for project', function () {
    $this->actingAs($this->admin);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Access Test Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $dev1 = User::factory()->create();
    $dev2 = User::factory()->create();

    $response = $this->post("/projects/{$project->id}/user-access", [
        'users' => [$dev1->id, $dev2->id],
        'clients' => [],
    ]);

    $response->assertRedirect();
    expect($project->users()->pluck('users.id')->toArray())->toEqualCanonicalizing([$dev1->id, $dev2->id]);
});

it('forbids unauthorized users from modifying project access', function () {
    $this->actingAs($this->developer);

    $project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Restricted Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    // Test JSON API request returns 403
    $jsonResponse = $this->postJson("/projects/{$project->id}/user-access", [
        'users' => [$this->developer->id],
        'clients' => [],
    ]);
    $jsonResponse->assertStatus(403);

    // Test Web request redirects with error flash message
    $webResponse = $this->post("/projects/{$project->id}/user-access", [
        'users' => [$this->developer->id],
        'clients' => [],
    ]);
    $webResponse->assertRedirect();
    $webResponse->assertSessionHas('flash.title', 'Unauthorized');
});
