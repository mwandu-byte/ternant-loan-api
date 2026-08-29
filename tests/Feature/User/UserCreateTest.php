<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserCreateTest extends TestCase
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

    public function test_authorized_user_can_create_a_user(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('users', ['email' => 'new-officer@example.com']);
    }

    public function test_can_create_a_user_with_roles(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'roles' => ['loan_officer'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertSame('loan_officer', $response->json('data.roles.0.name'));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'taken@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_password_is_hashed(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        $stored = User::where('email', 'new-officer@example.com')->first();
        $this->assertNotSame('StrongPass123!', $stored->password);
        $this->assertTrue(password_verify('StrongPass123!', $stored->password));
    }

    public function test_password_is_never_returned(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $response->assertJsonMissing(['password' => 'StrongPass123!']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_mismatched_password_confirmation_is_rejected(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'DoesNotMatch123!',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $token = $this->actingUserToken(['users.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'roles' => ['does_not_exist'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['roles.0']);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_store_requires_users_create_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Officer',
            'email' => 'new-officer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }
}
