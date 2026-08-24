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

class RepaymentShowTest extends TestCase
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

    private function repayment(): Repayment
    {
        $schedule = RepaymentSchedule::factory()->create();
        $receipt = Receipt::factory()->create();

        return Repayment::factory()->create([
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'receipt_id' => $receipt->id,
        ]);
    }

    public function test_authorized_user_can_view_a_repayment(): void
    {
        $repayment = $this->repayment();
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $repayment->id],
        ]);
    }

    public function test_show_includes_nested_receipt_loan_and_repayment_schedule(): void
    {
        $repayment = $this->repayment();
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame($repayment->receipt_id, $response->json('data.receipt.id'));
        $this->assertSame($repayment->loan_id, $response->json('data.loan.id'));
        $this->assertSame($repayment->repayment_schedule_id, $response->json('data.repayment_schedule.id'));
    }

    public function test_show_requires_authentication(): void
    {
        $repayment = $this->repayment();

        $response = $this->getJson("/api/v1/repayments/{$repayment->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_repayments_view_permission(): void
    {
        $repayment = $this->repayment();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/repayments/{$repayment->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_repayment(): void
    {
        $token = $this->actingUserToken(['repayments.view']);

        $response = $this->getJson('/api/v1/repayments/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Repayment not found.',
        ]);
    }
}
