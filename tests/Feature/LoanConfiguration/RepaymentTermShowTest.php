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

class RepaymentTermShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_single_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create(['value' => 4]);
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            "/api/v1/loan-configurations/repayment-terms/{$term->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('data.value'));
    }

    public function test_unauthenticated_request_cannot_view_a_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create();

        $response = $this->getJson("/api/v1/loan-configurations/repayment-terms/{$term->id}");

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_view_a_repayment_term(): void
    {
        $term = RepaymentTerm::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            "/api/v1/loan-configurations/repayment-terms/{$term->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_viewing_a_nonexistent_repayment_term_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-terms/999999',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
