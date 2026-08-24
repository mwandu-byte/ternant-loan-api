<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanListTest extends TestCase
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

    public function test_authorized_user_can_list_loans(): void
    {
        Loan::factory()->count(3)->create();
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson('/api/v1/loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.loans'));
    }

    public function test_unauthenticated_request_cannot_list_loans(): void
    {
        $response = $this->getJson('/api/v1/loans');

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_view_permission_cannot_list_loans(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_customer_id_filter_only_returns_records_for_the_given_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        Loan::factory()->create(['customer_id' => $customer->id]);
        Loan::factory()->create(['customer_id' => $otherCustomer->id]);
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            "/api/v1/loans?customer_id={$customer->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.loans'));
        $this->assertSame($customer->id, $response->json('data.loans.0.customer_id'));
    }

    public function test_invalid_customer_id_filter_is_rejected(): void
    {
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            '/api/v1/loans?customer_id=999999',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    public function test_search_matches_reference_no(): void
    {
        $loan = Loan::factory()->create(['reference_no' => 'LN-2026-000042']);
        Loan::factory()->create(['reference_no' => 'LN-2026-000099']);
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            '/api/v1/loans?search=000042',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.loans'));
        $this->assertSame($loan->reference_no, $response->json('data.loans.0.reference_no'));
    }

    public function test_status_filter_returns_only_matching_records(): void
    {
        Loan::factory()->create(['status' => 'pending']);
        Loan::factory()->active()->create();
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            '/api/v1/loans?status=active',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.loans'));
        $this->assertSame('active', $response->json('data.loans.0.status'));
    }

    public function test_customer_id_and_status_filters_can_be_combined(): void
    {
        $customer = Customer::factory()->create();
        Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        Loan::factory()->active()->create(['customer_id' => $customer->id]);
        Loan::factory()->active()->create();
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            "/api/v1/loans?customer_id={$customer->id}&status=active",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.loans'));
        $this->assertSame($customer->id, $response->json('data.loans.0.customer_id'));
        $this->assertSame('active', $response->json('data.loans.0.status'));
    }

    public function test_listing_loans_is_paginated(): void
    {
        Loan::factory()->count(5)->create();
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            '/api/v1/loans?page=1&per_page=2',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.loans'));
        $this->assertSame(5, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
    }
}
