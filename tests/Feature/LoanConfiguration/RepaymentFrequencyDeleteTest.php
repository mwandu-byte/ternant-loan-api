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

class RepaymentFrequencyDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create();
        $token = $this->actingUserToken(['loan-configurations.delete']);

        $response = $this->deleteJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Repayment frequency deleted successfully',
            'data' => null,
        ]);
        $this->assertDatabaseMissing('repayment_frequencies', ['id' => $frequency->id]);
    }

    public function test_unauthenticated_request_cannot_delete_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create();

        $response = $this->deleteJson("/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}");

        $response->assertStatus(401);
    }

    public function test_user_without_delete_permission_cannot_delete_a_repayment_frequency(): void
    {
        $frequency = RepaymentFrequency::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson(
            "/api/v1/loan-configurations/repayment-frequencies/{$frequency->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('repayment_frequencies', ['id' => $frequency->id]);
    }

    public function test_deleting_a_nonexistent_repayment_frequency_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.delete']);

        $response = $this->deleteJson(
            '/api/v1/loan-configurations/repayment-frequencies/999999',
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
