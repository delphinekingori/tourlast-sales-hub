<?php

namespace App\Models;

use App\Enums\Travel\CancellationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to cancel a package booking, with the cancellation policy as it
 * stood when the request was made.
 */
#[Fillable([
    'package_booking_id', 'reason', 'policy_snapshot', 'refund_amount', 'status', 'requested_by',
    'decided_by', 'decided_at', 'decision_note', 'processed_by', 'processed_at',
])]
class PackageCancellation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CancellationStatus::class,
            'refund_amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'processed_at' => 'datetime',
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
     * @return HasMany<TravelRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(TravelRefund::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
