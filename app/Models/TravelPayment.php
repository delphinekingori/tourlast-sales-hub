<?php

namespace App\Models;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use Database\Factories\TravelPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client payment towards a package booking. M-Pesa payments come from
 * Daraja (request to phone, or paid straight to the paybill with the booking
 * reference as the account number) and can never be edited. Cash and bank
 * payments are logged by sales and only count once Accounts confirms them.
 * A paybill payment whose account number matches no booking waits unallocated
 * (package_booking_id null) for Accounts.
 */
#[Fillable([
    'package_booking_id', 'method', 'channel', 'status', 'amount', 'currency', 'mpesa_receipt',
    'checkout_request_id', 'merchant_request_id', 'phone', 'payer_name', 'account_reference', 'reference',
    'result_code', 'result_description', 'paid_at', 'recorded_by', 'confirmed_by', 'confirmed_at',
    'allocated_by', 'allocated_at', 'notes', 'raw',
])]
class TravelPayment extends Model
{
    /** @use HasFactory<TravelPaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'result_code' => 'integer',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'allocated_at' => 'datetime',
            'raw' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Counts towards the booking: completed, and (for manual payments)
     * confirmed by Accounts.
     */
    public function counts(): bool
    {
        return $this->status === PaymentStatus::Completed
            && ($this->channel !== PaymentChannel::Manual || $this->confirmed_at !== null);
    }

    /**
     * @param  Builder<TravelPayment>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->where('status', PaymentStatus::Completed)
            ->where(fn (Builder $query) => $query->where('channel', '!=', PaymentChannel::Manual)->orWhereNotNull('confirmed_at'));
    }

    /**
     * @param  Builder<TravelPayment>  $query
     */
    #[Scope]
    protected function unallocated(Builder $query): void
    {
        $query->whereNull('package_booking_id')->where('status', PaymentStatus::Completed);
    }
}
