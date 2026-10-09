<?php

namespace App\Console\Commands;

use App\Actions\Travel\Payments\SimulateMpesaCustomer;
use App\Integrations\Mpesa\MpesaGateway;
use App\Integrations\Mpesa\MpesaPhone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:mpesa-simulate {account : Booking reference the customer types as the account number} {amount : Amount in KES} {--phone=0708374149} {--name=Test Customer}')]
#[Description('Test mode only: pretend a customer paid the paybill (posts a fake Daraja confirmation through the real handler)')]
class MpesaSimulate extends Command
{
    public function handle(MpesaGateway $gateway, SimulateMpesaCustomer $simulate): int
    {
        if (! $gateway->isSimulated() || app()->isProduction()) {
            $this->components->error('Only available with MPESA_DRIVER=sandbox outside production.');

            return self::FAILURE;
        }

        $amount = (float) $this->argument('amount');

        if ($amount < 1) {
            $this->components->error('Enter an amount of at least 1.');

            return self::FAILURE;
        }

        $payment = $simulate->payPaybill(
            (string) $this->argument('account'),
            $amount,
            MpesaPhone::normalise((string) $this->option('phone')) ?? '254708374149',
            (string) $this->option('name'),
        );

        if (! $payment) {
            $this->components->error('The confirmation was not recorded; see the callbacks log.');

            return self::FAILURE;
        }

        $this->components->info($payment->package_booking_id
            ? "Recorded {$payment->mpesa_receipt} on booking {$payment->booking->reference}."
            : "Recorded {$payment->mpesa_receipt} as unmatched (no open booking with that reference).");

        return self::SUCCESS;
    }
}
