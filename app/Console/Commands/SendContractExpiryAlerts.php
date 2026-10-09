<?php

namespace App\Console\Commands;

use App\Actions\Travel\Providers\SendContractExpiryAlerts as SendAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:contract-alerts')]
#[Description('Warn about provider contracts ending in 30, 14 and 7 days, and contracts that have just expired')]
class SendContractExpiryAlerts extends Command
{
    public function handle(SendAlerts $alerts): int
    {
        $sent = $alerts->handle();
        $this->info($sent.' contract expiry '.($sent === 1 ? 'alert' : 'alerts').' sent.');

        return self::SUCCESS;
    }
}
