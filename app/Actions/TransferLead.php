<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\User;
use App\Support\Alerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TransferLead
{
    public function __construct(private AssignSalesRep $assignSalesRep) {}

    /**
     * Hand a lead to another salesperson. Its history stays attributed to
     * whoever did the work; open schedule items move to the new owner. When
     * the lead's registry property was represented by the previous owner,
     * the registry follows too (unless told not to).
     */
    public function handle(Lead $lead, User $to, User $by, string $reason, ?string $notes = null, bool $withRegistry = true, bool $notify = true): ?LeadTransfer
    {
        if ($lead->user_id === $to->id) {
            return null;
        }

        return DB::transaction(function () use ($lead, $to, $by, $reason, $notes, $withRegistry, $notify): LeadTransfer {
            $from = $lead->user;

            $transfer = $lead->transfers()->create([
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'transferred_by' => $by->id,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $lead->forceFill(['user_id' => $to->id])->save();
            $lead->followUps()->whereNull('completed_at')->update(['user_id' => $to->id]);

            $engagement = $lead->propertyEngagement;

            if ($withRegistry && $engagement && in_array($engagement->sales_rep_id, [null, $from->id], true)) {
                $this->assignSalesRep->handle($engagement, $to, $by, CarbonImmutable::now(), $reason, $notes);
            }

            if ($notify) {
                Alerts::send(
                    'ownership_transferred',
                    'Lead transferred to you',
                    "{$lead->business_name} moved from {$from->name} to {$to->name} by {$by->name}. Reason: ".LeadTransfer::reasonLabelFor($reason).'.',
                    route('leads.show', $lead),
                    $to,
                );
            }

            return $transfer;
        });
    }
}
