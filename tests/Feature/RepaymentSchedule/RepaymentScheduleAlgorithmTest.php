<?php

namespace Tests\Feature\RepaymentSchedule;

use App\Models\InterestRule;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentScheduleAlgorithmTest extends TestCase
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

    private function generate(Loan $loan): array
    {
        $token = $this->actingUserToken(['repayment-schedules.create']);

        return $this->postJson(
            "/api/v1/loans/{$loan->id}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201)->json('data');
    }

    public function test_sum_of_installment_amounts_equals_the_loans_amounts(): void
    {
        $loan = Loan::factory()->active()->create([
            'principal_amount' => 1200000,
            'interest_amount' => 240000,
            'total_amount' => 1440000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        $this->assertSame('1200000.00', number_format(array_sum(array_column($schedule, 'principal_amount')), 2, '.', ''));
        $this->assertSame('240000.00', number_format(array_sum(array_column($schedule, 'interest_amount')), 2, '.', ''));
        $this->assertSame('1440000.00', number_format(array_sum(array_column($schedule, 'total_amount')), 2, '.', ''));
    }

    public function test_remainder_is_absorbed_into_final_installment(): void
    {
        $loan = Loan::factory()->active()->create([
            'principal_amount' => 1000000,
            'interest_amount' => 0,
            'total_amount' => 1000000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 3,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        $this->assertSame('333333.33', $schedule[0]['principal_amount']);
        $this->assertSame('333333.33', $schedule[1]['principal_amount']);
        $this->assertSame('333333.34', $schedule[2]['principal_amount']);
        $this->assertSame(
            '1000000.00',
            number_format(array_sum(array_column($schedule, 'principal_amount')), 2, '.', ''),
        );
    }

    public function test_due_dates_do_not_overflow_at_month_end(): void
    {
        $loan = Loan::factory()->active()->create([
            'principal_amount' => 300000,
            'interest_amount' => 0,
            'total_amount' => 300000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 2,
            'start_date' => '2026-01-31',
        ]);

        $schedule = $this->generate($loan);

        $this->assertSame('2026-02-28', $schedule[0]['due_date']);
        $this->assertSame('2026-03-31', $schedule[1]['due_date']);
    }

    public function test_installment_numbers_are_sequential_starting_at_one(): void
    {
        $loan = Loan::factory()->active()->create([
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        $this->assertSame([1, 2, 3, 4], array_column($schedule, 'installment_number'));
    }

    public function test_outstanding_amount_equals_total_amount_on_generation(): void
    {
        $loan = Loan::factory()->active()->create([
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        foreach ($schedule as $installment) {
            $this->assertSame($installment['total_amount'], $installment['outstanding_amount']);
        }
    }

    public function test_correct_number_of_installments_is_generated_for_the_loans_term(): void
    {
        $loan = Loan::factory()->active()->create([
            'repayment_frequency' => 'monthly',
            'repayment_term' => 7,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        $this->assertCount(7, $schedule);
    }

    public function test_weekly_frequency_is_respected(): void
    {
        RepaymentFrequency::factory()->create([
            'name' => 'Weekly', 'code' => 'weekly', 'interval_value' => 1, 'interval_unit' => 'week', 'status' => 'active',
        ]);

        $loan = Loan::factory()->active()->create([
            'repayment_frequency' => 'weekly',
            'repayment_term' => 4,
            'start_date' => '2026-01-05',
        ]);

        $schedule = $this->generate($loan);

        $this->assertSame(['2026-01-12', '2026-01-19', '2026-01-26', '2026-02-02'], array_column($schedule, 'due_date'));
    }

    public function test_current_interest_rule_changes_do_not_alter_an_existing_loans_schedule(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 4000000, 'interest_rate' => 15.00, 'status' => 'active',
        ]);

        $loan = Loan::factory()->active()->create([
            'principal_amount' => 1000000,
            'interest_amount' => 220000,
            'total_amount' => 1220000,
            'applied_interest_rate' => 22.00,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ]);

        // Simulate the global interest configuration changing after this
        // loan was created.
        InterestRule::query()->update(['interest_rate' => 99.00]);

        $schedule = $this->generate($loan);

        $this->assertSame(
            '220000.00',
            number_format(array_sum(array_column($schedule, 'interest_amount')), 2, '.', ''),
        );
    }

    public function test_status_is_pending_on_generation(): void
    {
        $loan = Loan::factory()->active()->create([
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ]);

        $schedule = $this->generate($loan);

        foreach ($schedule as $installment) {
            $this->assertContains($installment['status'], ['pending', 'due', 'overdue']);
        }

        $this->assertDatabaseHas('repayment_schedules', ['loan_id' => $loan->id, 'status' => 'pending']);
    }
}
