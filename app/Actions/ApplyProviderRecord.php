<?php

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\OnboardingStatus;
use App\Incentives\AccountPoints;
use App\Integrations\Tourlast\ProviderRecord;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\ReferralCode;
use App\Support\Alerts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Store one provider reported by tourlast.com and work out who gets credit.
 *
 * Rules:
 *  - Credit goes to the owner of the referral code that came with the signup.
 *  - An admin's manual assignment is never overwritten by later syncs.
 *  - Schedule 1: a provider counts as onboarded on its Activation Date, the first
 *    time it is live (Active) on tourlast.com. If it is later rejected, the credit
 *    is withdrawn. An Inactive property went live and later stopped: it keeps its
 *    credit and only drops out of the live counts.
 *  - Every property is grouped into its legal Partner Account, which carries the points.
 *  - A row the source app reports as deleted archives the property instead of
 *    updating it, and never withdraws credit. A later live row restores it.
 */
class ApplyProviderRecord
{
    public const Created = 'created';

    public const Updated = 'updated';

    public const Unchanged = 'unchanged';

    public const Deleted = 'deleted';

    public function __construct(
        private AccountPoints $accountPoints,
        private SyncOnboardingToRegistry $syncRegistry,
        private DeleteOnboarding $deleteOnboarding,
        private RestoreOnboarding $restoreOnboarding,
    ) {}

    public function handle(ProviderRecord $record, string $source): string
    {
        return DB::transaction(function () use ($record, $source): string {
            $onboarding = Onboarding::withTrashed()->lockForUpdate()->firstOrNew(['tourlast_property_id' => $record->propertyId]);
            $isNew = ! $onboarding->exists;
            $previousStatus = $onboarding->status;
            $deletionChanged = $record->isDeleted !== $onboarding->trashed();

            if (! $deletionChanged && $onboarding->source_updated_at && $record->updatedAt && $record->updatedAt->lt($onboarding->source_updated_at)) {
                return self::Unchanged;
            }

            if ($record->isDeleted) {
                return $this->archive($onboarding, $record);
            }

            $restored = $onboarding->trashed();

            if ($restored) {
                $this->restoreOnboarding->handle($onboarding);
            }

            $onboarding->fill([
                'ref_code' => $record->refCode ?? $onboarding->ref_code,
                'tourlast_account_id' => $record->accountId,
                'property_name' => $record->propertyName,
                'legal_name' => $record->legalName,
                'property_type' => $record->propertyType,
                'category' => $record->category,
                'inventory_count' => $record->inventoryCount ?? $onboarding->inventory_count,
                'location' => $record->location,
                'contact_name' => $record->contactName,
                'contact_phone' => $record->contactPhone,
                'contact_email' => $record->contactEmail,
                'status' => $record->status,
                'submitted_at' => $record->submittedAt ?? $onboarding->submitted_at ?? now(),
                'approved_at' => $record->approvedAt ?? $onboarding->approved_at,
                'active_at' => $record->activeAt ?? $onboarding->active_at,
                'inactive_at' => $record->status === OnboardingStatus::Inactive
                    ? $record->inactiveAt ?? $onboarding->inactive_at ?? $record->updatedAt ?? now()
                    : null,
                'rejected_at' => $record->rejectedAt,
                'first_booking_at' => $onboarding->first_booking_at ?? $record->firstBookingAt,
                'source_updated_at' => $this->sourceUpdatedAt($onboarding, $record),
                'source_payload' => $record->raw,
            ]);

            // The Hub's own code wins: a feed row without one never clears credit
            // the Hub already gave, and a row that carries one re-points attribution
            // (source apps copy the list from GET /integrations/tourlast/ref-codes).
            if ($onboarding->attribution !== 'manual' && $record->refCode) {
                $referralCode = ReferralCode::query()->where('code', $record->refCode)->first();
                $onboarding->referral_code_id = $referralCode?->id;
                $onboarding->user_id = $referralCode?->user_id;
                $onboarding->attribution = 'referral';
            }

            $this->applyCredit($onboarding, $record);
            $firstBookingIsNew = $onboarding->isDirty('first_booking_at') && $onboarding->first_booking_at !== null;

            if (! $isNew && ! $onboarding->isDirty() && ! $restored) {
                return self::Unchanged;
            }

            $onboarding->save();

            if ($previousStatus !== $record->status) {
                $onboarding->statusChanges()->create([
                    'from_status' => $previousStatus,
                    'to_status' => $record->status,
                    'source' => $source,
                    'occurred_at' => $this->statusTime($record) ?? now(),
                ]);
            }

            $this->linkLead($onboarding);
            $this->syncRegistry->handle($onboarding);
            $this->accountPoints->linkOnboarding($onboarding);
            $this->alert($onboarding, $isNew, $previousStatus, $firstBookingIsNew);

            return $isNew ? self::Created : self::Updated;
        });
    }

    /**
     * The source app no longer lists this property: archive it, its mirror lead
     * and its registry record. Nothing is applied from the tombstone's own
     * fields, so a deletion never moves status or withdraws credit.
     */
    private function archive(Onboarding $onboarding, ProviderRecord $record): string
    {
        if (! $onboarding->exists || $onboarding->trashed()) {
            return self::Unchanged;
        }

        $onboarding->forceFill([
            'source_updated_at' => $this->sourceUpdatedAt($onboarding, $record),
            'source_payload' => $record->raw,
        ])->save();

        $this->deleteOnboarding->handle($onboarding, $record->deletedAt);

        return self::Deleted;
    }

    /**
     * The row version this update carries, kept from moving backwards when a
     * delete or restore signal is applied ahead of an older row.
     */
    private function sourceUpdatedAt(Onboarding $onboarding, ProviderRecord $record): CarbonInterface
    {
        $updatedAt = $record->updatedAt ?? $record->deletedAt ?? now();

        if ($onboarding->source_updated_at && $onboarding->source_updated_at->greaterThan($updatedAt)) {
            return $onboarding->source_updated_at->toImmutable();
        }

        return $updatedAt;
    }

    /**
     * Smart Alerts for management (and the salesperson concerned).
     */
    private function alert(Onboarding $onboarding, bool $isNew, ?OnboardingStatus $previousStatus, bool $firstBookingIsNew): void
    {
        $salesperson = $onboarding->user;
        $who = $salesperson ? ' by '.$salesperson->name : '';
        $url = $onboarding->partner_account_id ? route('accounts.show', $onboarding->partner_account_id) : route('partners.index');

        if ($isNew) {
            $salesperson
                ? Alerts::send('property_referred', 'New property referred', "{$onboarding->property_name} signed up on tourlast.com through the referral link of {$salesperson->name}.", $url, $salesperson)
                : Alerts::send('onboarding_submitted', 'New onboarding submitted', "{$onboarding->property_name} signed up without a referral code and needs assigning.", route('onboardings.unattributed'));
        }

        if ($previousStatus !== $onboarding->status) {
            match (true) {
                $onboarding->status === OnboardingStatus::Approved => Alerts::send('partner_approved', 'Partner approved', "{$onboarding->property_name}{$who} was approved by Tourlast.", $url, $salesperson),
                $previousStatus === OnboardingStatus::Active && in_array($onboarding->status, [OnboardingStatus::Rejected, OnboardingStatus::Inactive], true) => Alerts::send('property_inactive', 'Property inactive', "{$onboarding->property_name}{$who} is no longer live on tourlast.com.", $url, $salesperson),
                $onboarding->status === OnboardingStatus::Rejected => Alerts::send('partner_rejected', 'Partner rejected', "{$onboarding->property_name}{$who} was rejected by Tourlast.", $url, $salesperson),
                default => null,
            };
        }

        if ($firstBookingIsNew) {
            Alerts::send('first_booking', 'First booking received', "{$onboarding->property_name}{$who} received its first booking on tourlast.com.", $url, $salesperson);
        }
    }

    private function applyCredit(Onboarding $onboarding, ProviderRecord $record): void
    {
        if ($record->status->isOnboarded()) {
            $onboarding->credited_at ??= $record->activeAt ?? now();

            return;
        }

        if ($record->status === OnboardingStatus::Rejected) {
            $onboarding->credited_at = null;
        }
    }

    private function statusTime(ProviderRecord $record): ?CarbonImmutable
    {
        return match ($record->status) {
            OnboardingStatus::Approved => $record->approvedAt,
            OnboardingStatus::Active => $record->activeAt,
            OnboardingStatus::Inactive => $record->inactiveAt,
            OnboardingStatus::Rejected => $record->rejectedAt,
            default => $record->submittedAt,
        } ?? $record->updatedAt;
    }

    /**
     * Connect the onboarding to the credited salesperson's lead with the same
     * email or phone, and mark the lead Onboarded once the provider is live.
     */
    private function linkLead(Onboarding $onboarding): void
    {
        if (! $onboarding->user_id || (! $onboarding->contact_email && ! $onboarding->contact_phone)) {
            return;
        }

        $lead = Lead::query()->where('onboarding_id', $onboarding->id)->first()
            ?? Lead::query()
                ->where('user_id', $onboarding->user_id)
                ->whereNull('onboarding_id')
                ->where(function ($query) use ($onboarding): void {
                    if ($onboarding->contact_email) {
                        $query->orWhere('contact_email', $onboarding->contact_email);
                    }
                    if ($onboarding->contact_phone) {
                        $query->orWhere('contact_phone', $onboarding->contact_phone);
                    }
                })
                ->oldest()
                ->first();

        if (! $lead) {
            return;
        }

        $lead->onboarding_id = $onboarding->id;

        if ($onboarding->status->isOnboarded()) {
            if ($lead->status !== LeadStatus::Onboarded) {
                Alerts::send('deal_won', 'Deal won', "{$lead->business_name} is live on tourlast.com. Lead closed as onboarded for {$onboarding->user?->name}.", route('leads.show', $lead), $onboarding->user);
            }
            $lead->status = LeadStatus::Onboarded;
        } elseif ($lead->status === LeadStatus::Onboarded) {
            $lead->status = LeadStatus::LinkSent;
        }

        $lead->save();
    }
}
