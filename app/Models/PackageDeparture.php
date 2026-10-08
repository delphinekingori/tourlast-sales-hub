<?php

namespace App\Models;

use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TripStatus;
use Database\Factories\PackageDepartureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dated run of a package with limited slots (package inventory).
 *
 * Slots: "sold" = travelers on confirmed or completed bookings; "reserved" =
 * travelers on pending bookings whose hold has not expired. Available =
 * capacity − sold − reserved. Never stored, always counted from bookings.
 */
#[Fillable([
    'package_id', 'starts_on', 'start_time', 'ends_on', 'end_time', 'capacity', 'waitlist_count', 'status', 'trip_status',
    'driver_id', 'guide_id', 'allow_overbooking', 'overbooking_approved_by', 'last_availability_alert', 'notes', 'created_by',
])]
class PackageDeparture extends Model
{
    /** @use HasFactory<PackageDepartureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'capacity' => 'integer',
            'waitlist_count' => 'integer',
            'status' => DepartureStatus::class,
            'trip_status' => TripStatus::class,
            'allow_overbooking' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return HasMany<PackageBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(PackageBooking::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Guide, $this>
     */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    /**
     * "12 Oct 2026" or "12–14 Oct 2026".
     */
    public function dateLabel(): string
    {
        if ($this->ends_on === null || $this->ends_on->isSameDay($this->starts_on)) {
            return $this->starts_on->format('j M Y');
        }

        return $this->starts_on->isSameMonth($this->ends_on)
            ? $this->starts_on->format('j').'–'.$this->ends_on->format('j M Y')
            : $this->starts_on->format('j M').' – '.$this->ends_on->format('j M Y');
    }

    /**
     * Bookings can still be taken: open status and not yet started.
     */
    public function isBookable(): bool
    {
        return ! in_array($this->status, [DepartureStatus::Closed, DepartureStatus::Cancelled], true)
            && $this->starts_on->gte(today());
    }

    public function soldSlots(): int
    {
        if (array_key_exists('sold_slots', $this->attributes)) {
            return (int) $this->attributes['sold_slots'];
        }

        return (int) $this->bookings()->whereIn('status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])->sum('travelers');
    }

    public function reservedSlots(): int
    {
        if (array_key_exists('reserved_slots', $this->attributes)) {
            return (int) $this->attributes['reserved_slots'];
        }

        return (int) $this->bookings()->holdingSlots()->sum('travelers');
    }

    public function availableSlots(): int
    {
        return max(0, $this->capacity - $this->soldSlots() - $this->reservedSlots());
    }

    /**
     * Open / Nearly full / Full worked out from the slots; Closed and
     * Cancelled are set by hand and always win.
     */
    public function availabilityStatus(): DepartureStatus
    {
        if (in_array($this->status, [DepartureStatus::Closed, DepartureStatus::Cancelled], true)) {
            return $this->status;
        }

        $taken = $this->soldSlots() + $this->reservedSlots();

        return match (true) {
            $taken >= $this->capacity => DepartureStatus::Full,
            $this->capacity > 0 && $taken / $this->capacity >= (float) config('travel.nearly_full_ratio') => DepartureStatus::NearlyFull,
            default => DepartureStatus::Open,
        };
    }

    /**
     * Adds sold_slots and reserved_slots sums (avoids N+1 on tables).
     *
     * @param  Builder<PackageDeparture>  $query
     */
    #[Scope]
    protected function withSlotCounts(Builder $query): void
    {
        $query->withSum(['bookings as sold_slots' => fn (Builder $bookings) => $bookings
            ->whereIn('status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])], 'travelers')
            ->withSum(['bookings as reserved_slots' => fn (Builder $bookings) => $bookings
                ->where('status', TravelBookingStatus::Pending)
                ->where(fn (Builder $query) => $query->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()))], 'travelers');
    }

    /**
     * Departures overlapping a date range (for driver/guide clash checks).
     *
     * @param  Builder<PackageDeparture>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, string $from, string $to): void
    {
        $query->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from);
    }
}
