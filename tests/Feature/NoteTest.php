<?php

use App\Models\ClientCompany;
use App\Models\Currency;
use App\Models\Note;
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

    $this->clientCompany = ClientCompany::factory()->create([
        'currency_id' => Currency::first()->id,
    ]);

    $this->project = Project::create([
        'client_company_id' => $this->clientCompany->id,
        'name' => 'Notes Project',
        'rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);
});

it('lists project notes and masks locked content', function () {
    $this->actingAs($this->admin);

    $plainNote = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Plain Note',
        'content' => 'Visible content',
        'is_locked' => false,
    ]);

    $lockedNote = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Secret Note',
        'content' => 'Super Secret Raw Text',
        'is_locked' => true,
        'passcode_salt' => 'randomsalt123',
    ]);

    $response = $this->get("/projects/{$this->project->id}/notes");
    $response->assertStatus(200);

    $notes = $response->original->getData()['page']['props']['notes'];
    $plain = collect($notes)->firstWhere('id', $plainNote->id);
    $locked = collect($notes)->firstWhere('id', $lockedNote->id);

    expect($plain['content'])->toBe('Visible content')
        ->and($locked['content'])->toBeNull();
});

it('creates a new note', function () {
    $this->actingAs($this->admin);

    $response = $this->post("/projects/{$this->project->id}/notes", [
        'title' => 'Architecture Notes',
        'content' => 'Microservices and event sourcing',
    ]);

    $response->assertRedirect("/projects/{$this->project->id}/notes");

    $this->assertDatabaseHas('notes', [
        'project_id' => $this->project->id,
        'title' => 'Architecture Notes',
        'content' => 'Microservices and event sourcing',
        'is_locked' => false,
    ]);
});

it('updates an unlocked note', function () {
    $this->actingAs($this->admin);

    $note = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Original Title',
        'content' => 'Original Content',
        'is_locked' => false,
    ]);

    $response = $this->put("/projects/{$this->project->id}/notes/{$note->id}", [
        'title' => 'New Title',
        'content' => 'New Content',
    ]);

    $response->assertRedirect();
    $note->refresh();
    expect($note->title)->toBe('New Title')
        ->and($note->content)->toBe('New Content');
});

it('locks note with passcode and encrypts content', function () {
    $this->actingAs($this->admin);

    $note = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Confidential Note',
        'content' => 'Sensitive Database Password: ABC',
        'is_locked' => false,
    ]);

    $response = $this->post("/projects/{$this->project->id}/notes/{$note->id}/lock", [
        'passcode' => '987654',
        'content' => 'Sensitive Database Password: ABC',
    ]);

    $response->assertRedirect();
    $note->refresh();

    expect($note->is_locked)->toBeTrue()
        ->and($note->passcode_salt)->not->toBeNull()
        ->and($note->content)->not->toBe('Sensitive Database Password: ABC');
});

it('unlocks note with valid passcode and rejects invalid passcode', function () {
    $this->actingAs($this->admin);

    $note = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Confidential Note',
        'content' => 'Initial Content',
        'is_locked' => false,
    ]);

    // Lock note
    $this->post("/projects/{$this->project->id}/notes/{$note->id}/lock", [
        'passcode' => '123456',
        'content' => 'Secret Vault Code 42',
    ]);
    $note->refresh();

    // Test incorrect passcode
    $failResponse = $this->postJson("/projects/{$this->project->id}/notes/{$note->id}/unlock", [
        'passcode' => '000000',
    ]);
    $failResponse->assertStatus(422);

    // Test correct passcode
    $successResponse = $this->postJson("/projects/{$this->project->id}/notes/{$note->id}/unlock", [
        'passcode' => '123456',
    ]);
    $successResponse->assertOk()
        ->assertJson([
            'content' => 'Secret Vault Code 42',
        ]);
});

it('removes lock from note with correct passcode', function () {
    $this->actingAs($this->admin);

    $note = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Secret Note',
        'content' => 'Temporary Secret',
        'is_locked' => false,
    ]);

    // Lock
    $this->post("/projects/{$this->project->id}/notes/{$note->id}/lock", [
        'passcode' => 'secretpass',
        'content' => 'Temporary Secret',
    ]);
    $note->refresh();

    // Remove lock
    $response = $this->post("/projects/{$this->project->id}/notes/{$note->id}/remove-lock", [
        'passcode' => 'secretpass',
    ]);

    $response->assertRedirect();
    $note->refresh();

    expect($note->is_locked)->toBeFalse()
        ->and($note->passcode_salt)->toBeNull()
        ->and($note->content)->toBe('Temporary Secret');
});

it('deletes a note', function () {
    $this->actingAs($this->admin);

    $note = Note::create([
        'project_id' => $this->project->id,
        'title' => 'Note to delete',
        'content' => 'Content',
        'is_locked' => false,
    ]);

    $response = $this->delete("/projects/{$this->project->id}/notes/{$note->id}");
    $response->assertRedirect();

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});
