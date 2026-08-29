<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_a_customer(): void
    {
        Storage::fake('public');
        $photoPath = 'customers/to-delete.jpg';
        Storage::disk('public')->put($photoPath, 'fake-content');
        $customer = Customer::factory()->create(['photo' => $photoPath]);
        $token = $this->actingUserToken(['customers.delete']);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()->assertJson(['success' => true, 'data' => null]);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        Storage::disk('public')->assertMissing($photoPath);
    }

    public function test_user_without_delete_permission_cannot_delete_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_deleting_a_nonexistent_customer_returns_404(): void
    {
        $token = $this->actingUserToken(['customers.delete']);

        $response = $this->deleteJson('/api/v1/customers/999999', [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson(['success' => false]);
    }

    public function test_unauthenticated_user_cannot_delete_a_customer(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(401)->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }
}
