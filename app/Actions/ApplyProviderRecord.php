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
use Illuminate\Support\Facades\DB;

/**
 * Store one provider reported by tourlast.com and work out who gets credit.
 *
 * Rules:
 *  - Credit goes to the owner of the referral code that came with the signup.
 *  - An admin's manual assignment is never overwritten by later syncs.
 *  - Schedule 1: a provider counts as onboarded on its Activation Date, the first
 *    time it is live (Active) on tourlast.com. If it is later rejected, the credit
 *    is withdrawn.
 *  - Every property is grouped into its legal Partner Account, which carries the points.
 */
class ApplyProviderRecord
{
    public const Created = 'created';

    public const Updated = 'updated';

    public const Unchanged = 'unchanged';

    public function __construct(
        private AccountPoints $accountPoints,
        private SyncOnboardingToRegistry $syncRegistry,
    ) {}

    public function handle(ProviderRecord $record, string $source): string
    {
        return DB::transaction(function () use ($record, $source): string {
            $onboarding = Onboarding::query()->lockForUpdate()->firstOrNew(['tourlast_property_id' => $record->propertyId]);
            $isNew = ! $onboarding->exists;
            $previousStatus = $onboarding->status;

            if ($onboarding->source_updated_at && $record->updatedAt && $record->updatedAt->lt($onboarding->source_updated_at)) {
                return self::Unchanged;
            }

            $onboarding->fill([
                'ref_code' => $record->refCode,
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
                'rejected_at' => $record->rejectedAt,
                'first_booking_at' => $onboarding->first_booking_at ?? $record->firstBookingAt,
                'source_updated_at' => $record->updatedAt ?? now(),
                'source_payload' => $record->raw,
            ]);

            if ($onboarding->attribution !== 'manual') {
                $referralCode = $record->refCode ? ReferralCode::query()->where('code', $record->refCode)->first() : null;
                $onboarding->referral_code_id = $referralCode?->id;
                $onboarding->user_id = $referralCode?->user_id;
                $onboarding->attribution = 'referral';
            }

            $this->applyCredit($onboarding, $record);
            $firstBookingIsNew = $onboarding->isDirty('first_booking_at') && $onboarding->first_booking_at !== null;

            if (! $isNew && ! $onboarding->isDirty()) {
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
                $onboarding->status === OnboardingStatus::Rejected && $previousStatus === OnboardingStatus::Active => Alerts::send('property_inactive', 'Property inactive', "{$onboarding->property_name}{$who} is no longer live on tourlast.com.", $url, $salesperson),
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
