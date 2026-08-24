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

class PenaltyRuleCreateTest extends TestCase
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

    public function test_authorized_user_can_create_a_penalty_rule(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/penalty-rules',
            [
                'minimum_amount' => 0,
                'maximum_amount' => 2999999.99,
                'penalty_type' => 'fixed',
                'penalty_value' => 50000,
                'application_frequency' => 'once',
            ],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('active', $response->json('data.status'));
        $this->assertDatabaseHas('penalty_rules', ['penalty_value' => 50000, 'status' => 'active']);
    }

    public function test_maximum_amount_must_be_greater_than_minimum_amount(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/penalty-rules',
            ['minimum_amount' => 100, 'maximum_amount' => 50, 'penalty_value' => 10, 'application_frequency' => 'once'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['maximum_amount']);
    }

    public function test_overlapping_active_penalty_rules_are_rejected(): void
    {
        PenaltyRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 2999999.99]);
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/penalty-rules',
            ['minimum_amount' => 2000000, 'maximum_amount' => null, 'penalty_value' => 100000, 'application_frequency' => 'once'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['minimum_amount']);
    }

    public function test_overlap_is_allowed_when_the_other_range_is_inactive(): void
    {
        PenaltyRule::factory()->inactive()->create(['minimum_amount' => 0, 'maximum_amount' => 2999999.99]);
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/penalty-rules',
            ['minimum_amount' => 2000000, 'maximum_amount' => null, 'penalty_value' => 100000, 'application_frequency' => 'once'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
    }

    public function test_unauthenticated_request_cannot_create_a_penalty_rule(): void
    {
        $response = $this->postJson('/api/v1/loan-configurations/penalty-rules', [
            'minimum_amount' => 0, 'penalty_value' => 10, 'application_frequency' => 'once',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_create_permission_cannot_create_a_penalty_rule(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            '/api/v1/loan-configurations/penalty-rules',
            ['minimum_amount' => 0, 'penalty_value' => 10, 'application_frequency' => 'once'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
