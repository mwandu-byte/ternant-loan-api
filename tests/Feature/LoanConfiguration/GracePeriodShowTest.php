<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GracePeriodShowTest extends TestCase
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

    public function test_authorized_user_can_view_the_grace_period(): void
    {
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/grace-period',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame(7, $response->json('data.duration'));
        $this->assertSame('days', $response->json('data.unit'));
    }

    public function test_unauthenticated_request_cannot_view_the_grace_period(): void
    {
        $response = $this->getJson('/api/v1/loan-configurations/grace-period');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_view_the_grace_period(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            '/api/v1/loan-configurations/grace-period',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_no_store_route_exists_for_grace_period(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/grace-period',
            ['duration' => 5, 'unit' => 'days'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(405);
    }

    public function test_no_destroy_route_exists_for_grace_period(): void
    {
        $token = $this->actingUserToken(['loan-configurations.delete']);

        $response = $this->deleteJson(
            '/api/v1/loan-configurations/grace-period',
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(405);
    }
}
