<?php

namespace Tests\Feature\DataScoping;

use App\Models\Loan;
use App\Models\Penalty;
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

class RepaymentScheduleAndPenaltyScopingTest extends TestCase
{
    use CreatesScopedUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    /**
     * The seeded `staff` role does not currently carry `penalties.view`
     * at all (an existing, pre-scoping product decision, out of scope
     * for this change) — this builds a user with just that permission,
     * without `data.view-all`, to exercise the scoping mechanism itself.
     */
    private function scopedPenaltyViewer(): User
    {
        $role = Role::firstOrCreate(['name' => 'test-penalty-viewer-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::where('name', 'penalties.view')->first());

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_agent_lists_only_schedules_for_owned_loans(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();

        $ownLoan = Loan::factory()->ownedBy($agentA)->create();
        $otherLoan = Loan::factory()->ownedBy($agentB)->create();
        RepaymentSchedule::factory()->create(['loan_id' => $ownLoan->id]);
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id]);

        $response = $this->getJson('/api/v1/repayment-schedules', ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_agent_cannot_list_another_agents_loan_schedule_via_nested_route(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $otherLoan = Loan::factory()->ownedBy($agentB)->create();
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id]);

        $response = $this->getJson("/api/v1/loans/{$otherLoan->id}/repayment-schedules", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_view_a_schedule_installment_for_another_agents_loan(): void
    {
        $agentA = $this->staffUser();
        $agentB = $this->staffUser();
        $otherLoan = Loan::factory()->ownedBy($agentB)->create();
        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id]);

        $response = $this->getJson("/api/v1/repayment-schedules/{$schedule->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }

    public function test_agent_lists_only_penalties_for_owned_loans(): void
    {
        $agentA = $this->scopedPenaltyViewer();
        $agentB = $this->staffUser();

        $ownLoan = Loan::factory()->ownedBy($agentA)->create();
        $otherLoan = Loan::factory()->ownedBy($agentB)->create();
        Penalty::factory()->create(['loan_id' => $ownLoan->id]);
        Penalty::factory()->create(['loan_id' => $otherLoan->id]);

        $response = $this->getJson('/api/v1/penalties', ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_agent_cannot_view_a_penalty_for_another_agents_loan(): void
    {
        $agentA = $this->scopedPenaltyViewer();
        $agentB = $this->staffUser();
        $otherLoan = Loan::factory()->ownedBy($agentB)->create();
        $penalty = Penalty::factory()->create(['loan_id' => $otherLoan->id]);

        $response = $this->getJson("/api/v1/penalties/{$penalty->id}", ['Authorization' => 'Bearer '.$this->tokenFor($agentA)]);

        $response->assertStatus(403);
    }
}
