<?php

namespace App\Actions\Travel\Influencers;

use App\Enums\Travel\CommissionEntryStatus;
use App\Models\Influencer;
use App\Models\InfluencerCommission;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use App\Support\Travel\InfluencerAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts (or a Travel manager) record that payable commission lines were
 * paid to the influencer, with the M-Pesa or bank reference.
 */
class MarkCommissionsPaid
{
    /**
     * @param  list<int>  $lineIds
     * @return int lines marked paid
     */
    public function handle(User $actor, Influencer $influencer, array $lineIds, string $reference): int
    {
        abort_unless(InfluencerAccess::canMarkPaid($actor), 403);

        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 60) {
            throw ValidationException::withMessages(['payReference' => 'Enter the M-Pesa or bank reference (up to 60 characters).']);
        }

        $lines = InfluencerCommission::query()
            ->where('influencer_id', $influencer->id)
            ->whereIn('id', $lineIds)
            ->where('status', CommissionEntryStatus::Payable)
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['selected' => 'Select at least one payable commission line.']);
        }

        DB::transaction(function () use ($lines, $actor, $reference): void {
            foreach ($lines as $line) {
                $line->forceFill([
                    'status' => CommissionEntryStatus::Paid,
                    'paid_at' => now(),
                    'paid_by' => $actor->id,
                    'payment_reference' => $reference,
                ])->save();
            }
        });

        $total = (float) $lines->sum('commission_amount');
        $summary = 'Paid '.$lines->count().' commission '.str('line')->plural($lines->count()).' to '.$influencer->name
            .' (KES '.number_format($total, 2).', ref '.$reference.')';

        Audit::record($influencer, 'influencer.commission_paid', $summary, ['lines' => [null, $lines->pluck('id')->all()]]);

        Alerts::sendTravel(
            'influencer_commission',
            'Influencer commission paid',
            $summary.'.',
            route('travel.influencers.show', $influencer),
            $influencer->owner,
        );

        return $lines->count();
    }
}
