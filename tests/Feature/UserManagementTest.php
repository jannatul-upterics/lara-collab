<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->developer = User::factory()->create();
    $this->developer->assignRole('developer');
});

it('lists internal users for admin', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/users');

    $response->assertStatus(200);
});

it('creates a new team user with roles', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/users', [
        'name' => 'Alice Developer',
        'email' => 'alice.dev@gmail.com',
        'job_title' => 'Senior Backend Engineer',
        'rate' => 75,
        'phone' => '1234567890',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
        'roles' => ['developer'],
    ]);

    $response->assertRedirect('/users');

    $user = User::where('email', 'alice.dev@gmail.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->rate)->toBe(7500)
        ->and($user->job_title)->toBe('Senior Backend Engineer')
        ->and($user->hasRole('developer'))->toBeTrue();
});

it('validates user creation input', function () {
    $this->actingAs($this->admin);

    $response = $this->post('/users', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => '123', // less than 8 chars
        'roles' => 'not-an-array',
    ]);

    $response->assertSessionHasErrors(['name', 'email', 'password', 'roles']);
});

it('prevents user from archiving their own account', function () {
    $this->actingAs($this->admin);

    $response = $this->delete("/users/{$this->admin->id}");

    $response->assertRedirect('/users');
    $response->assertSessionHas('flash.title', 'Action stopped');

    $this->admin->refresh();
    expect($this->admin->archived_at)->toBeNull();
});

it('archives and restores another user', function () {
    $this->actingAs($this->admin);

    $targetUser = User::factory()->create();
    $targetUser->assignRole('developer');

    // Archive
    $res1 = $this->delete("/users/{$targetUser->id}");
    $res1->assertRedirect();
    expect($targetUser->fresh()->archived_at)->not->toBeNull();

    // Restore
    $res2 = $this->post("/users/{$targetUser->id}/restore");
    $res2->assertRedirect();
    expect($targetUser->fresh()->archived_at)->toBeNull();
});

it('allows user to update their own profile', function () {
    $this->actingAs($this->developer);

    $response = $this->put('/account/profile', [
        'name' => 'Updated Developer Name',
        'email' => 'developer.new@gmail.com',
        'phone' => '987654321',
        'job_title' => 'Lead Engineer',
        'password' => 'new-secret-pass',
        'password_confirmation' => 'new-secret-pass',
    ]);

    $response->assertRedirect();
    $this->developer->refresh();

    expect($this->developer->name)->toBe('Updated Developer Name')
        ->and($this->developer->email)->toBe('developer.new@gmail.com')
        ->and(Hash::check('new-secret-pass', $this->developer->password))->toBeTrue();
});
