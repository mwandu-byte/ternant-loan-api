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

class InterestRuleCreateTest extends TestCase
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

    public function test_authorized_user_can_create_an_interest_rule(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/interest-rules',
            [
                'minimum_amount' => 0,
                'maximum_amount' => 499999.99,
                'interest_rate' => 30,
                'calculation_method' => 'percentage',
            ],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('active', $response->json('data.status'));
        $this->assertDatabaseHas('interest_rules', ['interest_rate' => 30, 'status' => 'active']);
    }

    public function test_maximum_amount_must_be_greater_than_minimum_amount(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/interest-rules',
            ['minimum_amount' => 100, 'maximum_amount' => 50, 'interest_rate' => 10],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['maximum_amount']);
    }

    public function test_overlapping_active_interest_rules_are_rejected(): void
    {
        InterestRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 500000, 'status' => 'active']);
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/interest-rules',
            ['minimum_amount' => 400000, 'maximum_amount' => 4000000, 'interest_rate' => 22],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['minimum_amount']);
    }

    public function test_overlap_is_allowed_when_the_other_range_is_inactive(): void
    {
        InterestRule::factory()->inactive()->create(['minimum_amount' => 0, 'maximum_amount' => 500000]);
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/interest-rules',
            ['minimum_amount' => 400000, 'maximum_amount' => 4000000, 'interest_rate' => 22],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
    }

    public function test_unauthenticated_request_cannot_create_an_interest_rule(): void
    {
        $response = $this->postJson('/api/v1/loan-configurations/interest-rules', [
            'minimum_amount' => 0, 'interest_rate' => 10,
        ]);

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_create_permission_cannot_create_an_interest_rule(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            '/api/v1/loan-configurations/interest-rules',
            ['minimum_amount' => 0, 'interest_rate' => 10],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }
}
