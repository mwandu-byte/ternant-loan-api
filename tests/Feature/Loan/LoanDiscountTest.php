<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\InterestRule;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'principal_amount' => 1000000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 12,
            'start_date' => '2026-01-01',
        ], $overrides);
    }

    public function test_loan_without_discount_uses_the_configured_interest_rule_rate(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => null, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('22.00', $response->json('data.applied_interest_rate'));
        $this->assertFalse($response->json('data.has_discount'));
        $this->assertNull($response->json('data.discount_rate'));
    }

    public function test_loan_with_discount_uses_the_discount_rate_as_applied_rate(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => null, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['has_discount' => true, 'discount_rate' => 20]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertSame('20.00', $response->json('data.applied_interest_rate'));
        $this->assertSame('20.00', $response->json('data.discount_rate'));
        $this->assertTrue($response->json('data.has_discount'));
        $this->assertSame('200000.00', $response->json('data.interest_amount'));
        $this->assertSame('1200000.00', $response->json('data.total_amount'));
    }

    public function test_discount_rate_is_rejected_when_has_discount_is_false(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => null, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['discount_rate' => 20]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['discount_rate']);
    }

    public function test_discount_rate_is_required_when_has_discount_is_true(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => null, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['has_discount' => true]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['discount_rate']);
    }

    public function test_historical_loan_keeps_its_applied_interest_rate_after_interest_rule_changes(): void
    {
        $rule = InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => null, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.view']);

        $created = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );
        $created->assertStatus(201);
        $loanId = $created->json('data.id');

        $rule->update(['interest_rate' => 99.00]);

        $refetched = $this->getJson(
            "/api/v1/customers/{$customer->id}/loans/{$loanId}",
            ['Authorization' => "Bearer {$token}"],
        );

        $refetched->assertStatus(200);
        $this->assertSame('22.00', $refetched->json('data.interest_rate'));
        $this->assertSame('22.00', $refetched->json('data.applied_interest_rate'));
    }
}
