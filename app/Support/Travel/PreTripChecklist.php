<?php

namespace App\Support\Travel;

use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\BookingChecklistItem;
use App\Models\PackageBooking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The pre-trip checklist for a confirmed booking. Some items are worked out
 * live from the booking (confirmed, paid, driver, guide, completed); the
 * rest are ticked by hand and stored as BookingChecklistItem rows.
 */
class PreTripChecklist
{
    /** Items worked out from the booking; they cannot be ticked by hand. */
    public const Automatic = ['booking_confirmed', 'payment_confirmed', 'driver_assigned', 'guide_assigned', 'trip_completed'];

    /** Bookings within this many days of departure need every required item done. */
    public const ActionWindowDays = 7;

    /**
     * @return Collection<int, array{key: string, label: string, done: bool, automatic: bool, required: bool, by: ?string, at: ?CarbonInterface}>
     */
    public static function items(PackageBooking $booking): Collection
    {
        $stored = $booking->relationLoaded('checklistItems')
            ? $booking->checklistItems
            : $booking->checklistItems()->with('completer:id,name')->get();
        $stored = $stored->keyBy('item');

        return collect(PackageBooking::Checklist)->map(function (string $label, string $key) use ($booking, $stored): array {
            $automatic = in_array($key, self::Automatic, true);
            /** @var BookingChecklistItem|null $row */
            $row = $stored->get($key);

            return [
                'key' => $key,
                'label' => $label,
                'done' => $automatic ? self::automaticValue($booking, $key) : $row?->completed_at !== null,
                'automatic' => $automatic,
                'required' => self::isRequired($booking, $key),
                'by' => $automatic ? null : $row?->completer?->name,
                'at' => $automatic ? null : $row?->completed_at,
            ];
        })->values();
    }

    /**
     * Required items not done yet.
     *
     * @return Collection<int, array{key: string, label: string, done: bool, automatic: bool, required: bool, by: ?string, at: ?CarbonInterface}>
     */
    public static function outstanding(PackageBooking $booking): Collection
    {
        return self::items($booking)->filter(fn (array $item): bool => $item['required'] && ! $item['done'])->values();
    }

    /**
     * Confirmed, departing within the next week, and something required is not done.
     */
    public static function needsAction(PackageBooking $booking): bool
    {
        $start = $booking->departure?->starts_on;

        return $booking->status === TravelBookingStatus::Confirmed
            && $start !== null
            && $start->gte(today())
            && $start->lte(today()->addDays(self::ActionWindowDays))
            && self::outstanding($booking)->isNotEmpty();
    }

    /**
     * Ids of bookings that need pre-trip action, within a base query.
     *
     * @param  Builder<PackageBooking>  $base
     * @return list<int>
     */
    public static function idsNeedingAction(Builder $base): array
    {
        return (clone $base)
            ->where('status', TravelBookingStatus::Confirmed)
            ->whereHas('departure', fn (Builder $departure) => $departure
                ->whereDate('starts_on', '>=', today())
                ->whereDate('starts_on', '<=', today()->addDays(self::ActionWindowDays)))
            ->with(['departure.driver', 'departure.guide', 'package.driver', 'package.guide', 'driver', 'guide', 'checklistItems'])
            ->get()
            ->filter(fn (PackageBooking $booking): bool => self::needsAction($booking))
            ->pluck('id')
            ->all();
    }

    private static function isRequired(PackageBooking $booking, string $key): bool
    {
        return match ($key) {
            'guide_assigned' => (bool) $booking->package?->guide_required,
            'emergency_contact_confirmed', 'trip_completed' => false,
            default => true,
        };
    }

    private static function automaticValue(PackageBooking $booking, string $key): bool
    {
        return match ($key) {
            'booking_confirmed' => in_array($booking->status, [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed], true),
            'payment_confirmed' => $booking->payment_status === BookingPaymentStatus::Paid,
            'driver_assigned' => $booking->effectiveDriver() !== null,
            'guide_assigned' => $booking->effectiveGuide() !== null,
            'trip_completed' => $booking->status === TravelBookingStatus::Completed,
            default => false,
        };
    }
}
