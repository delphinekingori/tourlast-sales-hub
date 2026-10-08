<?php

namespace App\Actions\Travel\Bookings;

use App\Enums\Travel\GuestType;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records who is travelling on a package booking: one guest per traveler,
 * matching the booking's adults, children and infants. A name is required
 * for every guest; everything else is optional. Saving replaces the whole
 * list. Whoever may work the booking may change it; every change is audited
 * (names only — ID numbers never go into the audit log).
 */
class SaveBookingGuests
{
    /**
     * @param  array<int, array<string, mixed>>  $guests
     */
    public function handle(User $actor, PackageBooking $booking, array $guests): void
    {
        abort_unless($booking->isWorkableBy($actor), 403, 'You cannot change this booking.');

        $rows = self::validated($guests, (int) $booking->adults, (int) $booking->children, (int) $booking->infants);
        $before = $booking->guests()->pluck('full_name')->all();
        $hadGuests = $before !== [];

        DB::transaction(fn () => self::write($booking, $rows));

        $after = array_column($rows, 'full_name');
        Audit::record(
            $booking,
            $hadGuests ? 'booking.guests_updated' : 'booking.guests_added',
            ($hadGuests ? 'Guest details updated on ' : 'Guest details added to ').$booking->reference.': '.implode(', ', $after),
            $before === $after ? [] : ['guests' => [implode(', ', $before) ?: null, implode(', ', $after)]],
        );
    }

    /**
     * Validates the guest rows against the booking's traveler counts and
     * returns them cleaned up, in order.
     *
     * @param  array<int, mixed>  $guests
     * @return list<array{full_name: string, type: string, is_booker: bool, date_of_birth: ?string, nationality: ?string, id_number: ?string, phone: ?string, email: ?string, special_requirements: ?string}>
     */
    public static function validated(mixed $guests, int $adults, int $children, int $infants): array
    {
        $guests = is_array($guests) ? array_values($guests) : $guests;
        $travelers = $adults + $children + $infants;

        $data = Validator::make(['guests' => $guests], [
            'guests' => ['present', 'array'],
            'guests.*' => ['array'],
            'guests.*.full_name' => ['required', 'string', 'max:120'],
            'guests.*.type' => ['required', Rule::enum(GuestType::class)],
            'guests.*.is_booker' => ['nullable', 'boolean'],
            'guests.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'guests.*.nationality' => ['nullable', 'string', 'max:60'],
            'guests.*.id_number' => ['nullable', 'string', 'max:40'],
            'guests.*.phone' => ['nullable', 'string', 'max:30'],
            'guests.*.email' => ['nullable', 'email', 'max:160'],
            'guests.*.special_requirements' => ['nullable', 'string', 'max:500'],
        ], [
            'guests.*.full_name.required' => 'Enter this guest’s full name.',
            'guests.*.date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
        ], [
            'guests.*.email' => 'email',
            'guests.*.date_of_birth' => 'date of birth',
        ])->validate();

        $rows = $data['guests'];

        if (count($rows) !== $travelers) {
            throw ValidationException::withMessages(['guests' => "Enter one guest for each of the {$travelers} ".($travelers === 1 ? 'traveler' : 'travelers').' ('.count($rows).' entered).']);
        }

        $types = array_count_values(array_column($rows, 'type'));

        if (($types['adult'] ?? 0) !== $adults || ($types['child'] ?? 0) !== $children || ($types['infant'] ?? 0) !== $infants) {
            throw ValidationException::withMessages(['guests' => 'The guests must match the booking: '.$adults.' '.($adults === 1 ? 'adult' : 'adults').', '.$children.' '.($children === 1 ? 'child' : 'children').', '.$infants.' '.($infants === 1 ? 'infant' : 'infants').'.']);
        }

        if (count(array_filter($rows, fn (array $row): bool => (bool) ($row['is_booker'] ?? false))) > 1) {
            throw ValidationException::withMessages(['guests' => 'Only one guest can be the person who booked.']);
        }

        $clean = fn (mixed $value): ?string => filled($value) ? trim((string) $value) : null;

        return array_map(fn (array $row): array => [
            'full_name' => trim((string) $row['full_name']),
            'type' => (string) $row['type'],
            'is_booker' => (bool) ($row['is_booker'] ?? false),
            'date_of_birth' => $clean($row['date_of_birth'] ?? null),
            'nationality' => $clean($row['nationality'] ?? null),
            'id_number' => $clean($row['id_number'] ?? null),
            'phone' => $clean($row['phone'] ?? null),
            'email' => ($email = $clean($row['email'] ?? null)) ? mb_strtolower($email) : null,
            'special_requirements' => $clean($row['special_requirements'] ?? null),
        ], $rows);
    }

    /**
     * Replaces the booking's guests with validated rows. Call inside a transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function write(PackageBooking $booking, array $rows): void
    {
        $booking->guests()->delete();

        foreach ($rows as $index => $row) {
            $booking->guests()->create($row + ['position' => $index + 1]);
        }
    }
}
