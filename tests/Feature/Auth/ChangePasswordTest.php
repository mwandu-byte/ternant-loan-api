<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function loginAndGetAccessToken(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'OldPassword123!',
        ])->json('data.access_token');
    }

    public function test_successful_password_change(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data' => null,
        ]);

        $user->refresh();
        $this->assertTrue(Hash::check('NewStrongPassword123!', $user->password));
        $this->assertFalse(Hash::check('OldPassword123!', $user->password));
    }

    public function test_change_password_with_incorrect_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The current password is incorrect.',
            'errors' => [
                'current_password' => ['The current password is incorrect.'],
            ],
        ]);
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    public function test_new_password_confirmation_mismatch(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'SomethingElse123!',
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_new_password_same_as_current_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'OldPassword123!',
            'password_confirmation' => 'OldPassword123!',
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ]);

        $response->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_successful_change_revokes_active_refresh_tokens(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $accessToken = $this->loginAndGetAccessToken($user);

        $this->assertGreaterThan(0, RefreshToken::active()->where('user_id', $user->id)->count());

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!',
        ], ['Authorization' => "Bearer {$accessToken}"])->assertOk();

        $this->assertSame(0, RefreshToken::active()->where('user_id', $user->id)->count());
    }
}
