<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\IncorrectCurrentPasswordException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ChangePasswordService
{
    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new IncorrectCurrentPasswordException();
        }

        $user->forceFill(['password' => Hash::make($newPassword)])->save();

        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
