<?php

namespace App\Console\Commands;

use App\Actions\Travel\Bookings\ExpireBookingHolds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:expire-holds')]
#[Description('Cancel unpaid pending package bookings whose slot hold has run out')]
class ExpireTravelHolds extends Command
{
    public function handle(ExpireBookingHolds $expire): int
    {
        $this->components->info($expire->handle().' expired hold(s) released.');

        return self::SUCCESS;
    }
}
