<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suspension, termination or reinstatement. Never edited.
 */
#[Fillable(['user_id', 'from_status', 'to_status', 'reason', 'notes', 'suspended_until', 'changed_by'])]
class UserStatusChange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => AccountStatus::class,
            'to_status' => AccountStatus::class,
            'suspended_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function reasonLabel(): ?string
    {
        return AccountStatus::reasonLabel($this->reason);
    }
}
