<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\InterestRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InterestRuleListTest extends TestCase
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

    public function test_authorized_user_can_view_interest_rules(): void
    {
        InterestRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 499999.99, 'interest_rate' => 30]);
        InterestRule::factory()->inactive()->create(['minimum_amount' => 500000, 'maximum_amount' => null, 'interest_rate' => 22]);
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/interest-rules',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_inactive_rules_are_still_listed_but_flagged_inactive(): void
    {
        InterestRule::factory()->inactive()->create(['minimum_amount' => 0, 'maximum_amount' => 100000, 'interest_rate' => 30]);
        $token = $this->actingUserToken(['loan-configurations.view']);

        $response = $this->getJson(
            '/api/v1/loan-configurations/interest-rules',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('inactive', $response->json('data.0.status'));
    }

    public function test_unauthenticated_request_cannot_list_interest_rules(): void
    {
        $response = $this->getJson('/api/v1/loan-configurations/interest-rules');

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_view_permission_cannot_list_interest_rules(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            '/api/v1/loan-configurations/interest-rules',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }
}
