<?php

namespace Tests\Feature\Repayment;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingUserToken(array $permissions): string
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_list_all_repayment_schedules(): void
    {
        RepaymentSchedule::factory()->count(3)->create();
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.repayment_schedules'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/repayments');

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_list_requires_repayments_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/repayments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_list_can_filter_by_loan_id(): void
    {
        $loan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 1]);
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 2]);
        RepaymentSchedule::factory()->create(['installment_number' => 1]);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson("/api/v1/repayments?loan_id={$loan->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.repayment_schedules'));
    }

    public function test_list_can_filter_by_status(): void
    {
        RepaymentSchedule::factory()->paid()->create();
        RepaymentSchedule::factory()->create(['status' => 'pending']);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments?status=paid', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayment_schedules'));
        $this->assertSame('paid', $response->json('data.repayment_schedules.0.status'));
    }

    public function test_list_can_filter_by_due_date_range(): void
    {
        RepaymentSchedule::factory()->create(['due_date' => '2026-01-01']);
        RepaymentSchedule::factory()->create(['due_date' => '2026-06-01']);
        RepaymentSchedule::factory()->create(['due_date' => '2026-12-01']);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson(
            '/api/v1/repayments?due_date_from=2026-03-01&due_date_to=2026-09-01',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayment_schedules'));
        $this->assertSame('2026-06-01', $response->json('data.repayment_schedules.0.due_date'));
    }

    public function test_list_is_paginated(): void
    {
        RepaymentSchedule::factory()->count(3)->create();
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.repayment_schedules'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));
    }
}
