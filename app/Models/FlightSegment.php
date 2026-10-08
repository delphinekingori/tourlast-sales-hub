<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of a synced flight booking (read-only).
 */
#[Fillable(['flight_booking_id', 'sequence', 'flight_number', 'airline_code', 'origin', 'destination', 'departure_at', 'arrival_at', 'cabin'])]
class FlightSegment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FlightBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}
