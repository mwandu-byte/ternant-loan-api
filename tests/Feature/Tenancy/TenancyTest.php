<?php

namespace Tests\Feature\Tenancy;

use App\Models\ApplicationFee;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\InterestRule;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        InterestRule::factory()->create(['minimum_amount' => 0, 'maximum_amount' => 4000000, 'interest_rate' => 22, 'status' => 'active']);
        RepaymentFrequency::factory()->create(['name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active']);
        RepaymentTerm::factory()->create(['name' => '12 Months', 'value' => 12, 'unit' => 'months', 'status' => 'active']);
    }

    private function userFor(?Business $business, string $role = 'manager'): User
    {
        $user = User::factory()->create(['business_id' => $business?->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    /** @return array<string, mixed> */
    private function loanPayload(Customer $customer, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'principal_amount' => 1000000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 12,
            'start_date' => '2026-01-01',
        ], $extra);
    }

    public function test_business_users_only_see_their_own_customers_and_loans(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $customerA = Customer::factory()->create(['business_id' => $a->id]);
        $customerB = Customer::factory()->create(['business_id' => $b->id]);
        $loanB = Loan::factory()->create(['customer_id' => $customerB->id, 'business_id' => $b->id]);
        $managerA = $this->userFor($a);

        $ids = collect($this->getJson('/api/v1/customers', $this->as($managerA))->json('data.customers'))->pluck('id');
        $this->assertSame([$customerA->id], $ids->all());

        $this->getJson("/api/v1/customers/{$customerB->id}", $this->as($managerA))->assertForbidden();
        $this->getJson("/api/v1/loans/{$loanB->id}", $this->as($managerA))->assertForbidden();
        $this->putJson("/api/v1/loans/{$loanB->id}", ['notes' => 'x'], $this->as($managerA))->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/loans', $this->as($managerA))->json('data.loans'));
    }

    public function test_platform_user_sees_every_business(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        Customer::factory()->create(['business_id' => $a->id]);
        Customer::factory()->create(['business_id' => $b->id]);

        $this->getJson('/api/v1/customers', $this->as($this->userFor(null, 'admin')))
            ->assertOk()->assertJsonPath('data.pagination.total', 2);
    }

    public function test_business_user_cannot_create_a_loan_for_another_businesss_customer(): void
    {
        $a = Business::factory()->create();
        $customerB = Customer::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->postJson('/api/v1/loans', $this->loanPayload($customerB), $this->as($this->userFor($a)))
            ->assertStatus(422);
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_customers_created_by_a_business_user_belong_to_their_business_even_if_another_is_supplied(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();

        $this->postJson('/api/v1/customers', [
            'business_id' => $b->id,
            'full_name' => 'Jane', 'phone' => '+255711000001', 'identification_type' => 'NIDA',
            'identification_number' => '111', 'address' => 'Dar',
        ], $this->as($this->userFor($a)))->assertCreated();

        $this->assertSame($a->id, Customer::first()->business_id);
    }

    public function test_the_same_phone_can_be_registered_in_two_businesses(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $payload = ['full_name' => 'Jane', 'phone' => '+255711000002', 'identification_type' => 'NIDA', 'identification_number' => '222', 'address' => 'Dar'];

        $this->postJson('/api/v1/customers', $payload, $this->as($this->userFor($a)))->assertCreated();
        $this->postJson('/api/v1/customers', $payload, $this->as($this->userFor($b)))->assertCreated();
        $this->postJson('/api/v1/customers', $payload, $this->as($this->userFor($a)))->assertStatus(422);
    }

    public function test_users_are_scoped_to_the_business(): void
    {
        $a = Business::factory()->create();
        $adminA = $this->userFor($a, 'admin');
        $userB = $this->userFor(Business::factory()->create(), 'staff');

        $emails = collect($this->getJson('/api/v1/users', $this->as($adminA))->json('data.users'))->pluck('email');
        $this->assertNotContains($userB->email, $emails->all());

        $this->getJson("/api/v1/users/{$userB->id}", $this->as($adminA))->assertForbidden();
        $this->deleteJson("/api/v1/users/{$userB->id}", [], $this->as($adminA))->assertForbidden();

        $this->postJson('/api/v1/users', [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'business_id' => $userB->business_id,
        ], $this->as($adminA))->assertCreated();
        $this->assertSame($a->id, User::where('email', 'new@example.com')->first()->business_id);
    }

    public function test_only_platform_users_manage_businesses(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $adminA = $this->userFor($a, 'admin');

        $this->getJson('/api/v1/businesses', $this->as($adminA))->assertForbidden();
        $this->postJson('/api/v1/businesses', ['name' => 'X'], $this->as($adminA))->assertForbidden();
        $this->putJson("/api/v1/businesses/{$a->id}", ['requires_guarantor' => true], $this->as($adminA))->assertForbidden();
        $this->getJson("/api/v1/businesses/{$b->id}", $this->as($adminA))->assertForbidden();
        $this->getJson('/api/v1/business', $this->as($adminA))->assertOk()->assertJsonPath('data.id', $a->id);

        $platform = $this->userFor(null, 'admin');
        $this->postJson('/api/v1/businesses', ['name' => 'Acme', 'requires_guarantor' => true], $this->as($platform))
            ->assertCreated()->assertJsonPath('data.requires_guarantor', true);
        $this->putJson("/api/v1/businesses/{$a->id}", ['requires_application_fee' => true], $this->as($platform))->assertOk();
        $this->assertTrue($a->fresh()->requires_application_fee);
    }

    public function test_suspended_business_users_cannot_log_in(): void
    {
        $business = Business::factory()->suspended()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'email' => 'sus@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'sus@example.com', 'password' => 'password'])->assertUnauthorized();
    }

    public function test_guarantor_is_required_only_when_the_business_requires_it(): void
    {
        $required = Business::factory()->requiringGuarantor()->create();
        $optional = Business::factory()->create();
        $customerR = Customer::factory()->create(['business_id' => $required->id]);
        $customerO = Customer::factory()->create(['business_id' => $optional->id]);

        $this->postJson('/api/v1/loans', $this->loanPayload($customerR), $this->as($this->userFor($required)))
            ->assertStatus(422)->assertJsonValidationErrors('guarantors');
        $this->postJson('/api/v1/loans', $this->loanPayload($customerO), $this->as($this->userFor($optional)))
            ->assertCreated();
    }

    public function test_loan_supports_multiple_guarantors_including_an_existing_customer(): void
    {
        $business = Business::factory()->requiringGuarantor()->create();
        $borrower = Customer::factory()->create(['business_id' => $business->id]);
        $friend = Customer::factory()->create(['business_id' => $business->id]);

        $response = $this->postJson('/api/v1/loans', $this->loanPayload($borrower, ['guarantors' => [
            ['guarantor_customer_id' => $friend->id, 'relationship' => 'friend'],
            ['full_name' => 'Bob', 'phone' => '+255700000001', 'identification_type' => 'NIDA', 'identification_number' => '999', 'relationship' => 'brother'],
        ]]), $this->as($this->userFor($business)));

        $response->assertCreated()->assertJsonCount(2, 'data.guarantors');
        $this->assertContains($friend->full_name, collect($response->json('data.guarantors'))->pluck('full_name')->all());
        $this->assertNull(Guarantor::whereNotNull('guarantor_customer_id')->first()->full_name);
    }

    public function test_borrower_and_other_business_customers_cannot_be_guarantors(): void
    {
        $business = Business::factory()->requiringGuarantor()->create();
        $borrower = Customer::factory()->create(['business_id' => $business->id]);
        $outsider = Customer::factory()->create(['business_id' => Business::factory()->create()->id]);
        $user = $this->userFor($business);

        foreach ([$borrower, $outsider] as $bad) {
            $this->postJson('/api/v1/loans', $this->loanPayload($borrower, ['guarantors' => [
                ['guarantor_customer_id' => $bad->id, 'relationship' => 'friend'],
            ]]), $this->as($user))->assertStatus(422);
        }
    }

    public function test_guarantor_endpoints_are_tenant_scoped_and_keep_the_minimum(): void
    {
        $a = Business::factory()->requiringGuarantor()->create();
        $b = Business::factory()->create();
        $loanA = Loan::factory()->create(['customer_id' => Customer::factory()->create(['business_id' => $a->id])->id, 'business_id' => $a->id]);
        $loanB = Loan::factory()->create(['customer_id' => Customer::factory()->create(['business_id' => $b->id])->id, 'business_id' => $b->id]);
        $guarantorA = Guarantor::factory()->create(['loan_id' => $loanA->id, 'business_id' => $a->id]);
        Guarantor::factory()->create(['loan_id' => $loanB->id, 'business_id' => $b->id]);
        $managerA = $this->userFor($a);

        $this->getJson("/api/v1/loans/{$loanB->id}/guarantors", $this->as($managerA))->assertForbidden();
        $this->getJson("/api/v1/loans/{$loanA->id}/guarantors", $this->as($managerA))->assertOk()->assertJsonCount(1, 'data.guarantors');
        $this->deleteJson("/api/v1/loans/{$loanA->id}/guarantors/{$guarantorA->id}", [], $this->as($managerA))->assertStatus(422);

        $this->postJson("/api/v1/loans/{$loanA->id}/guarantors", ['full_name' => 'Zed', 'phone' => '+255700000002', 'identification_type' => 'NIDA', 'identification_number' => '5', 'relationship' => 'friend'], $this->as($managerA))->assertCreated();
        $this->deleteJson("/api/v1/loans/{$loanA->id}/guarantors/{$guarantorA->id}", [], $this->as($managerA))->assertOk();
    }

    public function test_application_fee_must_be_paid_and_is_consumed_by_one_loan(): void
    {
        $business = Business::factory()->requiringApplicationFee()->create();
        $customer = Customer::factory()->create(['business_id' => $business->id]);
        $user = $this->userFor($business);

        $this->postJson('/api/v1/loans', $this->loanPayload($customer), $this->as($user))
            ->assertStatus(422)->assertJsonValidationErrors('application_fee');

        $this->postJson("/api/v1/customers/{$customer->id}/application-fees", ['amount' => 5000, 'status' => 'pending'], $this->as($user))->assertCreated();
        $this->postJson('/api/v1/loans', $this->loanPayload($customer), $this->as($user))->assertStatus(422);

        $this->postJson("/api/v1/customers/{$customer->id}/application-fees", ['amount' => 5000, 'payment_method' => 'cash', 'reference_no' => 'R1'], $this->as($user))
            ->assertCreated()->assertJsonPath('data.status', 'paid');

        $loanId = $this->postJson('/api/v1/loans', $this->loanPayload($customer), $this->as($user))
            ->assertCreated()->assertJsonPath('data.application_fee.reference_no', 'R1')->json('data.id');
        $this->assertSame($loanId, ApplicationFee::where('reference_no', 'R1')->first()->loan_id);

        $this->postJson('/api/v1/loans', $this->loanPayload($customer), $this->as($user))->assertStatus(422);
    }

    public function test_application_fees_are_tenant_scoped(): void
    {
        $a = Business::factory()->create();
        $b = Business::factory()->create();
        $customerB = Customer::factory()->create(['business_id' => $b->id]);
        $feeB = ApplicationFee::factory()->create(['customer_id' => $customerB->id, 'business_id' => $b->id]);
        $managerA = $this->userFor($a);

        $this->getJson("/api/v1/application-fees/{$feeB->id}", $this->as($managerA))->assertForbidden();
        $this->getJson("/api/v1/customers/{$customerB->id}/application-fees", $this->as($managerA))->assertForbidden();
        $this->postJson("/api/v1/customers/{$customerB->id}/application-fees", ['amount' => 1], $this->as($managerA))->assertForbidden();
    }

    public function test_repayments_cannot_target_another_businesss_loan(): void
    {
        $b = Business::factory()->create();
        $loanB = Loan::factory()->active()->create(['customer_id' => Customer::factory()->create(['business_id' => $b->id])->id, 'business_id' => $b->id]);

        $this->postJson('/api/v1/repayments', [
            'loan_id' => $loanB->id, 'amount' => 100, 'repayment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ], $this->as($this->userFor(Business::factory()->create())))->assertStatus(422);
    }
}
