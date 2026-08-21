<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset OTP
    |--------------------------------------------------------------------------
    |
    | A one-time numeric code emailed to the user, typed into the mobile app's
    | reset-password screen alongside their new password. Stored hashed in
    | the `password_reset_tokens` table (one row per email — a new request
    | replaces any previous, unused code), and is single-use: it's deleted
    | as soon as it's successfully used to reset the password.
    |
    */

    'otp_length' => (int) env('PASSWORD_RESET_OTP_LENGTH', 6),

    'otp_expire_minutes' => (int) env('PASSWORD_RESET_OTP_EXPIRE_MINUTES', 10),

];
