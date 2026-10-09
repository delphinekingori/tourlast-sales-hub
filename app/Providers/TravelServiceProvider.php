<?php

namespace App\Providers;

use App\Integrations\Mpesa\DarajaMpesaGateway;
use App\Integrations\Mpesa\MpesaGateway;
use App\Integrations\Mpesa\SandboxMpesaGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Travel Sales services: the M-Pesa gateway (sandbox or live Daraja).
 */
class TravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MpesaGateway::class, fn (): MpesaGateway => config('travel.mpesa.driver') === 'daraja'
            ? new DarajaMpesaGateway((array) config('travel.mpesa'))
            : new SandboxMpesaGateway);
    }
}
