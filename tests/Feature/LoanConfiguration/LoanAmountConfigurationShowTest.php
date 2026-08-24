<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanAmountConfigurationShowTest extends TestCase
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

    public function test_authorized_user_can_view_the_loan_amount_configuration(): void
    {
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/loan-amount',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('0.00', $response->json('data.minimum_amount'));
        $this->assertNull($response->json('data.maximum_amount'));
    }

    public function test_unauthenticated_request_cannot_view_the_loan_amount_configuration(): void
    {
        $response = $this->getJson('/api/v1/loan-configurations/loan-amount');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_view_the_loan_amount_configuration(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            '/api/v1/loan-configurations/loan-amount',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_no_store_route_exists_for_loan_amount_configuration(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/loan-amount',
            ['minimum_amount' => 0],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(405);
    }

    public function test_no_destroy_route_exists_for_loan_amount_configuration(): void
    {
        $token = $this->actingUserToken(['loan-configurations.delete']);

        $response = $this->deleteJson(
            '/api/v1/loan-configurations/loan-amount',
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(405);
    }
}
