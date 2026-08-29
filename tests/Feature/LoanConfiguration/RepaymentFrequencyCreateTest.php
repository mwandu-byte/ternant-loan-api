<?php

namespace Tests\Feature\LoanConfiguration;

use App\Models\RepaymentFrequency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentFrequencyCreateTest extends TestCase
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

    public function test_authorized_user_can_create_a_repayment_frequency(): void
    {
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('active', $response->json('data.status'));
        $this->assertDatabaseHas('repayment_frequencies', ['code' => 'monthly', 'status' => 'active']);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        RepaymentFrequency::factory()->create(['code' => 'monthly']);
        $token = $this->actingUserToken(['loan-configurations.create']);

        $response = $this->postJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['name' => 'Monthly Again', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_unauthenticated_request_cannot_create_a_repayment_frequency(): void
    {
        $response = $this->postJson('/api/v1/loan-configurations/repayment-frequencies', [
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_create_permission_cannot_create_a_repayment_frequency(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            '/api/v1/loan-configurations/repayment-frequencies',
            ['name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403);
    }
}
