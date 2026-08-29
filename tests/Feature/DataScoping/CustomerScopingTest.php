<?php

namespace Tests\Feature\DataScoping;

use App\Models\Customer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesScopedUsers;
use Tests\TestCase;

class CustomerScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_agent_lists_only_own_customers(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        Customer::factory()->ownedBy($agentA)->create(['full_name' => 'Owned By A']);
        Customer::factory()->ownedBy($agentB)->create(['full_name' => 'Owned By B']);
        Customer::factory()->create(['full_name' => 'Unowned Legacy Record']);

        $response = $this->getJson('/api/v1/customers', ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('Owned By A', $response->json('data.customers.0.full_name'));
    }

    public function test_agent_can_view_own_customer(): void
    {
        $agent = $this->staffUser();
        $customer = Customer::factory()->ownedBy($agent)->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agent)]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_agent_cannot_view_another_agents_customer(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $customer = Customer::factory()->ownedBy($agentB)->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_view_customer_with_no_owner(): void
    {
        $agent = $this->staffUser();
        $customer = Customer::factory()->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agent)]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_update_another_agents_customer(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $customer = Customer::factory()->ownedBy($agentB)->create();

        $response = $this->putJson("/api/v1/customers/{$customer->id}", ['full_name' => 'Changed'], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id, 'full_name' => 'Changed']);
    }

    public function test_agent_cannot_delete_another_agents_customer(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $customer = Customer::factory()->ownedBy($agentB)->create();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_created_by_is_always_the_authenticated_agent_regardless_of_payload(): void
    {
        $agent = $this->staffUser();
        $otherAgent = $this->staffUser();

        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'John Doe',
            'phone' => '0712345678',
            'identification_type' => 'NIDA',
            'identification_number' => '19900101-12345-00001-23',
            'address' => 'Dar es Salaam',
            'created_by' => $otherAgent->id,
        ], ['Authorization' => 'Bearer '.$this->tokenFor($agent)]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customers', ['full_name' => 'John Doe', 'created_by' => $agent->id]);
        $this->assertDatabaseMissing('customers', ['full_name' => 'John Doe', 'created_by' => $otherAgent->id]);
    }
}
