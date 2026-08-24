<?php

namespace Tests\Feature\RepaymentSchedule;

use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentScheduleShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_repayment_schedule(): void
    {
        $repayment = RepaymentSchedule::factory()->create();
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson("/api/v1/repayment-schedules/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $repayment->id],
        ]);
    }

    public function test_show_requires_authentication(): void
    {
        $repayment = RepaymentSchedule::factory()->create();

        $response = $this->getJson("/api/v1/repayment-schedules/{$repayment->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_repayments_view_permission(): void
    {
        $repayment = RepaymentSchedule::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/repayment-schedules/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_show_returns_404_for_nonexistent_schedule(): void
    {
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson('/api/v1/repayment-schedules/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Repayment schedule not found.',
        ]);
    }

    public function test_show_response_contains_exact_expected_fields(): void
    {
        $repayment = RepaymentSchedule::factory()->create();
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson("/api/v1/repayment-schedules/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([
            'id', 'loan_id', 'installment_number', 'due_date', 'principal_amount', 'interest_amount',
            'total_amount', 'outstanding_amount', 'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }
}
