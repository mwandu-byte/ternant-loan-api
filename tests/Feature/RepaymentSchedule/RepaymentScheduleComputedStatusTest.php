<?php

namespace Tests\Feature\RepaymentSchedule;

use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentScheduleComputedStatusTest extends TestCase
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

    private function statusFor(RepaymentSchedule $repayment): string
    {
        $token = $this->actingUserToken(['repayment-schedules.view']);

        return $this->getJson(
            "/api/v1/repayment-schedules/{$repayment->id}",
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(200)->json('data.status');
    }

    public function test_pending_schedule_with_future_due_date_shows_status_pending(): void
    {
        $repayment = RepaymentSchedule::factory()->create([
            'status' => 'pending',
            'due_date' => Carbon::tomorrow()->toDateString(),
        ]);

        $this->assertSame('pending', $this->statusFor($repayment));
    }

    public function test_pending_schedule_with_todays_due_date_shows_status_due(): void
    {
        $repayment = RepaymentSchedule::factory()->dueToday()->create();

        $this->assertSame('due', $this->statusFor($repayment));
    }

    public function test_pending_schedule_with_past_due_date_beyond_grace_period_shows_status_overdue(): void
    {
        // 10 days past due clears the seeded 7-day default grace period.
        $repayment = RepaymentSchedule::factory()->overdue()->create();

        $this->assertSame('overdue', $this->statusFor($repayment));
    }

    public function test_pending_schedule_past_due_date_but_within_grace_period_shows_status_due(): void
    {
        // 3 days past due is still within the seeded 7-day default grace
        // period — must NOT be labeled overdue yet.
        $repayment = RepaymentSchedule::factory()->create([
            'status' => 'pending',
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame('due', $this->statusFor($repayment));
    }

    public function test_paid_schedule_with_past_due_date_still_shows_status_paid(): void
    {
        $repayment = RepaymentSchedule::factory()->paid()->create([
            'due_date' => Carbon::yesterday()->toDateString(),
        ]);

        $this->assertSame('paid', $this->statusFor($repayment));
    }

    public function test_fully_paid_schedule_never_shows_as_overdue_even_with_past_due_date(): void
    {
        $repayment = RepaymentSchedule::factory()->paid()->create([
            'due_date' => Carbon::now()->subDays(30)->toDateString(),
        ]);

        $this->assertSame('paid', $this->statusFor($repayment));
        $this->assertFalse($this->isOverdueFor($repayment));
    }

    private function isOverdueFor(RepaymentSchedule $repayment): bool
    {
        $token = $this->actingUserToken(['repayment-schedules.view']);

        return $this->getJson(
            "/api/v1/repayment-schedules/{$repayment->id}",
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(200)->json('data.is_overdue');
    }
}
