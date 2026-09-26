<?php

namespace App\Models;

use App\Incentives\ChecklistItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['partner_account_id', 'item', 'completed_at', 'completed_by', 'source', 'evidence_path', 'evidence_name', 'note'])]
class AccountChecklistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item' => ChecklistItem::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PartnerAccount, $this>
     */
    public function partnerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
