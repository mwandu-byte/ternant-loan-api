<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_a_customer(): void
    {
        $customer = Customer::factory()->create(['full_name' => 'Old Name']);
        $token = $this->actingUserToken(['customers.update']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'full_name' => 'New Name',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('New Name', $response->json('data.full_name'));
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'full_name' => 'New Name']);
    }

    public function test_user_without_update_permission_cannot_update_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'full_name' => 'New Name',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_updating_a_customer_with_its_own_unchanged_phone_and_identification_number_succeeds(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '+255712345678',
            'identification_type' => 'NIDA',
            'identification_number' => '19900101-12345-00001-23',
        ]);
        $token = $this->actingUserToken(['customers.update']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'phone' => '0712345678',
            'identification_type' => 'NIDA',
            'identification_number' => '19900101-12345-00001-23',
            'address' => 'Updated Address',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertSame('Updated Address', $response->json('data.address'));
    }

    public function test_updating_a_customer_rejects_phone_already_used_by_another_customer(): void
    {
        Customer::factory()->create(['phone' => '+255700000001']);
        $customer = Customer::factory()->create(['phone' => '+255700000002']);
        $token = $this->actingUserToken(['customers.update']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'phone' => '0700000001',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_updating_a_customer_can_replace_its_photo(): void
    {
        Storage::fake('public');
        $oldPhotoPath = 'customers/old-photo.jpg';
        Storage::disk('public')->put($oldPhotoPath, 'fake-content');
        $customer = Customer::factory()->create(['photo' => $oldPhotoPath]);
        $token = $this->actingUserToken(['customers.update']);
        $newPhoto = UploadedFile::fake()->image('new-photo.jpg');

        $response = $this->post("/api/v1/customers/{$customer->id}?_method=PUT", [
            'photo' => $newPhoto,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();

        $customer->refresh();
        $this->assertNotSame($oldPhotoPath, $customer->photo);
        Storage::disk('public')->assertMissing($oldPhotoPath);
        Storage::disk('public')->assertExists($customer->photo);
    }

    public function test_updating_a_nonexistent_customer_returns_404(): void
    {
        $token = $this->actingUserToken(['customers.update']);

        $response = $this->putJson('/api/v1/customers/999999', [
            'full_name' => 'New Name',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404)->assertJson(['success' => false]);
    }
}
