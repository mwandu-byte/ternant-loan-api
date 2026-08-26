<?php

namespace Tests\Feature\Penalty;

use App\Models\Penalty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PenaltyShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_penalty(): void
    {
        $penalty = Penalty::factory()->create();
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson("/api/v1/penalties/{$penalty->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $penalty->id],
        ]);
    }

    public function test_show_requires_authentication(): void
    {
        $penalty = Penalty::factory()->create();

        $response = $this->getJson("/api/v1/penalties/{$penalty->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_view_permission(): void
    {
        $penalty = Penalty::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/penalties/{$penalty->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_penalty(): void
    {
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson('/api/v1/penalties/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Penalty not found.',
        ]);
    }

    public function test_show_response_contains_exact_expected_fields(): void
    {
        $penalty = Penalty::factory()->create();
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson("/api/v1/penalties/{$penalty->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([
            'id', 'loan_id', 'repayment_schedule_id', 'penalty_rule_id', 'amount',
            'period_start_date', 'applied_date', 'status', 'reason', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }
}
