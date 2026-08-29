<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingUserToken(array $permissions): string
    {
        $permissions[] = 'data.view-all';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_update_name_email_and_status(): void
    {
        $target = User::factory()->create(['name' => 'Old Name']);
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'updated@example.com',
            'status' => 'inactive',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('New Name', $response->json('data.name'));
        $this->assertSame('updated@example.com', $response->json('data.email'));
        $this->assertSame('inactive', $response->json('data.status'));
    }

    public function test_password_can_be_updated_when_explicitly_supplied(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertTrue(password_verify('NewStrongPass123!', $target->fresh()->password));
    }

    public function test_roles_field_in_body_is_silently_ignored(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'New Name',
            'roles' => ['loan_officer'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(0, $target->fresh()->roles);
        $this->assertFalse($target->fresh()->hasRole($role));
    }

    public function test_duplicate_email_on_update_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'email' => 'taken@example.com',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_updating_with_own_current_email_does_not_fail_uniqueness(): void
    {
        $target = User::factory()->create(['email' => 'self@example.com']);
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'email' => 'self@example.com',
            'name' => 'Renamed',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
    }

    public function test_update_requires_authentication(): void
    {
        $target = User::factory()->create();

        $response = $this->putJson("/api/v1/users/{$target->id}", ['name' => 'New Name']);

        $response->assertStatus(401);
    }

    public function test_update_requires_users_update_permission(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/users/{$target->id}", ['name' => 'New Name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_update_returns_404_for_nonexistent_user(): void
    {
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson('/api/v1/users/999999', ['name' => 'New Name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
