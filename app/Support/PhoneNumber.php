<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PhoneNumber
{
    /**
     * Normalize a raw phone number into a consistent E.164-style format
     * (e.g. "+255712345678"), using the given default country code to
     * expand locally-formatted numbers (leading "0").
     */
    public static function normalize(string $raw, ?string $defaultCountryCode = null): string
    {
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';

        if (str_starts_with($digits, '+')) {
            $normalized = '+'.ltrim(substr($digits, 1), '0');
        } elseif ($defaultCountryCode !== null && str_starts_with($digits, '0')) {
            $normalized = '+'.$defaultCountryCode.substr($digits, 1);
        } elseif ($defaultCountryCode !== null && ! str_starts_with($digits, $defaultCountryCode)) {
            $normalized = '+'.$defaultCountryCode.$digits;
        } else {
            $normalized = '+'.$digits;
        }

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $normalized)) {
            throw ValidationException::withMessages([
                'phone' => 'The phone number format is invalid.',
            ]);
        }

        return $normalized;
    }
}
