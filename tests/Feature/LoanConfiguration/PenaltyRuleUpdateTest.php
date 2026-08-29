<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\PenaltyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PenaltyRuleUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_a_penalty_rule(): void
    {
        $rule = PenaltyRule::factory()->create(['penalty_value' => 50000]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/penalty-rules/{$rule->id}",
            ['penalty_value' => 75000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('75000.00', $response->json('data.penalty_value'));
    }

    public function test_updating_a_rule_does_not_reject_overlap_with_itself(): void
    {
        $rule = PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 2999999.99]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/penalty-rules/{$rule->id}",
            ['penalty_value' => 75000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
    }

    public function test_updating_a_rule_to_overlap_another_active_rule_is_rejected(): void
    {
        PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 2999999.99]);
        $other = PenaltyRule::factory()->create(['minimum_amount' => 3000000, 'maximum_amount' => null]);
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            "/api/v1/loan-configurations/penalty-rules/{$other->id}",
            ['minimum_amount' => 2000000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['minimum_amount']);
    }

    public function test_unauthenticated_request_cannot_update_a_penalty_rule(): void
    {
        $rule = PenaltyRule::factory()->create();

        $response = $this->putJson("/api/v1/loan-configurations/penalty-rules/{$rule->id}", ['penalty_value' => 75000]);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_update_a_penalty_rule(): void
    {
        $rule = PenaltyRule::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/loan-configurations/penalty-rules/{$rule->id}",
            ['penalty_value' => 75000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }

    public function test_updating_a_nonexistent_penalty_rule_returns_404(): void
    {
        $token = $this->actingUserToken(['loan-configurations.update']);

        $response = $this->putJson(
            '/api/v1/loan-configurations/penalty-rules/999999',
            ['penalty_value' => 75000],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
