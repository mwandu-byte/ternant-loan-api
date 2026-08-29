<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\InterestRule;
use App\Models\PenaltyRule;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Confirms penalty configuration never restricts loan creation — only
 * interest configuration determines the applied rate, and
 * PenaltyRule/loan-amount-range are entirely independent concerns. See
 * app/Services/Loan/LoanService.php, which never references PenaltyRule.
 */
class LoanCreationIgnoresPenaltyConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 499999.99, 'interest_rate' => 30.00, 'status' => 'active',
        ]);
        InterestRule::factory()->create([
            'minimum_amount' => 500000, 'maximum_amount' => 4000000, 'interest_rate' => 22.00, 'status' => 'active',
        ]);

        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);
        RepaymentTerm::factory()->create([
            'name' => '12 Months', 'value' => 12, 'unit' => 'months', 'status' => 'active',
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

    public function test_loan_creation_succeeds_with_no_penalty_rules_configured_at_all(): void
    {
        PenaltyRule::query()->delete();

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
    }

    public function test_loan_creation_succeeds_when_no_penalty_rule_covers_the_principal(): void
    {
        // Only covers a narrow low bracket — the loan's principal below
        // falls well outside it, and creation must still succeed.
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 100, 'penalty_value' => 50000, 'status' => 'active',
        ]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
    }

    public function test_loan_creation_succeeds_with_every_penalty_rule_deactivated(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 4000000, 'penalty_value' => 50000, 'status' => 'inactive',
        ]);

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
    }

    public function test_interest_rate_applied_is_still_driven_by_interest_configuration_regardless_of_penalty_config(): void
    {
        PenaltyRule::query()->delete();

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('22.00', $response->json('data.applied_interest_rate'));
    }

    public function test_discounted_interest_rate_still_overrides_configured_rate_regardless_of_penalty_config(): void
    {
        PenaltyRule::query()->delete();

        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, [
                'principal_amount' => 1000000,
                'has_discount' => true,
                'discount_rate' => 10,
            ]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('10.00', $response->json('data.applied_interest_rate'));
        $this->assertSame('100000.00', $response->json('data.interest_amount'));
    }
}
