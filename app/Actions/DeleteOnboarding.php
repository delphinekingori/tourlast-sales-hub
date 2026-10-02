<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\Onboarding;
use App\Support\Alerts;
use Carbon\CarbonInterface;

/**
 * Archive a property the source app no longer lists: its onboarding, the lead
 * that mirrors it and its registry record all become soft deletes, so nothing
 * is lost if tourlast.com brings the property back.
 *
 * Credit already earned is never clawed back: only a Rejected outcome does
 * that, and a deletion is not a review outcome.
 */
class DeleteOnboarding
{
    /**
     * @param  ?CarbonInterface  $deletedAt  when the source app deleted it, or now
     */
    public function handle(Onboarding $onboarding, ?CarbonInterface $deletedAt = null): Onboarding
    {
        $onboarding->forceFill(['deleted_at' => $deletedAt ?? now()])->save();

        Lead::query()->where('onboarding_id', $onboarding->id)->delete();
        $onboarding->propertyEngagement?->delete();

        $salesperson = $onboarding->user;
        $who = $salesperson ? ' by '.$salesperson->name : '';

        Alerts::send(
            'property_deleted',
            'Property deleted',
            "{$onboarding->property_name}{$who} was deleted on tourlast.com. The credit it earned is kept.",
            $onboarding->partner_account_id ? route('accounts.show', $onboarding->partner_account_id) : route('partners.index'),
            $salesperson,
        );

        return $onboarding;
    }
}
