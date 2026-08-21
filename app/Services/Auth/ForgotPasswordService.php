<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Password;

class ForgotPasswordService
{
    /**
     * Always no-ops the same way whether the email exists, doesn't exist, or
     * is throttled by the broker's own cooldown — the caller must never
     * branch on the result, or it becomes an email-enumeration oracle.
     */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }
}
