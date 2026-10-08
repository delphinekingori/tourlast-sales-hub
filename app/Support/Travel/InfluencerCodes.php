<?php

namespace App\Support\Travel;

use App\Enums\Travel\InfluencerCodeScope;
use App\Models\InfluencerCode;
use Carbon\CarbonInterface;

/**
 * Finds the influencer code a booking was made with, if it may earn on it.
 */
class InfluencerCodes
{
    /**
     * The code if it exists, covers this kind of booking, and was running on
     * the booking date. The booking cap is checked when commission is
     * written, not here, so the booking still records which code was used.
     *
     * @param  'packages'|'flights'  $kind
     */
    public static function resolve(?string $code, string $kind, ?CarbonInterface $on = null): ?InfluencerCode
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        $match = InfluencerCode::query()->where('code', $code)->first();
        $scope = $kind === 'flights' ? InfluencerCodeScope::Flights : InfluencerCodeScope::Packages;

        if (! $match || ! in_array($match->applies_to, [$scope, InfluencerCodeScope::All], true)) {
            return null;
        }

        return $match->isRunningOn($on ?? today()) ? $match : null;
    }
}
