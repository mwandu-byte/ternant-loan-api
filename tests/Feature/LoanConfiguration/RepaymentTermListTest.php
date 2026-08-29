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

class RepaymentTermListTest extends TestCase
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

    public function test_authorized_user_can_view_repayment_terms(): void
    {
        RepaymentTerm::factory()->create(['value' => 3]);
        RepaymentTerm::factory()->inactive()->create(['value' => 6]);
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-terms',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_inactive_terms_are_still_listed_but_flagged_inactive(): void
    {
        RepaymentTerm::factory()->inactive()->create();
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-terms',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('inactive', $response->json('data.0.status'));
    }

    public function test_unauthenticated_request_cannot_list_repayment_terms(): void
    {
        $response = $this->getJson('/api/v1/loan-configurations/repayment-terms');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_list_repayment_terms(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-terms',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
