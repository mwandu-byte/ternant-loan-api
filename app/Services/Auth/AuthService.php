<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: User}
     */
    public function login(string $email, string $password): array
    {
        $accessToken = Auth::guard('api')->attempt([
            'email' => $email,
            'password' => $password,
        ]);

        if (! $accessToken) {
            throw new InvalidCredentialsException;
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();

        if (! $this->businessIsActive($user)) {
            Auth::guard('api')->logout();

            throw new InvalidCredentialsException;
        }

        return $this->issueTokens($user, $accessToken);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: User}
     */
    public function refresh(string $rawRefreshToken): array
    {
        $hash = hash('sha256', $rawRefreshToken);

        $refreshToken = RefreshToken::active()->where('token_hash', $hash)->first();

        if (! $refreshToken) {
            $this->handlePossibleReuse($hash);

            throw new InvalidRefreshTokenException;
        }

        $refreshToken->update(['revoked_at' => now()]);

        if (! $this->businessIsActive($refreshToken->user)) {
            throw new InvalidRefreshTokenException;
        }

        return $this->issueTokens($refreshToken->user);
    }

    public function logout(User $user): void
    {
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Auth::guard('api')->logout();
    }

    /**
     * Users of a suspended business can neither log in nor refresh.
     * Platform users (no business) are never affected.
     */
    private function businessIsActive(User $user): bool
    {
        return $user->business === null || $user->business->isActive();
    }

    /**
     * A token hash that matches an existing, already-revoked/expired record
     * is a signal the refresh token may have been stolen and replayed.
     * Respond by revoking every active refresh token for that user.
     */
    private function handlePossibleReuse(string $hash): void
    {
        $existing = RefreshToken::where('token_hash', $hash)->first();

        if (! $existing) {
            return;
        }

        RefreshToken::where('user_id', $existing->user_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: User}
     */
    private function issueTokens(User $user, ?string $accessToken = null): array
    {
        $accessToken ??= Auth::guard('api')->login($user);

        $rawRefreshToken = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefreshToken),
            'expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl')),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $rawRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'user' => $user,
        ];
    }
}
