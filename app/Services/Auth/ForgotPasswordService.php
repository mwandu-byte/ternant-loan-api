<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ForgotPasswordService
{
    /**
     * Always no-ops the same way whether the email exists or not — the
     * caller must never branch on the result, or it becomes an
     * email-enumeration oracle.
     */
    public function sendResetLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        $otp = $this->generateOtp();

        // One row per email: replaces (invalidates) any previous unused OTP.
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($otp),
            'created_at' => now(),
        ]);

        $user->sendPasswordResetNotification($otp);
    }

    private function generateOtp(): string
    {
        $length = (int) config('password_reset.otp_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
