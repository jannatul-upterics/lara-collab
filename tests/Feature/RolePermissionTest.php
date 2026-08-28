<?php

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('seeds all predefined permissions for each role accurately', function () {
    $roles = ['admin', 'manager', 'developer', 'qa engineer', 'designer', 'client'];

    foreach ($roles as $roleName) {
        $role = Role::where('name', $roleName)->first();
        expect($role)->not->toBeNull();

        $expectedPermissions = collect(PermissionService::$permissionsByRole[$roleName])
            ->flatten()
            ->toArray();

        foreach ($expectedPermissions as $permissionName) {
            expect($role->hasPermissionTo($permissionName))->toBeTrue(
                "Role '{$roleName}' should have permission '{$permissionName}'"
            );
        }
    }
});

it('correctly checks user permissions through assigned role', function () {
    $qaUser = User::factory()->create();
    $qaUser->assignRole('qa engineer');

    expect($qaUser->hasPermissionTo('view tasks'))->toBeTrue()
        ->and($qaUser->hasPermissionTo('create task'))->toBeTrue()
        ->and($qaUser->hasPermissionTo('create project'))->toBeFalse()
        ->and($qaUser->hasPermissionTo('create user'))->toBeFalse();
});
