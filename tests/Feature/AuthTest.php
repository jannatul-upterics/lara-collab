<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create([
        'email' => 'john.doe@example.com',
        'password' => Hash::make('password123'),
    ]);
});

it('renders the login screen', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

it('authenticates users with valid credentials', function () {
    $response = $this->post('/login', [
        'email' => 'john.doe@example.com',
        'password' => 'password123',
    ]);

    $this->assertAuthenticatedAs($this->user);
    $response->assertRedirect('/dashboard');
});

it('does not authenticate users with invalid password', function () {
    $response = $this->post('/login', [
        'email' => 'john.doe@example.com',
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

it('does not authenticate non-existent users', function () {
    $response = $this->post('/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

it('validates required fields on login', function () {
    $response = $this->post('/login', [
        'email' => '',
        'password' => '',
    ]);

    $response->assertSessionHasErrors(['email', 'password']);
});

it('validates email format on login', function () {
    $response = $this->post('/login', [
        'email' => 'invalid-email-format',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors('email');
});

it('logs out an authenticated user', function () {
    $this->actingAs($this->user);

    $response = $this->delete('/logout');

    $this->assertGuest();
    $response->assertRedirect('/login');
});

it('renders forgot password screen', function () {
    $response = $this->get('/password/forgot');

    $response->assertStatus(200);
});

it('sends password reset link to valid user', function () {
    $response = $this->post('/password/forgot', [
        'email' => 'john.doe@example.com',
    ]);

    $response->assertSessionHas('status');
});

it('fails sending password reset link to non-existent email', function () {
    $response = $this->post('/password/forgot', [
        'email' => 'nonexistent@example.com',
    ]);

    $response->assertSessionHasErrors('email');
});

it('resets password with valid token', function () {
    $token = Password::createToken($this->user);

    $response = $this->post('/password/new', [
        'token' => $token,
        'email' => 'john.doe@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHas('notify', 'password-reset');

    $this->assertTrue(Hash::check('new-password-123', $this->user->fresh()->password));
});

it('fails resetting password with invalid token', function () {
    $response = $this->post('/password/new', [
        'token' => 'invalid-token-12345',
        'email' => 'john.doe@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertSessionHasErrors('email');
});

it('validates password confirmation on reset', function () {
    $token = Password::createToken($this->user);

    $response = $this->post('/password/new', [
        'token' => $token,
        'email' => 'john.doe@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'mismatched-password',
    ]);

    $response->assertSessionHasErrors('password');
});
