<?php

namespace Tests\Feature\DataScoping;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Repayment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

class ManagerFullVisibilityTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_manager_sees_all_customers_including_unowned(): void
    {
        $manager = $this->managerUser();
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        Customer::factory()->ownedBy($agentA)->create();
        Customer::factory()->ownedBy($agentB)->create();
        Customer::factory()->create();

        $response = $this->getJson('/api/v1/customers', ['Authorization' => 'Bearer '.$this->tokenFor($manager)]);

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_manager_sees_all_loans_including_unowned(): void
    {
        $manager = $this->managerUser();
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        $loanA = Loan::factory()->ownedBy($agentA)->create();
        Loan::factory()->ownedBy($agentB)->create();
        Loan::factory()->create();

        $this->getJson('/api/v1/loans', ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 3);

        $this->getJson("/api/v1/loans/{$loanA->id}", ['Authorization' => 'Bearer '.$this->tokenFor($manager)])
            ->assertStatus(200);
    }

    public function test_manager_can_view_agent_performance_via_dashboard(): void
    {
        $manager = $this->managerUser();
        $agent = $this->staffUser();
        $loan = Loan::factory()->ownedBy($agent)->create(['status' => 'active']);
        Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $agent->id]);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$this->tokenFor($manager)]);

        $response->assertStatus(200);
        // Loan counts are covered by the loan-scoping tests elsewhere;
        // this test's own focus is that the manager's dashboard reflects
        // an agent's recorded collection activity.
        $this->assertSame(1, $response->json('data.repayments.total'));
    }
}
