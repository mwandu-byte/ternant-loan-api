<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidResetTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class ResetPasswordService
{
    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();

                RefreshToken::where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidResetTokenException();
        }
    }
}
