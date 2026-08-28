<?php

use App\Models\ClientCompany;
use App\Models\Country;
use App\Models\Currency;
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

    $this->country = Country::first();
    $this->currency = Currency::first();
});

it('lists client companies for admin', function () {
    $this->actingAs($this->admin);

    ClientCompany::factory()->create([
        'currency_id' => $this->currency->id,
        'country_id' => $this->country->id,
    ]);

    $response = $this->get('/clients/companies');

    $response->assertStatus(200);
});

it('creates a client company', function () {
    $this->actingAs($this->admin);

    $clientUser = User::factory()->create();
    $clientUser->assignRole('client');

    $response = $this->post('/clients/companies', [
        'name' => 'Acme Corporation',
        'email' => 'contact@gmail.com',
        'address' => '123 Business St',
        'postal_code' => '10001',
        'city' => 'Metropolis',
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'clients' => [$clientUser->id],
    ]);

    $response->assertRedirect('/clients/companies');

    $company = ClientCompany::where('name', 'Acme Corporation')->first();
    expect($company)->not->toBeNull()
        ->and($company->email)->toBe('contact@gmail.com')
        ->and($company->clients()->where('users.id', $clientUser->id)->exists())->toBeTrue();
});

it('validates client company creation fields', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/clients/companies', [
        'name' => '',
        'email' => 'invalid-email-not-valid',
    ]);

    $response->assertSessionHasErrors(['name', 'email']);
});

it('updates a client company', function () {
    $this->actingAs($this->admin);

    $company = ClientCompany::factory()->create([
        'name' => 'Old Company Name',
        'currency_id' => $this->currency->id,
        'country_id' => $this->country->id,
    ]);

    $response = $this->put("/clients/companies/{$company->id}", [
        'name' => 'Updated Company Name',
        'currency_id' => $this->currency->id,
        'country_id' => $this->country->id,
        'clients' => [],
    ]);

    $response->assertRedirect('/clients/companies');
    $company->refresh();
    expect($company->name)->toBe('Updated Company Name');
});

it('archives and restores a client company', function () {
    $this->actingAs($this->admin);

    $company = ClientCompany::factory()->create([
        'currency_id' => $this->currency->id,
        'country_id' => $this->country->id,
    ]);

    // Archive
    $res1 = $this->delete("/clients/companies/{$company->id}");
    $res1->assertRedirect();
    expect($company->fresh()->archived_at)->not->toBeNull();

    // Restore
    $res2 = $this->post("/clients/companies/{$company->id}/restore");
    $res2->assertRedirect();
    expect($company->fresh()->archived_at)->toBeNull();
});

it('creates a client user', function () {
    $this->actingAs($this->admin);

    $company = ClientCompany::factory()->create([
        'currency_id' => $this->currency->id,
        'country_id' => $this->country->id,
    ]);

    $response = $this->post('/clients/users', [
        'name' => 'Jane Client',
        'email' => 'jane.client@gmail.com',
        'password' => 'secret-password-123',
        'password_confirmation' => 'secret-password-123',
        'phone' => '123456789',
        'companies' => [$company->id],
    ]);

    $response->assertRedirect('/clients/users');

    $client = User::where('email', 'jane.client@gmail.com')->first();
    expect($client)->not->toBeNull()
        ->and($client->hasRole('client'))->toBeTrue()
        ->and($client->clientCompanies()->where('client_companies.id', $company->id)->exists())->toBeTrue();
});

it('archives and restores a client user', function () {
    $this->actingAs($this->admin);

    $client = User::factory()->create();
    $client->assignRole('client');

    // Archive
    $res1 = $this->delete("/clients/users/{$client->id}");
    $res1->assertRedirect();
    expect($client->fresh()->archived_at)->not->toBeNull();

    // Restore
    $res2 = $this->post("/clients/users/{$client->id}/restore");
    $res2->assertRedirect();
    expect($client->fresh()->archived_at)->toBeNull();
});
