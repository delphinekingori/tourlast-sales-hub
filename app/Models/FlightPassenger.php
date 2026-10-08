<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passenger on a synced flight booking (read-only; name, type and ticket only).
 */
#[Fillable(['flight_booking_id', 'name', 'passenger_type', 'ticket_number'])]
class FlightPassenger extends Model
{
    /**
     * @return BelongsTo<FlightBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}
