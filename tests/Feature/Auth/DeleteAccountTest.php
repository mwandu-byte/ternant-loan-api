<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(?Business $business, string $role): User
    {
        $user = User::factory()->create(['business_id' => $business?->id, 'phone' => '+255700000001']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/delete-account', ['password' => 'password'])->assertUnauthorized();
    }

    public function test_wrong_password_changes_nothing(): void
    {
        $user = $this->userWithRole(Business::factory()->create(), 'staff');

        $this->postJson('/api/v1/auth/delete-account', ['password' => 'wrong'], $this->headersFor($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue($user->fresh()->is_enabled);
        $this->assertTrue($user->fresh()->hasRole('staff'));
    }

    public function test_deleting_anonymizes_the_account_and_ends_every_session(): void
    {
        $user = $this->userWithRole(Business::factory()->create(), 'staff');
        $originalEmail = $user->email;
        RefreshToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/auth/delete-account', ['password' => 'password'], $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Your account has been deleted.');

        $user->refresh();
        $this->assertSame('Deleted user', $user->name);
        $this->assertSame("deleted-user-{$user->id}@deleted.invalid", $user->email);
        $this->assertNull($user->phone);
        $this->assertFalse($user->is_enabled);
        $this->assertCount(0, $user->getRoleNames());
        $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id]);

        // The token used for the request is blacklisted, and the old
        // credentials no longer sign anyone in.
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $originalEmail, 'password' => 'password'])->assertUnauthorized();
    }

    public function test_financial_records_the_user_created_are_kept(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithRole($business, 'manager');
        $customer = Customer::factory()->create(['business_id' => $business->id, 'created_by' => $user->id]);
        $loan = Loan::factory()->create(['business_id' => $business->id, 'customer_id' => $customer->id, 'created_by' => $user->id]);

        $this->postJson('/api/v1/auth/delete-account', ['password' => 'password'], $this->headersFor($user))->assertOk();

        $this->assertSame($user->id, $loan->fresh()->created_by);
        $this->assertSame($customer->full_name, $customer->fresh()->full_name);
        $this->assertTrue($business->fresh()->isActive());
    }

    public function test_only_the_business_owner_can_close_the_business(): void
    {
        $business = Business::factory()->create();
        $staff = $this->userWithRole($business, 'manager');
        $platformAdmin = $this->userWithRole(null, 'admin');
        $this->userWithRole(null, 'admin'); // keeps roles.update coverage

        foreach ([$staff, $platformAdmin] as $user) {
            $this->postJson('/api/v1/auth/delete-account', ['password' => 'password', 'include_business' => true], $this->headersFor($user))
                ->assertStatus(422)
                ->assertJsonValidationErrors('include_business');
            $this->assertTrue($user->fresh()->is_enabled);
        }

        $this->assertTrue($business->fresh()->isActive());
    }

    public function test_an_owner_can_close_their_business_and_anonymize_its_people(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('customers/photo.jpg', 'img');

        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $owner = $this->userWithRole($business, 'owner');
        $staff = $this->userWithRole($business, 'staff');
        $customer = Customer::factory()->create(['business_id' => $business->id, 'photo' => 'customers/photo.jpg']);
        $secondCustomer = Customer::factory()->create(['business_id' => $business->id]);
        $outsider = Customer::factory()->create(['business_id' => $other->id]);
        $loan = Loan::factory()->create(['business_id' => $business->id, 'customer_id' => $customer->id]);
        $guarantor = Guarantor::factory()->create(['business_id' => $business->id, 'loan_id' => $loan->id]);

        $this->postJson('/api/v1/auth/delete-account', ['password' => 'password', 'include_business' => true], $this->headersFor($owner))
            ->assertOk();

        $business->refresh();
        $this->assertSame('suspended', $business->status);
        $this->assertSame("Closed business #{$business->id}", $business->name);
        $this->assertNull($business->email);
        $this->assertNull($business->phone);

        foreach ([$customer, $secondCustomer] as $c) {
            $c->refresh();
            $this->assertSame('Deleted customer', $c->full_name);
            $this->assertSame("deleted-{$c->id}", $c->phone);
            $this->assertSame("deleted-{$c->id}", $c->identification_number);
            $this->assertNull($c->photo);
        }
        Storage::disk('public')->assertMissing('customers/photo.jpg');

        $this->assertSame('Deleted guarantor', $guarantor->fresh()->full_name);
        $this->assertNull($guarantor->fresh()->identification_number);

        // Financial records stay; other businesses are untouched.
        $this->assertNotNull($loan->fresh());
        $this->assertSame($loan->principal_amount, $loan->fresh()->principal_amount);
        $this->assertSame($outsider->full_name, $outsider->fresh()->full_name);
        $this->assertTrue($other->fresh()->isActive());

        // The rest of the team can no longer sign in.
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertUnauthorized();
    }

    public function test_the_last_user_able_to_manage_roles_cannot_delete_themselves(): void
    {
        $admin = $this->userWithRole(null, 'admin');

        $this->postJson('/api/v1/auth/delete-account', ['password' => 'password'], $this->headersFor($admin))
            ->assertStatus(409);

        $this->assertTrue($admin->fresh()->is_enabled);
    }
}
