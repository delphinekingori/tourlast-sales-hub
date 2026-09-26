<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One salesperson's period representing a property. The open row (no end
 * date) is the current representative; closed rows are kept for history.
 */
#[Fillable(['property_engagement_id', 'user_id', 'started_on', 'ended_on', 'assigned_by', 'reason', 'notes'])]
class PropertyEngagementRep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
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
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<PropertyEngagement, $this>
     */
    public function propertyEngagement(): BelongsTo
    {
        return $this->belongsTo(PropertyEngagement::class);
    }

    public function reasonLabel(): ?string
    {
        return LeadTransfer::reasonLabelFor($this->reason);
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null;
    }
}
