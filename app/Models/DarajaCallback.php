<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every message received from Safaricom Daraja, stored as it arrived before
 * it is processed (for reconciliation and support).
 */
#[Fillable(['type', 'payload', 'ip_address', 'travel_payment_id', 'processed_at', 'error'])]
class DarajaCallback extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TravelPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(TravelPayment::class, 'travel_payment_id');
    }
}
