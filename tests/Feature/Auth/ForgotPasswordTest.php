<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_valid_forgot_password_request_returns_generic_success(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'If the account exists, a password reset instruction has been sent.',
            'data' => null,
        ]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_invalid_email_format_returns_validation_error(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_non_existent_email_returns_the_same_generic_response(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'If the account exists, a password reset instruction has been sent.',
            'data' => null,
        ]);
    }

    public function test_forgot_password_creates_a_reset_token_record(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $limit = (int) config('rate_limits.forgot_password');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429)
            ->assertJson(['success' => false]);
    }

    public function test_new_request_invalidates_the_previous_reset_otp(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $firstOtp = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$firstOtp) {
            $firstOtp = $notification->otp;

            return true;
        });

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $secondOtp = collect(Notification::sent($user, ResetPasswordNotification::class))
            ->pluck('otp')
            ->last();

        $this->assertNotSame($firstOtp, $secondOtp);
        $this->assertDatabaseCount('password_reset_tokens', 1);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'otp' => $firstOtp,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ])->assertStatus(422);
    }
}
