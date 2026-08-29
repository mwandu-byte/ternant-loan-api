<?php

namespace Tests\Feature\DataScoping;

use App\Models\Customer;
use App\Models\Loan;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

class DashboardScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    private const EXPECTED_STRUCTURE = [
        'customers' => ['total', 'active'],
        'loans' => ['total', 'active', 'pending', 'completed', 'overdue'],
        'financial' => [
            'total_amount_issued', 'total_amount_collected', 'total_outstanding_amount',
            'total_interest_generated', 'total_penalties_generated',
        ],
        'repayments' => ['total', 'today', 'overdue', 'upcoming'],
        'payments' => ['total_disbursements', 'today_disbursements'],
        'period' => ['date_from', 'date_to'],
        'loan_performance' => [
            'total_loans', 'active_loans', 'completed_loans', 'overdue_loans', 'overdue_percentage',
            'total_disbursed', 'total_collected', 'total_outstanding', 'total_interest_generated',
            'total_penalties_accrued',
        ],
        'charts' => [
            'loan_status', 'loan_performance_trend', 'collection_trend', 'disbursement_trend',
            'paid_vs_outstanding', 'penalty_trend',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_agent_dashboard_contains_only_agent_scoped_metrics(): void
    {
        $agent = $this->staffUser();
        $otherAgent = $this->staffUser();

        Customer::factory()->ownedBy($agent)->create();
        Customer::factory()->ownedBy($otherAgent)->create();
        Loan::factory()->ownedBy($agent)->create();
        Loan::factory()->ownedBy($otherAgent)->create();
        Loan::factory()->create();

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$this->tokenFor($agent)]);

        $response->assertStatus(200)->assertJsonStructure(['data' => self::EXPECTED_STRUCTURE]);
        $this->assertSame(1, $response->json('data.customers.total'));
        $this->assertSame(1, $response->json('data.loans.total'));
    }

    public function test_manager_dashboard_contains_organization_wide_metrics(): void
    {
        $manager = $this->managerUser();
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        $customerA = Customer::factory()->ownedBy($agentA)->create();
        $customerB = Customer::factory()->ownedBy($agentB)->create();
        // customer_id is pinned explicitly on every loan below so none of
        // them incidentally creates its own extra Customer via LoanFactory's
        // nested default, which would inflate customers.total for an
        // unrestricted (manager/admin) caller.
        Loan::factory()->ownedBy($agentA)->create(['customer_id' => $customerA->id]);
        Loan::factory()->ownedBy($agentB)->create(['customer_id' => $customerB->id]);
        Loan::factory()->create(['customer_id' => $customerA->id]);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$this->tokenFor($manager)]);

        $response->assertStatus(200)->assertJsonStructure(['data' => self::EXPECTED_STRUCTURE]);
        $this->assertSame(2, $response->json('data.customers.total'));
        $this->assertSame(3, $response->json('data.loans.total'));
    }

    public function test_admin_dashboard_contains_organization_wide_metrics(): void
    {
        $admin = $this->adminUser();
        $agent = $this->staffUser();

        $ownedCustomer = Customer::factory()->ownedBy($agent)->create();
        $unownedCustomer = Customer::factory()->create();
        Loan::factory()->ownedBy($agent)->create(['customer_id' => $ownedCustomer->id]);
        Loan::factory()->create(['customer_id' => $unownedCustomer->id]);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$this->tokenFor($admin)]);

        $response->assertStatus(200)->assertJsonStructure(['data' => self::EXPECTED_STRUCTURE]);
        $this->assertSame(2, $response->json('data.customers.total'));
        $this->assertSame(2, $response->json('data.loans.total'));
    }
}
