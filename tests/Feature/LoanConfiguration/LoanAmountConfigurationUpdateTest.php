<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanAmountConfigurationUpdateTest extends TestCase
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

    public function test_authorized_user_can_configure_loan_amount_limits(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/loan-amount',
            ['minimum_amount' => 50000, 'maximum_amount' => 5000000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('50000.00', $response->json('data.minimum_amount'));
        $this->assertSame('5000000.00', $response->json('data.maximum_amount'));
        $this->assertDatabaseHas('loan_amount_configurations', ['minimum_amount' => 50000, 'maximum_amount' => 5000000]);
    }

    public function test_maximum_amount_must_be_greater_than_minimum_amount(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/loan-amount',
            ['minimum_amount' => 100000, 'maximum_amount' => 50000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['maximum_amount']);
    }

    public function test_unauthenticated_request_cannot_configure_loan_amount_limits(): void
    {
        $response = $this->putJson('/api/v1/loan-configurations/loan-amount', ['minimum_amount' => 50000]);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_configure_loan_amount_limits(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            '/api/v1/loan-configurations/loan-amount',
            ['minimum_amount' => 50000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
