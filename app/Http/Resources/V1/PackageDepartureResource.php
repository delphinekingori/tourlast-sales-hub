<?php

namespace App\Http\Resources\V1;

use App\Models\PackageDeparture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dated run of a package with its slots: sold (confirmed/completed),
 * reserved (pending bookings still on hold) and available.
 *
 * @mixin PackageDeparture
 */
class PackageDepartureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $availability = $this->availabilityStatus();

        return [
            'id' => $this->id,
            'package' => $this->whenLoaded('package', fn () => ['id' => $this->package->id, 'reference' => $this->package->reference, 'name' => $this->package->name]),
            'starts_on' => $this->starts_on?->toDateString(),
            'start_time' => $this->start_time,
            'ends_on' => $this->ends_on?->toDateString(),
            'end_time' => $this->end_time,
            'capacity' => $this->capacity,
            'sold' => $this->soldSlots(),
            'reserved' => $this->reservedSlots(),
            'available' => $this->availableSlots(),
            'waitlist' => $this->waitlist_count,
            'status' => $availability->value,
            'status_label' => $availability->label(),
            'trip_status' => $this->trip_status?->value,
            'trip_status_label' => $this->trip_status?->label(),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->name] : null),
            'guide' => $this->whenLoaded('guide', fn () => $this->guide ? ['id' => $this->guide->id, 'name' => $this->guide->name] : null),
        ];
    }
}
