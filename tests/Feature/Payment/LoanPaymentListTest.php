<?php

namespace Tests\Feature\Payment;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanPaymentListTest extends TestCase
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
        $permissions[] = 'data.view-all';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_list_payments(): void
    {
        Payment::factory()->count(3)->create();
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson('/api/v1/payments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.payments'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/payments');

        $response->assertStatus(401);
    }

    public function test_list_requires_payments_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/payments', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_list_can_filter_by_loan_id(): void
    {
        $loan = Loan::factory()->active()->create();
        Payment::factory()->create(['loan_id' => $loan->id]);
        Payment::factory()->create();
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson("/api/v1/payments?loan_id={$loan->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.payments'));
    }

    public function test_list_can_filter_by_payment_method(): void
    {
        Payment::factory()->create(['payment_method' => 'cash']);
        Payment::factory()->create(['payment_method' => 'bank_transfer']);
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson('/api/v1/payments?payment_method=bank_transfer', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.payments'));
    }

    public function test_list_can_filter_by_payment_date_range(): void
    {
        Payment::factory()->create(['payment_date' => '2026-01-01']);
        Payment::factory()->create(['payment_date' => '2026-06-01']);
        Payment::factory()->create(['payment_date' => '2026-12-01']);
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson(
            '/api/v1/payments?payment_date_from=2026-03-01&payment_date_to=2026-09-01',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.payments'));
        $this->assertSame('2026-06-01', $response->json('data.payments.0.payment_date'));
    }

    public function test_list_is_paginated(): void
    {
        Payment::factory()->count(3)->create();
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson('/api/v1/payments?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.payments'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }
}
