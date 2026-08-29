<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\RepaymentFrequency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentFrequencyUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create(['status' => 'active']);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}",
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('inactive', $response->json('data.status'));
    }

    public function test_updating_a_frequency_does_not_reject_its_own_code(): void
    {
        $frequency = RepaymentFrequency::factory()->create(['code' => 'monthly']);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}",
            ['code' => 'monthly', 'name' => 'Monthly Updated'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
    }

    public function test_updating_a_frequency_to_a_duplicate_code_is_rejected(): void
    {
        RepaymentFrequency::factory()->create(['code' => 'monthly']);
        $other = RepaymentFrequency::factory()->create(['code' => 'weekly']);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$other->id}",
            ['code' => 'monthly'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_unauthenticated_request_cannot_update_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create();

        $response = $this->putJson("/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}", ['status' => 'inactive']);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_update_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}",
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_updating_a_nonexistent_repayment_frequency_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/repayment-frequencies/999999',
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
