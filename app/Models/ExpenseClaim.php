<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExpenseClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Airtime claim, transport reimbursement (after the trip) or transport request (before the trip).
 */
#[Fillable([
    'user_id', 'type', 'month', 'amount', 'approved_amount', 'description', 'travel_date', 'ride_provider',
    'trip_reference', 'pickup', 'dropoff', 'distance_km', 'partner_account_id', 'lead_id', 'status',
    'current_step', 'paid_at', 'paid_by', 'payment_reference',
])]
class ExpenseClaim extends Model
{
    /** @use HasFactory<ExpenseClaimFactory> */
    use HasFactory;

    public const Types = [
        'airtime' => 'Airtime',
        'transport_reimbursement' => 'Transport reimbursement',
        'transport_request' => 'Transport request',
    ];

    public const RideProviders = [
        'bolt' => 'Bolt',
        'uber' => 'Uber',
        'taxi' => 'Taxi',
        'matatu' => 'Matatu / bus',
        'boda' => 'Boda boda',
        'own_vehicle' => 'Own vehicle (fuel)',
        'other' => 'Other',
    ];

    /** Ride-hailing apps whose trip ID and ride details are required. */
    public const AppProviders = ['bolt', 'uber'];

    public const StepLabels = ['manager' => 'Sales Manager', 'hr' => 'HR', 'finance' => 'Finance'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'travel_date' => 'immutable_date',
            'amount' => 'float',
            'approved_amount' => 'float',
            'distance_km' => 'float',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * Stored as a plain Y-m-d date for the first day of the month.
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function month(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value)->startOfMonth() : null,
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->startOfMonth()->toDateString(),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ClaimAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class);
    }

    /**
     * @return HasMany<ClaimApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ClaimApproval::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<PartnerAccount, $this>
     */
    public function partnerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function typeLabel(): string
    {
        return self::Types[$this->type] ?? $this->type;
    }

    public function isTransport(): bool
    {
        return str_starts_with($this->type, 'transport');
    }

    public function isRequest(): bool
    {
        return $this->type === 'transport_request';
    }

    public function usesRideApp(): bool
    {
        return in_array($this->ride_provider, self::AppProviders, true);
    }

    public function rideProviderLabel(): ?string
    {
        return $this->ride_provider ? (self::RideProviders[$this->ride_provider] ?? $this->ride_provider) : null;
    }

    /**
     * Approval steps for this kind of claim, in order.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return config('incentives.approval_steps.'.$this->type, ['finance']);
    }

    public function payableAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->amount);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Waiting for '.(self::StepLabels[$this->current_step] ?? 'approval'),
            'approved' => $this->isRequest() ? 'Approved · awaiting disbursement' : 'Approved · paid with statement',
            'paid' => $this->isRequest() ? 'Disbursed' : 'Paid',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'approved' => 'brand',
            'paid' => 'success',
            'rejected' => 'danger',
            default => 'warning',
        };
    }
}
