<?php

namespace App\Integrations\Mpesa;

/**
 * The public URLs Safaricom calls back on. Each carries DARAJA_CALLBACK_SECRET
 * so only Safaricom (who was given the URL) can reach the handlers.
 */
class CallbackUrls
{
    public static function stk(): string
    {
        return self::build('daraja.stk');
    }

    public static function c2bValidation(): string
    {
        return self::build('daraja.c2b.validation');
    }

    public static function c2bConfirmation(): string
    {
        return self::build('daraja.c2b.confirmation');
    }

    private static function build(string $route): string
    {
        $base = rtrim((string) (config('travel.mpesa.callback_base_url') ?: config('app.url')), '/');

        return $base.route($route, ['secret' => (string) (config('travel.mpesa.callback_secret') ?: 'not-set')], false);
    }
}
