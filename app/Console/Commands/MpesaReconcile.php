<?php

namespace App\Console\Commands;

use App\Actions\Travel\Payments\ReconcileMpesaPayments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:mpesa-reconcile')]
#[Description('Check M-Pesa payment prompts whose callback never arrived')]
class MpesaReconcile extends Command
{
    public function handle(ReconcileMpesaPayments $reconcile): int
    {
        $summary = $reconcile->handle();

        $this->info("Checked {$summary['checked']}: {$summary['completed']} paid, {$summary['failed']} failed, {$summary['still_pending']} still waiting.");

        return self::SUCCESS;
    }
}
