<?php

namespace App\Console\Commands;

use App\Actions\Travel\Bookings\SendPreTripReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:pretrip-reminders')]
#[Description('Remind travel salespeople about outstanding pre-trip tasks for trips in the next three days')]
class SendTravelPreTripReminders extends Command
{
    public function handle(SendPreTripReminders $reminders): int
    {
        $this->components->info($reminders->handle().' booking(s) reminded.');

        return self::SUCCESS;
    }
}
