<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\FlightBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Read-only copy of a booking from Tourlast Flights Super Admin, the source
 * of truth. Only the flights sync writes these rows; the Hub never changes a
 * booking, its status, payment or refund. Statuses are kept exactly as the
 * Flights system sends them.
 */
#[Fillable([
    'source_system', 'external_id', 'booking_reference', 'pnr', 'customer_name', 'customer_email', 'customer_phone',
    'airline_code', 'airline_name', 'origin', 'destination', 'trip_type', 'cabin', 'departure_at', 'arrival_at',
    'return_at', 'passenger_count', 'currency', 'fare_amount', 'total_amount', 'markup_amount', 'booking_status',
    'payment_status', 'cancellation_status', 'cancelled_at', 'cancellation_reason', 'refund_status', 'refund_amount',
    'refund_method', 'refund_requested_at', 'refund_completed_at', 'booked_at', 'agent_reference', 'salesperson_id',
    'promo_code', 'influencer_code_id', 'source_updated_at', 'last_synced_at', 'sync_status', 'sync_error', 'payload',
])]
class FlightBooking extends Model
{
    /** @use HasFactory<FlightBookingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
            'return_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_requested_at' => 'datetime',
            'refund_completed_at' => 'datetime',
            'booked_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'fare_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'passenger_count' => 'integer',
            'payload' => 'array',
        ];
    }

    /**
     * @return HasMany<FlightSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(FlightSegment::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<FlightPassenger, $this>
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(FlightPassenger::class);
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
     * @return MorphMany<InfluencerCommission, $this>
     */
    public function influencerCommissions(): MorphMany
    {
        return $this->morphMany(InfluencerCommission::class, 'bookable');
    }

    public function route(): string
    {
        return trim(($this->origin ?? '?').' → '.($this->destination ?? '?'));
    }

    /**
     * Link to the booking in Flights Super Admin.
     */
    public function adminUrl(): string
    {
        return str_replace('{id}', rawurlencode($this->external_id), (string) config('travel.flights.admin_booking_url'));
    }

    /**
     * Pill tone for a status sent by Flights (kept verbatim): confirmed,
     * ticketed, completed, paid → success; pending, processing → warning;
     * cancelled, failed, rejected → danger; anything else neutral.
     */
    public static function statusTone(?string $status): string
    {
        return match (true) {
            in_array($status, ['confirmed', 'ticketed', 'issued', 'completed', 'paid', 'refunded'], true) => 'success',
            in_array($status, ['pending', 'processing', 'awaiting_payment', 'requested', 'on_hold'], true) => 'warning',
            in_array($status, ['cancelled', 'canceled', 'failed', 'rejected', 'void', 'voided'], true) => 'danger',
            default => 'neutral',
        };
    }

    /**
     * "awaiting_payment" → "Awaiting payment" (the value itself is never changed).
     */
    public static function statusLabel(?string $status): string
    {
        return $status ? ucfirst(str_replace(['_', '-'], ' ', $status)) : '—';
    }

    /**
     * Customer email and phone in full only for the selling travel
     * salesperson and Travel managers; everyone else sees them masked.
     */
    public function contactVisibleTo(User $viewer): bool
    {
        return $viewer->can(Permission::ManageTravelSales->value)
            || ($this->salesperson_id !== null && $this->salesperson_id === $viewer->id);
    }

    public function maskedEmail(): ?string
    {
        if (blank($this->customer_email) || ! str_contains($this->customer_email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $this->customer_email, 2);

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    public function maskedPhone(): ?string
    {
        return TravelClient::mask($this->customer_phone);
    }

    public function isCancelled(): bool
    {
        return filled($this->cancellation_status) || $this->cancelled_at !== null;
    }

    public function hasRefund(): bool
    {
        return filled($this->refund_status);
    }

    /**
     * @param  Builder<FlightBooking>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('departure_at', '>=', now())->whereNull('cancelled_at')->whereNull('cancellation_status');
    }

    /**
     * @param  Builder<FlightBooking>  $query
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
            ->where('booking_reference', 'like', $like)
            ->orWhere('pnr', 'like', $like)
            ->orWhere('external_id', 'like', $like)
            ->orWhere('customer_name', 'like', $like)
            ->orWhere('customer_email', 'like', $like)
            ->orWhereHas('passengers', fn (Builder $passengers) => $passengers->where('name', 'like', $like)->orWhere('ticket_number', 'like', $like)));
    }
}
