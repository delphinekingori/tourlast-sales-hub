<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a package version's itinerary.
 */
#[Fillable(['package_version_id', 'day_number', 'title', 'description', 'activities', 'meals', 'accommodation', 'transport', 'notes'])]
class PackageItineraryDay extends Model
{
    public const Meals = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meals' => 'array',
            'day_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'package_version_id');
    }
}
