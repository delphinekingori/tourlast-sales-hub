<?php

namespace App\Console\Commands;

use App\Actions\Travel\Payments\SimulateMpesaCustomer;
use App\Enums\Travel\RefundStatus;
use App\Integrations\Mpesa\MpesaGateway;
use App\Models\TravelRefund;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:mpesa-simulate-refund {refund : Refund id (in Processing after "Send refund via M-Pesa")} {--fail : Simulate a failed payout instead of a paid one}')]
#[Description('Test mode only: post the Daraja B2C result for a refund sent through the sandbox gateway')]
class MpesaSimulateRefund extends Command
{
    public function handle(MpesaGateway $gateway, SimulateMpesaCustomer $simulate): int
    {
        if (! $gateway->isSimulated() || app()->isProduction()) {
            $this->components->error('Only available with MPESA_DRIVER=sandbox outside production.');

            return self::FAILURE;
        }

        $refund = TravelRefund::query()->find($this->argument('refund'));

        if (! $refund || $refund->status !== RefundStatus::Processing || blank($refund->originator_conversation_id)) {
            $this->components->error('That refund is not waiting on an M-Pesa payout result.');

            return self::FAILURE;
        }

        $simulate->answerPayout($refund, ! $this->option('fail'));

        $this->components->info('Refund is now '.$refund->fresh()->status->label().'.');

        return self::SUCCESS;
    }
}
