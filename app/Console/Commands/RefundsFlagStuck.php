<?php

namespace App\Console\Commands;

use App\Actions\Travel\Payments\FlagStuckRefundPayouts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:refunds-flag-stuck')]
#[Description('Alert Accounts to M-Pesa refund payouts that Safaricom has not answered')]
class RefundsFlagStuck extends Command
{
    public function handle(FlagStuckRefundPayouts $flag): int
    {
        $this->info('Flagged '.$flag->handle().' stuck refund payout(s).');

        return self::SUCCESS;
    }
}
