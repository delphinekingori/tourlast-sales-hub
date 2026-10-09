<?php

namespace App\Models;

use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned to a package client. Requested by sales, approved by a
 * Sales Admin, then paid out by Accounts, who record the M-Pesa or bank
 * reference. Only completed refunds reduce what the booking has paid.
 */
#[Fillable([
    'package_booking_id', 'package_cancellation_id', 'travel_payment_id', 'amount', 'reason', 'status', 'method',
    'reference', 'failure_reason', 'requested_by', 'approved_by', 'approved_at', 'processed_by', 'processed_at',
])]
class TravelRefund extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<PackageCancellation, $this>
     */
    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(PackageCancellation::class, 'package_cancellation_id');
    }

    /**
     * @return BelongsTo<TravelPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(TravelPayment::class, 'travel_payment_id');
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
