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

class InterestRuleUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_an_interest_rule(): void
    {
        $rule = InterestRule::factory()->create(['interest_rate' => 22]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/interest-rules/{$rule->id}",
            ['interest_rate' => 25],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('25.00', $response->json('data.interest_rate'));
        $this->assertDatabaseHas('interest_rules', ['id' => $rule->id, 'interest_rate' => 25]);
    }

    public function test_updating_a_rule_does_not_reject_overlap_with_itself(): void
    {
        $rule = InterestRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 500000]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/interest-rules/{$rule->id}",
            ['interest_rate' => 25],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
    }

    public function test_updating_a_rule_to_overlap_another_active_rule_is_rejected(): void
    {
        InterestRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 500000]);
        $other = InterestRule::factory()->create(['minimum_amount' => 500001, 'maximum_amount' => 4000000]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/interest-rules/{$other->id}",
            ['minimum_amount' => 400000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['minimum_amount']);
    }

    public function test_unauthenticated_request_cannot_update_an_interest_rule(): void
    {
        $rule = InterestRule::factory()->create();

        $response = $this->putJson("/api/v1/loan-configurations/interest-rules/{$rule->id}", ['interest_rate' => 25]);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_update_an_interest_rule(): void
    {
        $rule = InterestRule::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/loan-configurations/interest-rules/{$rule->id}",
            ['interest_rate' => 25],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_updating_a_nonexistent_interest_rule_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/interest-rules/999999',
            ['interest_rate' => 25],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
