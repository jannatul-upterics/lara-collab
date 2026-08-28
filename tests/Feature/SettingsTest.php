<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\Label;
use App\Models\OwnerCompany;
use App\Models\Role;
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

    $this->country = Country::first();
    $this->currency = Currency::first();
});

it('renders owner company settings for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/settings/company');

    $response->assertStatus(200);
});

it('updates owner company details', function () {
    $this->actingAs($this->admin);

    $response = $this->put('/settings/company', [
        'name' => 'LaraCollab HQ',
        'address' => '456 Tech Avenue',
        'postal_code' => '90210',
        'city' => 'Silicon City',
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'email' => 'contact@hq.com',
        'tax' => 20, // 20% -> 2000
        'phone' => '123-456-7890',
        'web' => 'https://laracollab.test',
        'iban' => 'US1234567890',
        'swift' => 'SWIFTUS',
        'business_id' => 'B123',
        'tax_id' => 'T123',
        'vat' => 'V123',
    ]);

    $response->assertRedirect();
    $company = OwnerCompany::first();
    expect($company->name)->toBe('LaraCollab HQ')
        ->and($company->city)->toBe('Silicon City')
        ->and($company->tax)->toBe(2000);
});

it('creates a custom role with permissions', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/settings/roles', [
        'name' => 'Product Owner',
        'permissions' => ['view projects', 'view project', 'view tasks'],
    ]);

    $response->assertRedirect('/settings/roles');

    $role = Role::where('name', 'Product Owner')->first();
    expect($role)->not->toBeNull()
        ->and($role->hasPermissionTo('view projects'))->toBeTrue()
        ->and($role->hasPermissionTo('view tasks'))->toBeTrue();
});

it('prevents archiving a role assigned to active users', function () {
    $this->actingAs($this->admin);

    $devRole = Role::where('name', 'developer')->first();

    $response = $this->from('/settings/roles')->delete("/settings/roles/{$devRole->id}");
    $response->assertRedirect('/settings/roles');
    $response->assertSessionHas('flash.title', 'Action stopped');

    $devRole->refresh();
    expect($devRole->archived_at)->toBeNull();
});

it('archives and restores an unassigned custom role', function () {
    $this->actingAs($this->admin);

    $role = Role::create(['name' => 'Intern', 'guard_name' => 'web']);

    // Archive
    $res1 = $this->delete("/settings/roles/{$role->id}");
    $res1->assertRedirect();
    expect($role->fresh()->archived_at)->not->toBeNull();

    // Restore
    $res2 = $this->post("/settings/roles/{$role->id}/restore");
    $res2->assertRedirect();
    expect($role->fresh()->archived_at)->toBeNull();
});

it('creates, updates, archives, and restores a label', function () {
    $this->actingAs($this->admin);

    // Create
    $response = $this->post('/settings/labels', [
        'name' => 'Backend API',
        'color' => '#FF5733',
    ]);
    $response->assertRedirect('/settings/labels');

    $label = Label::where('name', 'Backend API')->first();
    expect($label)->not->toBeNull()
        ->and($label->color)->toBe('#FF5733');

    // Update
    $updateRes = $this->put("/settings/labels/{$label->id}", [
        'name' => 'Backend Core API',
        'color' => '#33FF57',
    ]);
    $updateRes->assertRedirect('/settings/labels');
    $label->refresh();
    expect($label->name)->toBe('Backend Core API');

    // Archive
    $archiveRes = $this->delete("/settings/labels/{$label->id}");
    $archiveRes->assertRedirect();
    expect($label->fresh()->archived_at)->not->toBeNull();

    // Restore
    $restoreRes = $this->post("/settings/labels/{$label->id}/restore");
    $restoreRes->assertRedirect();
    expect($label->fresh()->archived_at)->toBeNull();
});

it('returns dropdown values endpoint data', function () {
    $this->actingAs($this->admin);

    $response = $this->getJson('/dropdown/values?currencies=true&countries=true');
    $response->assertOk();
});
