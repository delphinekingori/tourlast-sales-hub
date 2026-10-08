<?php

namespace App\Models;

use App\Enums\Travel\GuestType;
use Database\Factories\PackageBookingGuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One traveler on a package booking. The ID / passport number is personal
 * data and is encrypted at rest. Written by App\Actions\Travel\Bookings\SaveBookingGuests.
 */
#[Fillable([
    'package_booking_id', 'position', 'full_name', 'type', 'is_booker', 'date_of_birth',
    'nationality', 'id_number', 'phone', 'email', 'special_requirements',
])]
class PackageBookingGuest extends Model
{
    /** @use HasFactory<PackageBookingGuestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GuestType::class,
            'is_booker' => 'boolean',
            'position' => 'integer',
            'date_of_birth' => 'date',
            'id_number' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<PackageBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PackageBooking::class, 'package_booking_id');
    }

    /**
     * The ID / passport number with all but the last three characters hidden.
     */
    public function maskedIdNumber(): ?string
    {
        if (blank($this->id_number)) {
            return null;
        }

        $length = mb_strlen($this->id_number);

        return $length <= 3 ? str_repeat('*', $length) : Str::mask($this->id_number, '*', 0, $length - 3);
    }
}
