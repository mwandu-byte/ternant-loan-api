<?php

namespace Tests\Feature\Payment;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanPaymentShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_payment(): void
    {
        $payment = Payment::factory()->create();
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $payment->id],
        ]);
    }

    public function test_show_includes_nested_loan(): void
    {
        $payment = Payment::factory()->create();
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($payment->loan_id, $response->json('data.loan.id'));
    }

    public function test_show_requires_authentication(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->getJson("/api/v1/payments/{$payment->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_payments_view_permission(): void
    {
        $payment = Payment::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/payments/{$payment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_payment(): void
    {
        $token = $this->actingUserToken(['payments.view']);

        $response = $this->getJson('/api/v1/payments/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Payment not found.',
        ]);
    }
}
