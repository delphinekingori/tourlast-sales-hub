<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of a lead from one salesperson to another. Never edited.
 */
#[Fillable(['lead_id', 'from_user_id', 'to_user_id', 'transferred_by', 'reason', 'notes'])]
class LeadTransfer extends Model
{
    /**
     * Standard reasons offered when assigning or transferring a property or lead.
     */
    public const Reasons = [
        'territory' => 'Territory reassignment',
        'left' => 'Salesperson left Tourlast',
        'workload' => 'Workload balancing',
        'client_request' => 'Property requested a different contact',
        'performance' => 'Performance / coverage',
        'new_assignment' => 'New assignment',
        'other' => 'Other',
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transferrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function reasonLabel(): string
    {
        return self::Reasons[$this->reason] ?? $this->reason;
    }

    public static function reasonLabelFor(?string $reason): ?string
    {
        return $reason === null ? null : (self::Reasons[$reason] ?? $reason);
    }
}
