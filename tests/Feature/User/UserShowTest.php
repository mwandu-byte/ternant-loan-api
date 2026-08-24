<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserShowTest extends TestCase
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
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_view_a_user(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson("/api/v1/users/{$target->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $target->id],
        ]);
    }

    public function test_show_response_contains_exact_expected_fields(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson("/api/v1/users/{$target->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'status', 'roles', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
    }

    public function test_show_never_returns_password_or_tokens(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson("/api/v1/users/{$target->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
        $this->assertArrayNotHasKey('access_token', $data);
        $this->assertArrayNotHasKey('refresh_token', $data);
    }

    public function test_show_requires_authentication(): void
    {
        $target = User::factory()->create();

        $response = $this->getJson("/api/v1/users/{$target->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_users_view_permission(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/users/{$target->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_user(): void
    {
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
