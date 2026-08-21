<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Route::middleware(['api', 'auth:api', 'permission:users.view'])
            ->get('/api/v1/_test/protected', fn () => response()->json(['ok' => true]));
    }

    public function test_user_with_the_required_permission_can_access_the_route(): void
    {
        Permission::create(['name' => 'users.view', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'admin-test', 'guard_name' => 'api']);
        $role->givePermissionTo('users.view');

        $user = User::factory()->create();
        $user->assignRole($role);

        $token = JWTAuth::fromUser($user);

        $this->getJson('/api/v1/_test/protected', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_user_without_the_required_permission_is_forbidden(): void
    {
        Permission::create(['name' => 'users.view', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'staff-test', 'guard_name' => 'api']);
        // Deliberately not granted users.view.

        $user = User::factory()->create();
        $user->assignRole($role);

        $token = JWTAuth::fromUser($user);

        $this->getJson('/api/v1/_test/protected', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'You do not have permission to perform this action.']);
    }
}
