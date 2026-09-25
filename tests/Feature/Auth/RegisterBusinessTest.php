<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\GracePeriod;
use App\Models\InterestRule;
use App\Models\LoanAmountConfiguration;
use App\Models\RepaymentFrequency;
use App\Models\User;
use Database\Seeders\LoanConfigurationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegisterBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(LoanConfigurationSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $business = [], array $owner = []): array
    {
        return [
            'business' => array_merge([
                'name' => 'Kilimo Credit Ltd',
                'registration_number' => 'BRELA-123456',
                'phone' => '0712345678',
                'email' => 'info@kilimocredit.co.tz',
                'address' => 'Arusha',
            ], $business),
            'owner' => array_merge([
                'name' => 'Asha Mushi',
                'email' => 'asha@kilimocredit.co.tz',
                'phone' => '0754000111',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ], $owner),
        ];
    }

    public function test_registration_creates_business_and_owner_and_signs_in(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'asha@kilimocredit.co.tz')
            ->assertJsonPath('data.user.roles', ['owner'])
            ->assertJsonPath('data.business.name', 'Kilimo Credit Ltd')
            ->assertJsonPath('data.business.phone', '+255712345678')
            ->assertJsonPath('data.business.status', 'active')
            ->assertJsonPath('data.business.requires_application_fee', false)
            ->assertJsonPath('data.business.requires_guarantor', false)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);

        $business = Business::firstWhere('email', 'info@kilimocredit.co.tz');
        $owner = User::firstWhere('email', 'asha@kilimocredit.co.tz');

        $this->assertSame($business->id, $owner->business_id);
        $this->assertSame('+255754000111', $owner->phone);
        $this->assertTrue($owner->hasRole('owner'));
        $this->assertFalse($owner->hasRole('admin'));
        $this->assertDatabaseCount('refresh_tokens', 1);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('data.access_token')])
            ->assertOk()->assertJsonPath('data.user.email', 'asha@kilimocredit.co.tz');
        $this->getJson('/api/v1/business', ['Authorization' => 'Bearer '.$response->json('data.access_token')])
            ->assertOk()->assertJsonPath('data.id', $business->id);
    }

    public function test_registration_gives_the_business_its_own_copy_of_the_default_loan_configuration(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $businessId = Business::firstWhere('email', 'info@kilimocredit.co.tz')->id;

        $this->assertSame(InterestRule::forBusiness(null)->count(), InterestRule::forBusiness($businessId)->count());
        $this->assertSame(RepaymentFrequency::forBusiness(null)->count(), RepaymentFrequency::forBusiness($businessId)->count());
        $this->assertSame(1, GracePeriod::forBusiness($businessId)->count());
        $this->assertSame(1, LoanAmountConfiguration::forBusiness($businessId)->count());
    }

    public function test_the_owner_can_log_in_with_the_registered_credentials(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/login', ['email' => 'asha@kilimocredit.co.tz', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk()->assertJsonPath('data.user.roles', ['owner']);
    }

    public function test_duplicate_business_email_registration_number_and_owner_email_are_rejected(): void
    {
        Business::factory()->create(['email' => 'info@kilimocredit.co.tz', 'registration_number' => 'BRELA-123456']);
        User::factory()->create(['email' => 'asha@kilimocredit.co.tz']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business.email', 'business.registration_number', 'owner.email']);

        $this->assertDatabaseCount('businesses', 1);
    }

    public function test_registration_number_is_optional(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['registration_number' => null]))->assertCreated();
        $this->postJson('/api/v1/auth/register', $this->payload(
            ['registration_number' => null, 'email' => 'other@biz.co.tz'],
            ['email' => 'other@owner.co.tz'],
        ))->assertCreated();
    }

    public function test_required_fields_and_password_rules_are_validated(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business', 'owner']);

        $this->postJson('/api/v1/auth/register', $this->payload(owner: ['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner.password']);

        $this->postJson('/api/v1/auth/register', $this->payload(owner: ['phone' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner.phone']);

        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_a_failure_part_way_through_rolls_everything_back(): void
    {
        Role::where('name', 'owner')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertServerError();

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertSame(0, InterestRule::whereNotNull('business_id')->count());
    }

    public function test_a_signed_in_caller_does_not_leak_into_the_new_business(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload())->json('data.access_token');

        $this->postJson('/api/v1/auth/register', $this->payload(
            ['email' => 'second@biz.co.tz', 'registration_number' => 'BRELA-2'],
            ['email' => 'second@owner.co.tz'],
        ), ['Authorization' => 'Bearer '.$token])->assertCreated();

        $first = Business::firstWhere('email', 'info@kilimocredit.co.tz');
        $second = Business::firstWhere('email', 'second@biz.co.tz');

        $this->assertSame($second->id, User::firstWhere('email', 'second@owner.co.tz')->business_id);
        $this->assertSame(1, GracePeriod::forBusiness($first->id)->count());
        $this->assertSame(1, GracePeriod::forBusiness($second->id)->count());
        $this->assertSame(InterestRule::forBusiness(null)->count(), InterestRule::forBusiness($second->id)->count());
    }

    public function test_the_owner_is_not_a_platform_administrator(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload())->json('data.access_token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/businesses', $headers)->assertForbidden();
        $this->postJson('/api/v1/businesses', ['name' => 'Other'], $headers)->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'sneaky'], $headers)->assertForbidden();
        $this->postJson('/api/v1/permissions', ['name' => 'sneaky.view'], $headers)->assertForbidden();
    }

    public function test_registration_is_rate_limited(): void
    {
        config(['rate_limits.register' => 1]);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'x@y.co.tz'], ['email' => 'x@z.co.tz']))
            ->assertStatus(429);
    }
}
