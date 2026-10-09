<?php

namespace App\Integrations\Mpesa;

/**
 * Kenyan mobile numbers in the 2547XXXXXXXX / 2541XXXXXXXX form M-Pesa expects.
 */
class MpesaPhone
{
    /**
     * "0712 345 678", "+254712345678", "254112345678", "712345678" → "254712345678".
     * Null when it is not a Safaricom-style Kenyan mobile number.
     */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        $local = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '254') => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        if ($local === null || ! preg_match('/^[17]\d{8}$/', $local)) {
            return null;
        }

        return '254'.$local;
    }
}
