<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_logout_revokes_refresh_tokens_and_blacklists_the_access_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $accessToken = $login->json('data.access_token');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => "Bearer {$accessToken}",
        ])->assertOk()->assertJson(['success' => true, 'message' => 'Logged out successfully']);

        $this->assertSame(0, RefreshToken::active()->where('user_id', $user->id)->count());

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$accessToken}",
        ])->assertStatus(401);
    }
}
