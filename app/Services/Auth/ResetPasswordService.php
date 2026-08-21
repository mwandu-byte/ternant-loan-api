<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidResetTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordService
{
    public function reset(string $email, string $otp, string $password): void
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || $this->isExpired($record->created_at) || ! Hash::check($otp, $record->token)) {
            throw new InvalidResetTokenException();
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new InvalidResetTokenException();
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Single-use: the OTP cannot be replayed once it's been used.
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    private function isExpired(string $createdAt): bool
    {
        return Carbon::parse($createdAt)->addMinutes((int) config('password_reset.otp_expire_minutes'))->isPast();
    }
}
