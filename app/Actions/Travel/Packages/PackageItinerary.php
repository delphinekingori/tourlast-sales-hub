<?php

namespace App\Actions\Travel\Packages;

use App\Models\PackageItineraryDay;
use App\Models\PackageVersion;

/**
 * Writes a version's itinerary days (only ever on an editable version).
 */
class PackageItinerary
{
    /**
     * @param  list<array<string, mixed>>  $days  normalised by PackageContent::normaliseItinerary
     */
    public static function replace(PackageVersion $version, array $days): void
    {
        $version->itineraryDays()->delete();

        foreach ($days as $day) {
            PackageItineraryDay::query()->create([...$day, 'package_version_id' => $version->id]);
        }

        $version->unsetRelation('itineraryDays');
    }
}
