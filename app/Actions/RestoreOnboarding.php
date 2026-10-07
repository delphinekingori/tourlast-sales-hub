<?php

namespace App\Actions;

use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Support\Alerts;

/**
 * Put back a property the source app lists again, together with the lead and
 * registry record that were archived alongside it.
 */
class RestoreOnboarding
{
    public function handle(Onboarding $onboarding): Onboarding
    {
        $onboarding->restore();

        Lead::query()->where('onboarding_id', $onboarding->id)->restore();

        if ($onboarding->property_engagement_id) {
            PropertyEngagement::withTrashed()->find($onboarding->property_engagement_id)?->restore();
        }

        $salesperson = $onboarding->user;
        $who = $salesperson ? ' by '.$salesperson->name : '';

        Alerts::send(
            'property_restored',
            'Property restored',
            "{$onboarding->property_name}{$who} is listed again on tourlast.com and is back in the Hub.",
            $onboarding->partner_account_id ? route('accounts.show', $onboarding->partner_account_id) : route('partners.index'),
            $salesperson,
        );

        return $onboarding;
    }
}
