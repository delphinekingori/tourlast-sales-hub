<?php

namespace App\Console\Commands;

use App\Integrations\Mpesa\CallbackUrls;
use App\Integrations\Mpesa\MpesaException;
use App\Integrations\Mpesa\MpesaGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:mpesa-register-urls')]
#[Description('Print the M-Pesa callback URLs and register the paybill (C2B) URLs with Safaricom')]
class MpesaRegisterUrls extends Command
{
    public function handle(MpesaGateway $gateway): int
    {
        if (blank(config('travel.mpesa.callback_secret'))) {
            $this->components->warn('DARAJA_CALLBACK_SECRET is not set. Set it before going live: Safaricom reaches the Hub only through URLs that contain it.');
        }

        $this->components->twoColumnDetail('Payment prompt (STK) callback', CallbackUrls::stk());
        $this->components->twoColumnDetail('Paybill validation', CallbackUrls::c2bValidation());
        $this->components->twoColumnDetail('Paybill confirmation', CallbackUrls::c2bConfirmation());

        if ($gateway->isSimulated()) {
            $this->components->info('MPESA_DRIVER=sandbox: nothing registered with Safaricom (test mode).');

            return self::SUCCESS;
        }

        try {
            $response = $gateway->registerC2bUrls();
        } catch (MpesaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Safaricom: '.($response['ResponseDescription'] ?? json_encode($response)));

        return self::SUCCESS;
    }
}
