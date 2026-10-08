<?php

namespace App\Models;

use App\Enums\Travel\ResourceStatus;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver who can be assigned to a package, a departure or a booking
 * (the most specific assignment wins).
 */
#[Fillable(['name', 'phone', 'vehicle', 'vehicle_registration', 'license_number', 'travel_provider_id', 'status', 'notes'])]
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }
}
