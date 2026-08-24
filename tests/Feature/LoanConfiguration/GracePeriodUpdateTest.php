<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GracePeriodUpdateTest extends TestCase
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

    public function test_authorized_user_can_configure_the_grace_period(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/grace-period',
            ['duration' => 14, 'unit' => 'days'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame(14, $response->json('data.duration'));
        $this->assertDatabaseHas('grace_periods', ['duration' => 14, 'unit' => 'days']);
    }

    public function test_duration_must_be_at_least_one(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/grace-period',
            ['duration' => 0],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['duration']);
    }

    public function test_unauthenticated_request_cannot_configure_the_grace_period(): void
    {
        $response = $this->putJson('/api/v1/loan-configurations/grace-period', ['duration' => 14]);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_configure_the_grace_period(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            '/api/v1/loan-configurations/grace-period',
            ['duration' => 14],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
