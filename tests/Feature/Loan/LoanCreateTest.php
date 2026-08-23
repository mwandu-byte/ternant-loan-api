<?php

namespace Tests\Feature\Loan;

use App\Models\Collateral;
use App\Models\Customer;
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

    public function test_authorized_user_can_create_a_pending_loan_by_default(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['status' => 'active']),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['status' => 'completed']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_creating_a_loan_requires_principal_amount(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => '']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['principal_amount']);
    }

    public function test_interest_rate_is_30_percent_for_principal_under_500000(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 499999.99]),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 500000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('22.00', $response->json('data.interest_rate'));

        $response2 = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 4000000]),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 1000000]),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 1000000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('1220000.00', $response->json('data.total_amount'));
    }

    public function test_principal_above_4000000_is_rejected(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['principal_amount' => 4000000.01]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Loan amount is outside the configured lending range.',
        ]);
    }

    public function test_due_date_is_calculated_from_start_date_and_repayment_term_for_monthly_frequency(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['start_date' => '2026-01-15', 'repayment_term' => 6]),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.reference_no');

        $second = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['collateral_ids' => [$collateral->id]]),
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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(['collateral_ids' => [$foreignCollateral->id]]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['collateral_ids']);
    }

    public function test_customer_id_in_payload_is_ignored_and_taken_from_the_route(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            [...$this->validPayload(), 'customer_id' => $otherCustomer->id],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame($customer->id, $response->json('data.customer_id'));
    }

    public function test_calculated_financial_fields_supplied_by_the_client_are_ignored(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            [
                ...$this->validPayload(['principal_amount' => 1000000]),
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

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
        );

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
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_creating_a_loan_for_a_nonexistent_customer_returns_404(): void
    {
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/customers/999999/loans',
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }

    public function test_loan_response_does_not_expose_unrelated_information(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/loans",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertEqualsCanonicalizing([
            'id', 'customer_id', 'reference_no', 'principal_amount', 'interest_rate',
            'interest_amount', 'total_amount', 'repayment_frequency', 'repayment_term',
            'start_date', 'due_date', 'status', 'notes', 'collaterals', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }
}
