<?php

namespace Tests\Feature\DataScoping;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

/**
 * The seeded `staff` role does not currently carry `reports.view` at all
 * (an existing, pre-scoping product decision — out of scope for this
 * change), so these tests exercise the scoping mechanism itself via an
 * ad hoc role that holds `reports.view` without `data.view-all`, rather
 * than the real `staff` role.
 */
class ReportScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function scopedReporterUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'test-reporter-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::where('name', 'reports.view')->first());

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_loan_portfolio_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        Loan::factory()->ownedBy($reporter)->create();
        Loan::factory()->ownedBy($otherAgent)->create();

        $this->getJson('/api/v1/reports/loan-portfolio', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.total_loans', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/loan-portfolio', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.total_loans', 2);
    }

    public function test_customer_report_is_scoped_by_customer_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        Customer::factory()->ownedBy($reporter)->create();
        Customer::factory()->ownedBy($otherAgent)->create();

        $this->getJson('/api/v1/reports/customers', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.total_customers', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/customers', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.total_customers', 2);
    }

    public function test_disbursement_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create();
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create();
        Payment::factory()->create(['loan_id' => $ownLoan->id]);
        Payment::factory()->create(['loan_id' => $otherLoan->id]);

        $this->getJson('/api/v1/reports/disbursements', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_disbursements', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/disbursements', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_disbursements', 2);
    }

    public function test_collection_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create();
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create();
        Repayment::factory()->create(['loan_id' => $ownLoan->id]);
        Repayment::factory()->create(['loan_id' => $otherLoan->id]);

        $this->getJson('/api/v1/reports/collections', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_repayments', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/collections', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_repayments', 2);
    }

    public function test_outstanding_loans_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create(['status' => 'active']);
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create(['status' => 'active']);
        RepaymentSchedule::factory()->create(['loan_id' => $ownLoan->id, 'outstanding_amount' => 500]);
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id, 'outstanding_amount' => 500]);

        $this->getJson('/api/v1/reports/outstanding-loans', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_outstanding_loans', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/outstanding-loans', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_outstanding_loans', 2);
    }

    public function test_overdue_loans_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create(['status' => 'active']);
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create(['status' => 'active']);
        RepaymentSchedule::factory()->overdue()->create(['loan_id' => $ownLoan->id]);
        RepaymentSchedule::factory()->overdue()->create(['loan_id' => $otherLoan->id]);

        $this->getJson('/api/v1/reports/overdue-loans', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.overdue_loans_count', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/overdue-loans', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.overdue_loans_count', 2);
    }

    public function test_penalty_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create();
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create();
        Penalty::factory()->create(['loan_id' => $ownLoan->id]);
        Penalty::factory()->create(['loan_id' => $otherLoan->id]);

        $this->getJson('/api/v1/reports/penalties', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_penalized_loans', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/penalties', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.summary.number_of_penalized_loans', 2);
    }

    public function test_cash_flow_report_is_scoped_by_loan_ownership(): void
    {
        $reporter = $this->scopedReporterUser();
        $otherAgent = $this->staffUser();
        $manager = $this->managerUser();

        $ownLoan = Loan::factory()->ownedBy($reporter)->create();
        $otherLoan = Loan::factory()->ownedBy($otherAgent)->create();
        Payment::factory()->create(['loan_id' => $ownLoan->id]);
        Payment::factory()->create(['loan_id' => $otherLoan->id]);

        $this->getJson('/api/v1/reports/cash-flow', ['Authorization' => 'Bearer '.$this->tokenFor($reporter)])
            ->assertStatus(200)->assertJsonPath('data.pagination.total', 1);

        $this->switchAuthenticatedUser();

        $this->getJson('/api/v1/reports/cash-flow', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)->assertJsonPath('data.pagination.total', 2);
    }
}
