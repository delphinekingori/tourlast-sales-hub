<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\BookingSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Support\Travel\TravelAccess;
use Database\Factories\PackageBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A client's booking on a package departure. Records the package version
 * that was sold. Booking status and payment status are separate; the trip
 * status lives on the departure.
 *
 * amount_paid, amount_refunded and payment_status are worked out from the
 * payments and refunds by App\Actions\Travel\RefreshBookingPayment — never
 * set them by hand.
 */
#[Fillable([
    'reference', 'package_id', 'package_version_id', 'package_departure_id', 'travel_client_id', 'salesperson_id',
    'influencer_code_id', 'source', 'external_id', 'adults', 'children', 'infants', 'travelers', 'currency',
    'amount_total', 'amount_paid', 'amount_refunded', 'payment_status', 'status',
    'special_requirements', 'dietary_requirements', 'emergency_contact_name', 'emergency_contact_phone', 'notes',
    'driver_id', 'guide_id', 'hold_expires_at', 'confirmed_at', 'cancelled_at', 'completed_at', 'last_pretrip_alert_on', 'created_by',
])]
class PackageBooking extends Model
{
    /** @use HasFactory<PackageBookingFactory> */
    use HasFactory;

    /** Pre-trip checklist items, in order. */
    public const Checklist = [
        'booking_confirmed' => 'Booking confirmed',
        'payment_confirmed' => 'Payment confirmed',
        'customer_contacted' => 'Customer contacted',
        'travel_details_sent' => 'Travel details sent',
        'pickup_confirmed' => 'Pickup confirmed',
        'driver_assigned' => 'Driver assigned',
        'guide_assigned' => 'Guide assigned',
        'emergency_contact_confirmed' => 'Emergency contact confirmed',
        'reminder_sent' => 'Pre-trip reminder sent',
        'trip_completed' => 'Trip completed',
    ];

    protected static function booted(): void
    {
        static::saving(fn (PackageBooking $booking) => $booking->travelers = (int) $booking->adults + (int) $booking->children + (int) $booking->infants);
        static::creating(fn (PackageBooking $booking) => $booking->verification_token ??= Str::random(32));
    }

    /**
     * Public link that shows the booking's live status (the ticket QR code).
     */
    public function verificationUrl(): string
    {
        return route('bookings.verify', $this->verification_token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => BookingSource::class,
            'status' => TravelBookingStatus::class,
            'payment_status' => BookingPaymentStatus::class,
            'amount_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
            'adults' => 'integer',
            'children' => 'integer',
            'infants' => 'integer',
            'travelers' => 'integer',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_pretrip_alert_on' => 'date',
        ];
    }

    /**
     * Next reference such as TB-2026-0042 (also the paybill account number).
     */
    public static function nextReference(): string
    {
        $prefix = 'TB-'.now()->year.'-';
        $last = static::query()->where('reference', 'like', $prefix.'%')->max('reference');

        return $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'package_version_id');
    }

    /**
     * @return BelongsTo<PackageDeparture, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class, 'package_departure_id');
    }

    /**
     * @return BelongsTo<TravelClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(TravelClient::class, 'travel_client_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /**
     * @return BelongsTo<InfluencerCode, $this>
     */
    public function influencerCode(): BelongsTo
    {
        return $this->belongsTo(InfluencerCode::class);
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
     * The travelers, in the order they were entered.
     *
     * @return HasMany<PackageBookingGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(PackageBookingGuest::class)->orderBy('position');
    }

    /**
     * @return HasMany<TravelPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TravelPayment::class);
    }

    /**
     * @return HasMany<TravelRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(TravelRefund::class);
    }

    /**
     * @return HasMany<PackageCancellation, $this>
     */
    public function cancellations(): HasMany
    {
        return $this->hasMany(PackageCancellation::class);
    }

    /**
     * @return HasMany<BookingChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(BookingChecklistItem::class);
    }

    /**
     * @return MorphMany<InfluencerCommission, $this>
     */
    public function influencerCommissions(): MorphMany
    {
        return $this->morphMany(InfluencerCommission::class, 'bookable');
    }

    /**
     * The driver doing the trip: booking, else departure, else package.
     */
    public function effectiveDriver(): ?Driver
    {
        return $this->driver ?? $this->departure?->driver ?? $this->package?->driver;
    }

    /**
     * The guide on the trip: booking, else departure, else package.
     */
    public function effectiveGuide(): ?Guide
    {
        return $this->guide ?? $this->departure?->guide ?? $this->package?->guide;
    }

    public function balance(): float
    {
        return round(max(0, (float) $this->amount_total - (float) $this->amount_paid + (float) $this->amount_refunded), 2);
    }

    /**
     * Where the effective driver comes from: "booking", "departure", "package" or null.
     */
    public function driverSource(): ?string
    {
        return match (true) {
            $this->driver_id !== null => 'booking',
            $this->departure?->driver_id !== null => 'departure',
            $this->package?->driver_id !== null => 'package',
            default => null,
        };
    }

    /**
     * Where the effective guide comes from: "booking", "departure", "package" or null.
     */
    public function guideSource(): ?string
    {
        return match (true) {
            $this->guide_id !== null => 'booking',
            $this->departure?->guide_id !== null => 'departure',
            $this->package?->guide_id !== null => 'package',
            default => null,
        };
    }

    /**
     * Pending and its slot hold has not run out.
     */
    public function isHoldingSlots(): bool
    {
        return $this->status === TravelBookingStatus::Pending
            && ($this->hold_expires_at === null || $this->hold_expires_at->isFuture());
    }

    /**
     * May change this booking: its salesperson, the package owner, or a Travel manager.
     */
    public function isWorkableBy(User $user): bool
    {
        if (! TravelAccess::works($user)) {
            return false;
        }

        return TravelAccess::managesAll($user)
            || $this->salesperson_id === $user->id
            || $this->package?->owner_id === $user->id;
    }

    /**
     * May see the client's phone and email in full.
     */
    public function canSeeClientContact(User $user): bool
    {
        return TravelAccess::managesAll($user)
            || TravelAccess::handlesPayments($user)
            || $this->salesperson_id === $user->id
            || $this->package?->owner_id === $user->id;
    }

    /**
     * Pending bookings whose slot hold has not run out.
     *
     * @param  Builder<PackageBooking>  $query
     */
    #[Scope]
    protected function holdingSlots(Builder $query): void
    {
        $query->where('status', TravelBookingStatus::Pending)
            ->where(fn (Builder $query) => $query->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()));
    }

    /**
     * Bookings the user may see: their own sales and bookings on their packages.
     *
     * @param  Builder<PackageBooking>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::ManageTravelSales->value) || $user->can(Permission::ManageTravelPayments->value)) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('salesperson_id', $user->id)
            ->orWhereHas('package', fn (Builder $package) => $package->where('owner_id', $user->id)));
    }

    /**
     * @param  Builder<PackageBooking>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(fn (Builder $query) => $query
            ->where('reference', 'like', $like)
            ->orWhereHas('client', fn (Builder $client) => $client->search($term))
            ->orWhereHas('package', fn (Builder $package) => $package->where('name', 'like', $like)));
    }
}
