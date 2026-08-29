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

class LoanScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_agent_lists_only_permitted_loans(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        Loan::factory()->ownedBy($agentA)->create();
        Loan::factory()->ownedBy($agentB)->create();
        Loan::factory()->create();

        $response = $this->getJson('/api/v1/loans', ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_agent_cannot_view_another_agents_restricted_loan(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loan = Loan::factory()->ownedBy($agentB)->create();

        $response = $this->getJson("/api/v1/loans/{$loan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_update_another_agents_restricted_loan(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loan = Loan::factory()->ownedBy($agentB)->create(['status' => 'pending']);

        $response = $this->putJson("/api/v1/loans/{$loan->id}", ['notes' => 'changed'], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_delete_another_agents_restricted_loan(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $loan = Loan::factory()->ownedBy($agentB)->create(['status' => 'pending']);

        $response = $this->deleteJson("/api/v1/loans/{$loan->id}", [], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('loans', ['id' => $loan->id]);
    }

    public function test_loan_ownership_is_independent_of_customer_ownership(): void
    {
        $registeringAgent = $this->staffUser();
        $processingAgent = $this->staffUser();

        $customer = Customer::factory()->ownedBy($registeringAgent)->create();
        $loan = Loan::factory()->ownedBy($processingAgent)->create(['customer_id' => $customer->id]);

        $this->getJson("/api/v1/loans/{$loan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($processingAgent)])
            ->assertStatus(200);

        $this->switchAuthenticatedUser();

        $this->getJson("/api/v1/loans/{$loan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($registeringAgent)])
            ->assertStatus(403);
    }

    public function test_agent_cannot_view_loan_with_no_owner(): void
    {
        $agent = $this->staffUser();
        $loan = Loan::factory()->create();

        $response = $this->getJson("/api/v1/loans/{$loan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agent)]);

        $response->assertStatus(403);
    }
}
