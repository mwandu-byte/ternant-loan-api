<?php

namespace Tests\Feature\Repayment;

use App\Models\Receipt;
use App\Models\Repayment;
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

    private function repaymentFor(RepaymentSchedule $schedule, array $receiptOverrides = [], array $repaymentOverrides = []): Repayment
    {
        $receipt = Receipt::factory()->create($receiptOverrides);

        return Repayment::factory()->create(array_merge([
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'receipt_id' => $receipt->id,
        ], $repaymentOverrides));
    }

    public function test_authorized_user_can_list_repayments(): void
    {
        $schedule = RepaymentSchedule::factory()->create();
        $this->repaymentFor($schedule);
        $this->repaymentFor($schedule);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('data.repayments'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/repayments');

        $response->assertStatus(401);
    }

    public function test_list_requires_repayments_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/repayments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_list_can_filter_by_loan_id(): void
    {
        $scheduleA = RepaymentSchedule::factory()->create();
        $scheduleB = RepaymentSchedule::factory()->create();
        $this->repaymentFor($scheduleA);
        $this->repaymentFor($scheduleB);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson("/api/v1/repayments?loan_id={$scheduleA->loan_id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayments'));
        $this->assertSame($scheduleA->loan_id, $response->json('data.repayments.0.loan_id'));
    }

    public function test_list_can_filter_by_repayment_schedule_id(): void
    {
        $scheduleA = RepaymentSchedule::factory()->create();
        $scheduleB = RepaymentSchedule::factory()->create();
        $this->repaymentFor($scheduleA);
        $this->repaymentFor($scheduleB);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson("/api/v1/repayments?repayment_schedule_id={$scheduleA->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayments'));
    }

    public function test_list_can_filter_by_repayment_date_range(): void
    {
        $schedule = RepaymentSchedule::factory()->create();
        $this->repaymentFor($schedule, [], ['repayment_date' => '2026-01-01']);
        $this->repaymentFor($schedule, [], ['repayment_date' => '2026-06-01']);
        $this->repaymentFor($schedule, [], ['repayment_date' => '2026-12-01']);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson(
            '/api/v1/repayments?repayment_date_from=2026-03-01&repayment_date_to=2026-09-01',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayments'));
        $this->assertSame('2026-06-01', $response->json('data.repayments.0.repayment_date'));
    }

    public function test_list_can_search_by_receipt_no(): void
    {
        $schedule = RepaymentSchedule::factory()->create();
        $this->repaymentFor($schedule, ['receipt_no' => 'RC-2026-000042']);
        $this->repaymentFor($schedule, ['receipt_no' => 'RC-2026-000099']);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments?search=000042', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayments'));
    }

    public function test_list_can_search_by_reference_no(): void
    {
        $schedule = RepaymentSchedule::factory()->create();
        $this->repaymentFor($schedule, ['reference_no' => 'MPESA-XYZ123']);
        $this->repaymentFor($schedule, ['reference_no' => 'MPESA-ABC999']);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments?search=XYZ123', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayments'));
    }

    public function test_list_is_paginated(): void
    {
        $schedule = RepaymentSchedule::factory()->create();
        $this->repaymentFor($schedule);
        $this->repaymentFor($schedule);
        $this->repaymentFor($schedule);
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.repayments'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }
}
