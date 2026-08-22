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

class CustomerCreateTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'John Doe',
            'phone' => '0712345678',
            'email' => 'john@example.com',
            'identification_type' => 'NIDA',
            'identification_number' => '19900101-12345-00001-23',
            'gender' => 'male',
            'address' => 'Dar es Salaam',
        ], $overrides);
    }

    public function test_authorized_user_can_create_a_customer(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('+255712345678', $response->json('data.phone'));
        $this->assertDatabaseHas('customers', ['full_name' => 'John Doe', 'phone' => '+255712345678']);
    }

    public function test_user_without_create_permission_cannot_create_a_customer(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_creating_a_customer_requires_full_name(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['full_name' => '']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['full_name']);
    }

    public function test_creating_a_customer_requires_phone(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['phone' => '']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_creating_a_customer_requires_identification_type(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['identification_type' => '']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['identification_type']);
    }

    public function test_creating_a_customer_requires_identification_number(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['identification_number' => '']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['identification_number']);
    }

    public function test_creating_a_customer_requires_address(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['address' => '']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['address']);
    }

    public function test_creating_a_customer_rejects_duplicate_phone(): void
    {
        Customer::factory()->create(['phone' => '+255712345678']);
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['phone' => '0712345678']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_creating_a_customer_rejects_duplicate_identification_number_for_same_type(): void
    {
        Customer::factory()->create([
            'identification_type' => 'NIDA',
            'identification_number' => '19900101-12345-00001-23',
        ]);
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(['phone' => '0755555555']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['identification_number']);
    }

    public function test_customer_can_be_created_with_a_photo_upload(): void
    {
        Storage::fake('public');
        $token = $this->actingUserToken(['customers.create']);
        $photo = UploadedFile::fake()->image('photo.jpg');

        $response = $this->post('/api/v1/customers', [
            ...$this->validPayload(),
            'photo' => $photo,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.photo_url'));

        $customer = Customer::first();
        $this->assertNotNull($customer->photo);
        Storage::disk('public')->assertExists($customer->photo);
    }

    public function test_customer_response_does_not_expose_forbidden_fields(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', $this->validPayload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertArrayNotHasKey('date_of_birth', $data);
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
        $this->assertEqualsCanonicalizing([
            'id', 'full_name', 'phone', 'email', 'identification_type', 'identification_number',
            'gender', 'address', 'photo_url', 'status', 'created_at', 'updated_at',
        ], array_keys($data));
    }

    public function test_date_of_birth_and_description_are_not_accepted(): void
    {
        $token = $this->actingUserToken(['customers.create']);

        $response = $this->postJson('/api/v1/customers', [
            ...$this->validPayload(),
            'date_of_birth' => '1990-01-01',
            'description' => 'Some description',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);

        $customer = Customer::first();
        $this->assertArrayNotHasKey('date_of_birth', $customer->getAttributes());
        $this->assertArrayNotHasKey('description', $customer->getAttributes());
    }
}
