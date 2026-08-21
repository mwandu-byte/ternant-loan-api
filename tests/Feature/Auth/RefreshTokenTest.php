<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function loginAndGetRefreshToken(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        return $response->json('data.refresh_token');
    }

    public function test_refresh_token_returns_a_new_token_pair(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $refreshToken = $this->loginAndGetRefreshToken($user);

        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);

        $response->assertOk()
            ->assertJson(['success' => true, 'message' => 'Token refreshed successfully'])
            ->assertJsonStructure([
                'data' => ['access_token', 'refresh_token', 'token_type', 'expires_in', 'user'],
            ]);

        $this->assertNotSame($refreshToken, $response->json('data.refresh_token'));
    }

    public function test_refresh_rotates_the_token_revoking_the_old_one(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $refreshToken = $this->loginAndGetRefreshToken($user);

        $oldHash = hash('sha256', $refreshToken);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => $oldHash, 'revoked_at' => null]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])->assertOk();

        $this->assertNotNull(RefreshToken::where('token_hash', $oldHash)->first()->revoked_at);
        $this->assertSame(2, RefreshToken::where('user_id', $user->id)->count());
    }

    public function test_reusing_a_revoked_refresh_token_is_rejected_and_revokes_all_active_tokens(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $refreshToken = $this->loginAndGetRefreshToken($user);

        // First refresh rotates the token (old one becomes revoked).
        $newRefreshToken = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->json('data.refresh_token');

        // Reusing the now-revoked original token must fail...
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        // ...and must have revoked the still-valid rotated token too (compromise response).
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $newRefreshToken])
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        $this->assertSame(0, RefreshToken::active()->where('user_id', $user->id)->count());
    }
}
