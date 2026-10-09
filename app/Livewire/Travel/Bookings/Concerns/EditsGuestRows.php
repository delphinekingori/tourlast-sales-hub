<?php

namespace App\Livewire\Travel\Bookings\Concerns;

use App\Enums\Travel\GuestType;
use App\Models\PackageBooking;

/**
 * Keeps one guest row per traveler: adults first, then children, then
 * infants. Rows already typed in are kept when the counts change.
 */
trait EditsGuestRows
{
    /** Most rows the form will draw (the action enforces the real limits). */
    protected int $maxGuestRows = 60;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function resizeGuestRows(array $rows, int $adults, int $children, int $infants): array
    {
        $resized = [];
        $room = $this->maxGuestRows;

        foreach ([GuestType::Adult->value => $adults, GuestType::Child->value => $children, GuestType::Infant->value => $infants] as $type => $wanted) {
            $wanted = max(0, min($wanted, $room));
            $room -= $wanted;
            $existing = array_values(array_filter($rows, fn (array $row): bool => ($row['type'] ?? null) === $type));

            for ($i = 0; $i < $wanted; $i++) {
                $resized[] = $existing[$i] ?? self::blankGuest($type);
            }
        }

        return $resized;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function blankGuest(string $type): array
    {
        return [
            'full_name' => '',
            'type' => $type,
            'is_booker' => false,
            'date_of_birth' => '',
            'nationality' => '',
            'id_number' => '',
            'phone' => '',
            'email' => '',
            'special_requirements' => '',
        ];
    }

    /**
     * The booking's saved guests as form rows, padded to its traveler counts.
     *
     * @return list<array<string, mixed>>
     */
    protected function guestRowsFor(PackageBooking $booking): array
    {
        $rows = $booking->guests()->get()->map(fn ($guest): array => [
            'full_name' => $guest->full_name,
            'type' => $guest->type->value,
            'is_booker' => $guest->is_booker,
            'date_of_birth' => $guest->date_of_birth?->toDateString() ?? '',
            'nationality' => $guest->nationality ?? '',
            'id_number' => $guest->id_number ?? '',
            'phone' => $guest->phone ?? '',
            'email' => $guest->email ?? '',
            'special_requirements' => $guest->special_requirements ?? '',
        ])->all();

        return $this->resizeGuestRows($rows, (int) $booking->adults, (int) $booking->children, (int) $booking->infants);
    }
}
