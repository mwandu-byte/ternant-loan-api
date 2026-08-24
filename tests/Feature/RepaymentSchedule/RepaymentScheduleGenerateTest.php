<?php

namespace Tests\Feature\RepaymentSchedule;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentScheduleGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);
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

    private function activeLoan(array $overrides = []): Loan
    {
        return Loan::factory()->active()->create(array_merge([
            'principal_amount' => 1200000,
            'interest_amount' => 240000,
            'total_amount' => 1440000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
            'due_date' => '2026-05-24',
        ], $overrides));
    }

    public function test_authorized_user_can_generate_schedule_for_active_loan(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertCount(4, $response->json('data'));
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_generated_schedule_belongs_to_the_correct_loan(): void
    {
        $loan = $this->activeLoan();
        $otherLoan = $this->activeLoan();
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201);

        $this->assertSame(4, RepaymentSchedule::where('loan_id', $loan->id)->count());
        $this->assertSame(0, RepaymentSchedule::where('loan_id', $otherLoan->id)->count());
    }

    public function test_generation_requires_authentication(): void
    {
        $loan = $this->activeLoan();

        $response = $this->postJson("/api/v1/loans/{$loan->id}/repayment-schedules/generate");

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_generation_requires_repayments_create_permission(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_generation_fails_for_pending_loan(): void
    {
        $loan = Loan::factory()->create(['status' => 'pending', 'repayment_frequency' => 'monthly']);
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Only active loans are eligible for repayment schedule generation.',
        ]);
    }

    public function test_generation_fails_for_completed_loan(): void
    {
        $loan = Loan::factory()->completed()->create(['repayment_frequency' => 'monthly']);
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422);
    }

    public function test_generation_fails_for_cancelled_loan(): void
    {
        $loan = Loan::factory()->cancelled()->create(['repayment_frequency' => 'monthly']);
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422);
    }

    public function test_generation_fails_when_schedule_already_exists(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'A repayment schedule has already been generated for this loan.',
        ]);
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_generation_returns_404_for_nonexistent_loan(): void
    {
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $response = $this->postJson(
            '/api/v1/loans/999999/repayment-schedules/generate',
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }

    public function test_no_payment_or_penalty_records_are_created_by_generation(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['repayment-schedules.create']);

        $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201);

        $this->assertDatabaseCount('payments', 0);
        $this->assertFalse(class_exists(Penalty::class));
    }
}
