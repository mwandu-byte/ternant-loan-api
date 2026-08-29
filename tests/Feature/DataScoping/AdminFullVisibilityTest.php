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

class AdminFullVisibilityTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_sees_all_customers_including_unowned(): void
    {
        $admin = $this->adminUser();
        $agent = $this->staffUser();

        Customer::factory()->ownedBy($agent)->create();
        Customer::factory()->create();

        $response = $this->getJson('/api/v1/customers', ['Authorization' => 'Bearer '.$this->tokenFor($admin)]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.pagination.total'));
    }

    public function test_admin_can_view_any_loan_regardless_of_ownership(): void
    {
        $admin = $this->adminUser();
        $agent = $this->staffUser();
        $loan = Loan::factory()->ownedBy($agent)->create();
        $unownedLoan = Loan::factory()->create();

        $this->getJson("/api/v1/loans/{$loan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($admin)])
            ->assertStatus(200);

        $this->getJson("/api/v1/loans/{$unownedLoan->id}", ['Authorization' => 'Bearer '.$this->tokenFor($admin)])
            ->assertStatus(200);
    }

    public function test_admin_can_update_and_delete_any_customer(): void
    {
        $admin = $this->adminUser();
        $agent = $this->staffUser();
        $customer = Customer::factory()->ownedBy($agent)->create();

        $this->putJson("/api/v1/customers/{$customer->id}", ['full_name' => 'Updated By Admin'], ['Authorization' => 'Bearer '.$this->tokenFor($admin)])
            ->assertStatus(200);

        $this->deleteJson("/api/v1/customers/{$customer->id}", [], ['Authorization' => 'Bearer '.$this->tokenFor($admin)])
            ->assertStatus(200);
    }
}
