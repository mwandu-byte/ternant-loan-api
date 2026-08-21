<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function requestResetOtpFor(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $otp = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$otp) {
            $otp = $notification->otp;

            return true;
        });

        return (string) $otp;
    }

    public function test_successful_password_reset(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $otp = $this->requestResetOtpFor($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Password reset successfully.',
            'data' => null,
        ]);

        $this->assertTrue(Hash::check('NewStrongPassword123!', $user->fresh()->password));
    }

    public function test_reset_with_invalid_otp_fails(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => '000000',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The reset code is invalid or has expired.',
            'errors' => [],
        ]);
    }

    public function test_reset_with_expired_otp_fails(): void
    {
        $user = User::factory()->create();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('654321'),
            'created_at' => now()->subMinutes(config('password_reset.otp_expire_minutes') + 5),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => '654321',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The reset code is invalid or has expired.',
        ]);
    }

    public function test_reset_otp_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetOtpFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'AnotherStrongPassword456!',
            'password_confirmation' => 'AnotherStrongPassword456!',
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_reset_password_confirmation_mismatch(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetOtpFor($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'SomethingElse123!',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_reset_with_weak_password_fails(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetOtpFor($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_successful_reset_revokes_active_refresh_tokens(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        JWTAuth::fromUser($user);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'some-raw-refresh-token'),
            'expires_at' => now()->addDays(14),
        ]);

        $otp = $this->requestResetOtpFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ])->assertOk();

        $this->assertSame(0, RefreshToken::active()->where('user_id', $user->id)->count());
    }
}
