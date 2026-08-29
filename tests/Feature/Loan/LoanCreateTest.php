<?php

namespace Tests\Feature\Loan;

use App\Models\Collateral;
use App\Models\Customer;
use App\Models\InterestRule;
use App\Models\LoanAmountConfiguration;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedDefaultLoanConfiguration();
    }

    /**
     * Seed the interest rules, repayment frequency, and repayment terms
     * that this test file's assertions are written against. The global
     * LoanAmountConfiguration needs no seeding here: the table's own
     * migration already inserts a permissive default row (min 0, max
     * null) that never blocks these tests.
     */
    private function seedDefaultLoanConfiguration(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 499999.99, 'interest_rate' => 30.00, 'status' => 'active',
        ]);
        InterestRule::factory()->create([
            'minimum_amount' => 500000, 'maximum_amount' => 4000000, 'interest_rate' => 22.00, 'status' => 'active',
        ]);

        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);

        foreach ([3, 6, 12] as $months) {
            RepaymentTerm::factory()->create([
                'name' => "{$months} Months", 'value' => $months, 'unit' => 'months', 'status' => 'active',
            ]);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function validPayload(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customerId,
            'principal_amount' => 1000000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 12,
            'start_date' => '2026-01-01',
        ], $overrides);
    }

    public function test_authorized_user_can_create_a_pending_loan_by_default(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame($customer->id, $response->json('data.customer_id'));
        $this->assertDatabaseHas('loans', [
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
    }

    public function test_authorized_user_can_create_an_active_loan_when_explicitly_requested(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('active', $response->json('data.status'));
    }

    public function test_creating_a_loan_rejects_a_completed_status(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'completed']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_creating_a_loan_requires_principal_amount(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => '']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['principal_amount']);
    }

    public function test_creating_a_loan_requires_customer_id(): void
    {
        $token = $this->actingUserToken(['loans.create']);

        $payload = $this->validPayload(1);
        unset($payload['customer_id']);

        $response = $this->postJson(
            '/api/v1/loans',
            $payload,
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    public function test_invalid_customer_id_is_rejected(): void
    {
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload(999999),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    public function test_interest_rate_is_30_percent_for_principal_under_500000(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 499999.99]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('30.00', $response->json('data.interest_rate'));
        $this->assertEquals(round(499999.99 * 0.30, 2), (float) $response->json('data.interest_amount'));
    }

    public function test_interest_rate_is_22_percent_for_principal_in_the_500000_to_4000000_range(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 500000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));

        $response2 = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 4000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response2->assertStatus(201);
        $this->assertSame('22.00', $response2->json('data.interest_rate'));
    }

    public function test_interest_amount_is_calculated_correctly(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('220000.00', $response->json('data.interest_amount'));
    }

    public function test_total_amount_is_calculated_correctly(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('1220000.00', $response->json('data.total_amount'));
    }

    public function test_principal_above_the_highest_interest_rule_band_is_rejected(): void
    {
        // This is rejected by the interest rule band lookup (no band covers
        // > 4,000,000 in this file's default setup) — not by the loan
        // amount configuration, which defaults to an unbounded max per the
        // migration. See test_principal_outside_the_configured_loan_amount_range_is_rejected
        // below for the actual config-driven rejection, and
        // test_no_interest_rule_covers_the_amount_is_a_distinct_error_from_loan_amount_range
        // for why these two failure conditions must never share a message.
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 4000000.01]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'No active interest rate rule covers this loan amount. An administrator must configure one before this loan can be created.',
        ]);
        $this->assertDatabaseMissing('loans', ['customer_id' => $customer->id]);
    }

    public function test_no_interest_rule_covers_the_amount_is_a_distinct_error_from_loan_amount_range(): void
    {
        // Regression test: LoanAmountConfiguration.maximum_amount = null
        // ("no upper limit") must not be blamed for a rejection that is
        // actually caused by a gap in the interest rule bands. Previously
        // both failure conditions threw the same LoanAmountOutOfRangeException
        // with the same message, which made this exact scenario look like
        // the loan amount configuration was broken when it was not.
        LoanAmountConfiguration::query()->update([
            'minimum_amount' => 0,
            'maximum_amount' => null,
        ]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 5000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'No active interest rate rule covers this loan amount. An administrator must configure one before this loan can be created.',
        ]);
        $this->assertDatabaseMissing('loans', ['customer_id' => $customer->id]);
    }

    public function test_principal_above_4000000_succeeds_once_the_top_interest_rule_band_is_open_ended(): void
    {
        // Reproduces the reported bug end-to-end: LoanAmountConfiguration
        // says there is no maximum, and once the top interest rule band is
        // also open-ended (as LoanConfigurationSeeder now seeds it), a
        // principal that was previously rejected succeeds.
        LoanAmountConfiguration::query()->update([
            'minimum_amount' => 0,
            'maximum_amount' => null,
        ]);
        InterestRule::query()->where('minimum_amount', 500000)->update(['maximum_amount' => null]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 5000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('5000000.00', $response->json('data.principal_amount'));
    }

    public function test_principal_outside_the_configured_loan_amount_range_is_rejected(): void
    {
        // This exercises LoanAmountConfigurationService::assertWithinRange()
        // specifically — distinct from
        // test_principal_above_the_highest_interest_rule_band_is_rejected
        // above, which is actually rejected by the interest rule band lookup
        // (no band covers > 4,000,000), not by the loan amount configuration.
        // Widen the interest rule bands here so a match would otherwise exist
        // for 5,000,000, isolating the configuration check as the cause.
        InterestRule::factory()->create([
            'minimum_amount' => 4000000.01, 'maximum_amount' => null, 'interest_rate' => 18.00, 'status' => 'active',
        ]);
        LoanAmountConfiguration::query()->update([
            'minimum_amount' => 50000,
            'maximum_amount' => 1000000,
        ]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $tooHigh = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 5000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $tooHigh->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Loan amount is outside the configured lending range.',
        ]);
        $this->assertDatabaseMissing('loans', ['customer_id' => $customer->id]);

        $withinRange = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 800000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $withinRange->assertStatus(201);
    }

    public function test_due_date_is_calculated_from_start_date_and_repayment_term_for_monthly_frequency(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['start_date' => '2026-01-15', 'repayment_term' => 6]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('2026-07-15', $response->json('data.due_date'));
    }

    public function test_reference_no_follows_the_expected_format(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^LN-\d{4}-\d{6}$/', $response->json('data.reference_no'));
    }

    public function test_reference_no_is_unique_across_created_loans(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $first = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.reference_no');

        $second = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.reference_no');

        $this->assertNotSame($first, $second);
    }

    public function test_creating_a_loan_syncs_collaterals_belonging_to_the_customer(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->for($customer)->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['collateral_ids' => [$collateral->id]]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame([$collateral->id], array_column($response->json('data.collaterals'), 'id'));
    }

    public function test_creating_a_loan_rejects_collateral_ids_belonging_to_another_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $foreignCollateral = Collateral::factory()->for($otherCustomer)->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['collateral_ids' => [$foreignCollateral->id]]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['collateral_ids']);
    }

    public function test_loan_is_stored_with_the_correct_customer_id(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $loanId = $response->json('data.id');
        $this->assertDatabaseHas('loans', ['id' => $loanId, 'customer_id' => $customer->id]);
    }

    public function test_calculated_financial_fields_supplied_by_the_client_are_ignored(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            [
                ...$this->validPayload($customer->id, ['principal_amount' => 1000000]),
                'reference_no' => 'LN-9999-999999',
                'interest_rate' => 1,
                'interest_amount' => 1,
                'total_amount' => 1,
            ],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertNotSame('LN-9999-999999', $response->json('data.reference_no'));
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('220000.00', $response->json('data.interest_amount'));
        $this->assertSame('1220000.00', $response->json('data.total_amount'));
    }

    public function test_unauthenticated_request_cannot_create_a_loan(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/loans', $this->validPayload($customer->id));

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_create_permission_cannot_create_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_loan_response_does_not_expose_unrelated_information(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertEqualsCanonicalizing([
            'id', 'customer_id', 'customer', 'reference_no', 'principal_amount', 'interest_rate',
            'interest_amount', 'total_amount', 'has_discount', 'discount_rate', 'applied_interest_rate',
            'repayment_frequency', 'repayment_term',
            'start_date', 'due_date', 'status', 'notes', 'collaterals', 'repayment_schedules',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }

    public function test_inactive_interest_rule_and_frequency_are_never_selected_for_a_new_loan(): void
    {
        // An inactive rule overlapping an active one is allowed (only
        // active ranges are checked for overlap) and must never win.
        InterestRule::factory()->inactive()->create([
            'minimum_amount' => 0, 'maximum_amount' => 4000000, 'interest_rate' => 99.00,
        ]);
        RepaymentFrequency::factory()->inactive()->create([
            'name' => 'Weekly', 'code' => 'weekly', 'interval_value' => 1, 'interval_unit' => 'week',
        ]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));

        $weeklyResponse = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['repayment_frequency' => 'weekly']),
            ['Authorization' => "Bearer {$token}"],
        );

        $weeklyResponse->assertStatus(422)->assertJsonValidationErrors(['repayment_frequency']);
    }
}
