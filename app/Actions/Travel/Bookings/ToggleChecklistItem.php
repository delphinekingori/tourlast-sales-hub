<?php

namespace App\Actions\Travel\Bookings;

use App\Enums\Travel\TravelBookingStatus;
use App\Models\BookingChecklistItem;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PreTripChecklist;
use Illuminate\Validation\ValidationException;

/**
 * Ticks or unticks a hand-checked pre-trip item on a confirmed booking.
 * Items worked out from the booking (confirmed, paid, driver, guide,
 * completed) can't be ticked by hand.
 */
class ToggleChecklistItem
{
    public function handle(User $actor, PackageBooking $booking, string $item, ?string $note = null): bool
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if (! array_key_exists($item, PackageBooking::Checklist) || in_array($item, PreTripChecklist::Automatic, true)) {
            throw ValidationException::withMessages(['item' => 'That item is filled in automatically.']);
        }

        if (! in_array($booking->status, [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed], true)) {
            throw ValidationException::withMessages(['item' => 'The checklist starts once the booking is confirmed.']);
        }

        $row = BookingChecklistItem::query()->firstOrNew(['package_booking_id' => $booking->id, 'item' => $item]);
        $done = $row->completed_at === null;

        $row->fill([
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $actor->id : null,
            'note' => $note ?? $row->note,
        ])->save();

        Audit::record($booking, 'booking.checklist', ($done ? 'Ticked' : 'Unticked').' “'.PackageBooking::Checklist[$item].'” on '.$booking->reference);

        return $done;
    }
}
