<?php

namespace Tests\Feature\DataScoping;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Repayment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

/**
 * Proves that swapping an ID in the URL cannot bypass access control
 * (spec section 21), and that the resulting 403 is indistinguishable in
 * shape from an ordinary missing-permission response — so a restricted
 * record's existence is never leaked — while a genuinely nonexistent ID
 * still resolves to the normal, unrelated 404.
 */
class DirectIdManipulationTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_swapping_a_customer_id_in_the_url_cannot_bypass_access_control(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $customerB = Customer::factory()->ownedBy($agentB)->create();

        $response = $this->getJson("/api/v1/customers/{$customerB->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_swapping_a_loan_id_in_the_url_cannot_bypass_access_control(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loanB = Loan::factory()->ownedBy($agentB)->create();

        $this->getJson("/api/v1/loans/{$loanB->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)])
            ->assertStatus(403);

        $this->putJson("/api/v1/loans/{$loanB->id}", ['notes' => 'hijacked'], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)])
            ->assertStatus(403);

        $this->deleteJson("/api/v1/loans/{$loanB->id}", [], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)])
            ->assertStatus(403);
    }

    public function test_swapping_a_repayment_id_in_the_url_cannot_bypass_access_control(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loanB = Loan::factory()->ownedBy($agentB)->create();
        $repayment = Repayment::factory()->create(['loan_id' => $loanB->id, 'received_by' => $agentB->id]);

        $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)])
            ->assertStatus(403);
    }

    public function test_swapping_a_payment_id_in_the_url_cannot_bypass_access_control(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loanB = Loan::factory()->ownedBy($agentB)->create();
        $payment = Payment::factory()->create(['loan_id' => $loanB->id, 'paid_by' => $agentB->id]);

        $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)])
            ->assertStatus(403);
    }

    public function test_forbidden_response_and_not_found_response_stay_distinct_shapes_but_both_hide_nothing(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $restrictedCustomer = Customer::factory()->ownedBy($agentB)->create();

        $forbidden = $this->getJson("/api/v1/customers/{$restrictedCustomer->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);
        $notFound = $this->getJson('/api/v1/customers/999999', ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $forbidden->assertStatus(403);
        $notFound->assertStatus(404);
        $this->assertArrayNotHasKey('data', $forbidden->json());

        // Neither response body reveals anything about the restricted
        // customer's actual data (name, phone, etc.).
        $this->assertStringNotContainsString($restrictedCustomer->full_name, $forbidden->getContent());
    }
}
