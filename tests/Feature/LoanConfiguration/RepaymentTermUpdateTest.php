<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\RepaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentTermUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_a_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create(['status' => 'active']);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-terms/{$term->id}",
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('inactive', $response->json('data.status'));
    }

    public function test_unauthenticated_request_cannot_update_a_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create();

        $response = $this->putJson("/api/v1/loan-configurations/repayment-terms/{$term->id}", ['status' => 'inactive']);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_update_a_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/loan-configurations/repayment-terms/{$term->id}",
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_updating_a_nonexistent_repayment_term_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/repayment-terms/999999',
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
