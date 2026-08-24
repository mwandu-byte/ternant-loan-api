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

class RepaymentFrequencyListTest extends TestCase
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

    public function test_authorized_user_can_view_repayment_frequencies(): void
    {
        RepaymentFrequency::factory()->create(['code' => 'monthly']);
        RepaymentFrequency::factory()->inactive()->create(['code' => 'weekly']);
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_inactive_frequencies_are_still_listed_but_flagged_inactive(): void
    {
        RepaymentFrequency::factory()->inactive()->create();
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('inactive', $response->json('data.0.status'));
    }

    public function test_unauthenticated_request_cannot_list_repayment_frequencies(): void
    {
        $response = $this->getJson('/api/v1/loan-configurations/repayment-frequencies');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_list_repayment_frequencies(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
