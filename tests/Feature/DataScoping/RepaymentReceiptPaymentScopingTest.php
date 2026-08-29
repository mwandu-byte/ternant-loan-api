<?php

namespace Tests\Feature\DataScoping;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\Repayment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

class RepaymentReceiptPaymentScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_agent_sees_repayment_they_recorded_even_on_a_loan_they_do_not_own(): void
    {
        $recordingAgent = $this->staffUser();
        $loanOwner = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $repayment = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $recordingAgent->id]);

        $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($recordingAgent)])
            ->assertStatus(200);
    }

    public function test_agent_sees_repayment_on_a_loan_they_own_even_if_recorded_by_another_agent(): void
    {
        $loanOwner = $this->staffUser();
        $recordingAgent = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $repayment = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $recordingAgent->id]);

        $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($loanOwner)])
            ->assertStatus(200);
    }

    public function test_agent_cannot_see_repayment_when_neither_recorder_nor_loan_owner(): void
    {
        $loanOwner = $this->staffUser();
        $recordingAgent = $this->staffUser();
        $bystander = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $repayment = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $recordingAgent->id]);

        $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($bystander)])
            ->assertStatus(403);
    }

    public function test_agent_sees_payment_they_disbursed_even_on_a_loan_they_do_not_own(): void
    {
        $disbursingAgent = $this->staffUser();
        $loanOwner = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $payment = Payment::factory()->create(['loan_id' => $loan->id, 'paid_by' => $disbursingAgent->id]);

        $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($disbursingAgent)])
            ->assertStatus(200);
    }

    public function test_agent_sees_payment_on_a_loan_they_own_even_if_disbursed_by_another_agent(): void
    {
        $loanOwner = $this->staffUser();
        $disbursingAgent = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $payment = Payment::factory()->create(['loan_id' => $loan->id, 'paid_by' => $disbursingAgent->id]);

        $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($loanOwner)])
            ->assertStatus(200);
    }

    public function test_agent_cannot_see_payment_when_neither_disburser_nor_loan_owner(): void
    {
        $loanOwner = $this->staffUser();
        $disbursingAgent = $this->staffUser();
        $bystander = $this->staffUser();
        $loan = Loan::factory()->ownedBy($loanOwner)->create();
        $payment = Payment::factory()->create(['loan_id' => $loan->id, 'paid_by' => $disbursingAgent->id]);

        $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => 'Bearer '.$this->tokenFor($bystander)])
            ->assertStatus(403);
    }
}
