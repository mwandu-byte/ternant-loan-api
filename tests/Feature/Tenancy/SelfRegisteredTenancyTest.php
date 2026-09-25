<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\Customer;
use App\Models\GracePeriod;
use App\Models\InterestRule;
use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Services\Repayment\OverdueService;
use Database\Seeders\LoanConfigurationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SelfRegisteredTenancyTest extends TestCase
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

    private function register(string $slug): User
    {
        $this->postJson('/api/v1/auth/register', [
            'business' => ['name' => "Biz {$slug}", 'phone' => '0712000111', 'email' => "info@{$slug}.co.tz"],
            'owner' => [
                'name' => "Owner {$slug}", 'email' => "owner@{$slug}.co.tz", 'phone' => '0754000111',
                'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            ],
        ])->assertCreated();

        return User::firstWhere('email', "owner@{$slug}.co.tz");
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function createCustomer(User $owner, string $phone): int
    {
        return $this->postJson('/api/v1/customers', [
            'full_name' => 'Jane', 'phone' => $phone, 'identification_type' => 'NIDA',
            'identification_number' => $phone, 'address' => 'Dar',
        ], $this->as($owner))->assertCreated()->json('data.id');
    }

    public function test_a_new_owner_can_lend_immediately_and_businesses_are_isolated(): void
    {
        $ownerA = $this->register('a');
        $ownerB = $this->register('b');

        $customerA = $this->createCustomer($ownerA, '+255711000001');
        $loanA = $this->postJson('/api/v1/loans', [
            'customer_id' => $customerA, 'principal_amount' => 1000000, 'repayment_frequency' => 'monthly',
            'repayment_term' => 3, 'start_date' => '2026-01-01',
        ], $this->as($ownerA))->assertCreated()->assertJsonPath('data.interest_rate', '22.00')->json('data.id');

        $this->assertSame([], $this->getJson('/api/v1/customers', $this->as($ownerB))->json('data.customers'));
        $this->assertSame([], $this->getJson('/api/v1/loans', $this->as($ownerB))->json('data.loans'));
        $this->getJson("/api/v1/customers/{$customerA}", $this->as($ownerB))->assertForbidden();
        $this->getJson("/api/v1/loans/{$loanA}", $this->as($ownerB))->assertForbidden();
        $this->getJson("/api/v1/users/{$ownerA->id}", $this->as($ownerB))->assertForbidden();

        $emails = collect($this->getJson('/api/v1/users', $this->as($ownerB))->json('data.users'))->pluck('email')->all();
        $this->assertSame(['owner@b.co.tz'], $emails);

        // The same customer may independently register with both businesses.
        $this->createCustomer($ownerB, '+255711000001');
    }

    public function test_loan_configuration_changes_stay_inside_the_business(): void
    {
        $ownerA = $this->register('a');
        $ownerB = $this->register('b');

        $ruleA = InterestRule::forBusiness($ownerA->business_id)->where('minimum_amount', 500000)->first();
        $ruleB = InterestRule::forBusiness($ownerB->business_id)->where('minimum_amount', 500000)->first();

        $this->putJson("/api/v1/loan-configurations/interest-rules/{$ruleA->id}", ['interest_rate' => 15], $this->as($ownerA))
            ->assertOk();
        $this->putJson("/api/v1/loan-configurations/interest-rules/{$ruleB->id}", ['interest_rate' => 1], $this->as($ownerA))
            ->assertNotFound();

        $this->assertSame('15.00', $ruleA->fresh()->interest_rate);
        $this->assertSame('22.00', $ruleB->fresh()->interest_rate);
        $this->assertSame('22.00', InterestRule::forBusiness(null)->where('minimum_amount', 500000)->first()->interest_rate);
        $this->assertCount(2, $this->getJson('/api/v1/loan-configurations/interest-rules', $this->as($ownerA))->json('data'));

        $customerB = $this->createCustomer($ownerB, '+255711000002');
        $this->postJson('/api/v1/loans', [
            'customer_id' => $customerB, 'principal_amount' => 1000000, 'repayment_frequency' => 'monthly',
            'repayment_term' => 3, 'start_date' => '2026-01-01',
        ], $this->as($ownerB))->assertCreated()->assertJsonPath('data.interest_rate', '22.00');

        // Each business owns its own frequency codes.
        $this->postJson('/api/v1/loan-configurations/repayment-frequencies', [
            'name' => 'Weekly', 'code' => 'weekly', 'interval_value' => 1, 'interval_unit' => 'week',
        ], $this->as($ownerA))->assertCreated();
        $this->postJson('/api/v1/loan-configurations/repayment-frequencies', [
            'name' => 'Weekly', 'code' => 'weekly', 'interval_value' => 1, 'interval_unit' => 'week',
        ], $this->as($ownerB))->assertCreated();
        $this->postJson('/api/v1/loans', [
            'customer_id' => $customerB, 'principal_amount' => 1000000, 'repayment_frequency' => 'every_4_months',
            'repayment_term' => 3, 'start_date' => '2026-01-01',
        ], $this->as($ownerB))->assertCreated();

        $this->putJson('/api/v1/loan-configurations/grace-period', ['duration' => 30, 'unit' => 'days'], $this->as($ownerA))->assertOk();
        $this->assertSame(7, GracePeriod::forBusiness($ownerB->business_id)->first()->duration);
    }

    public function test_overdue_status_uses_each_businesss_own_grace_period(): void
    {
        $short = Business::factory()->create();
        $long = Business::factory()->create();
        GracePeriod::forBusiness($short->id)->update(['duration' => 3, 'unit' => 'days']);
        GracePeriod::forBusiness($long->id)->update(['duration' => 30, 'unit' => 'days']);

        $scheduleFor = function (Business $business) {
            $loan = Loan::factory()->active()->create([
                'business_id' => $business->id,
                'customer_id' => Customer::factory()->create(['business_id' => $business->id])->id,
            ]);

            return RepaymentSchedule::factory()->create([
                'loan_id' => $loan->id, 'due_date' => Carbon::today()->subDays(10)->toDateString(),
            ]);
        };

        $shortSchedule = $scheduleFor($short);
        $longSchedule = $scheduleFor($long);

        $overdue = app(OverdueService::class);
        $this->assertTrue($overdue->isOverdue($shortSchedule));
        $this->assertFalse($overdue->isOverdue($longSchedule));
        $this->assertSame(
            [$shortSchedule->id],
            $overdue->applyOverdueScope(RepaymentSchedule::query())->pluck('id')->all(),
        );

        $this->artisan('penalties:accrue')->assertSuccessful();
        $this->assertSame(1, $shortSchedule->penalties()->count());
        $this->assertSame(0, $longSchedule->penalties()->count());
    }

    public function test_business_users_cannot_change_shared_roles_or_grant_platform_roles(): void
    {
        $owner = $this->register('a');
        $staff = User::factory()->create(['business_id' => $owner->business_id]);
        $staff->assignRole('staff');
        $headers = $this->as($owner);

        $this->putJson('/api/v1/roles/'.Role::findByName('staff', 'api')->id.'/permissions', ['permissions' => ['users.delete']], $headers)
            ->assertForbidden();
        $this->deleteJson('/api/v1/permissions/'.Permission::findByName('users.delete', 'api')->id, [], $headers)
            ->assertForbidden();
        $this->getJson('/api/v1/roles', $headers)->assertOk();

        $this->postJson("/api/v1/users/{$staff->id}/roles", ['role' => 'admin'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->putJson("/api/v1/users/{$staff->id}/roles", ['roles' => ['admin']], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('roles');
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@a.co.tz', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'roles' => ['admin'],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('roles');

        $this->postJson("/api/v1/users/{$staff->id}/roles", ['role' => 'manager'], $headers)->assertOk();
        $this->assertFalse($staff->fresh()->hasRole('admin'));
    }

    public function test_the_owner_manages_their_own_business_settings_but_not_its_status(): void
    {
        $owner = $this->register('a');
        $other = $this->register('b');

        $this->putJson('/api/v1/business', [
            'requires_guarantor' => true, 'requires_application_fee' => true, 'status' => 'suspended', 'address' => 'Mwanza',
        ], $this->as($owner))
            ->assertOk()
            ->assertJsonPath('data.requires_guarantor', true)
            ->assertJsonPath('data.requires_application_fee', true)
            ->assertJsonPath('data.status', 'active');

        $this->assertFalse($other->business->fresh()->requires_guarantor);

        $this->putJson('/api/v1/business', ['email' => 'info@b.co.tz'], $this->as($owner))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $staff = User::factory()->create(['business_id' => $owner->business_id]);
        $staff->assignRole('manager');
        $this->putJson('/api/v1/business', ['requires_guarantor' => false], $this->as($staff))->assertForbidden();
    }
}
