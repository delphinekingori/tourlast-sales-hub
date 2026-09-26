<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['expense_claim_id', 'kind', 'path', 'original_name', 'mime', 'size'])]
class ClaimAttachment extends Model
{
    public const Kinds = ['receipt' => 'Receipt', 'ride_details' => 'Ride details (Bolt/Uber)', 'other' => 'Supporting document'];

    /**
     * @return BelongsTo<ExpenseClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function kindLabel(): string
    {
        return self::Kinds[$this->kind] ?? 'Attachment';
    }
}
