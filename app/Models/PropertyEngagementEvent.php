<?php

namespace App\Models;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a registry record's timeline. Never updated or deleted.
 */
#[Fillable(['property_engagement_id', 'type', 'sales_rep_id', 'recorded_by', 'from_value', 'to_value', 'summary', 'notes', 'changes', 'happened_at'])]
class PropertyEngagementEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EngagementEventType::class,
            'changes' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    /**
     * The salesperson the engagement is attributed to.
     *
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    /**
     * The person who entered it in the Hub (null for tourlast.com updates).
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<PropertyEngagement, $this>
     */
    public function propertyEngagement(): BelongsTo
    {
        return $this->belongsTo(PropertyEngagement::class);
    }

    /**
     * Human-readable "from → to" for stage, status and rep changes.
     *
     * @return array{from: ?string, to: ?string}|null
     */
    public function transition(): ?array
    {
        $label = match ($this->type) {
            EngagementEventType::StageChanged, EngagementEventType::Onboarding => fn (?string $value): ?string => $value ? EngagementStage::tryFrom($value)?->label() ?? $value : null,
            EngagementEventType::StatusChanged => fn (?string $value): ?string => $value ? EngagementStatus::tryFrom($value)?->label() ?? $value : null,
            EngagementEventType::RepChanged => fn (?string $value): ?string => $value,
            default => null,
        };

        if (! $label || ($this->from_value === null && $this->to_value === null)) {
            return null;
        }

        return ['from' => $label($this->from_value), 'to' => $label($this->to_value)];
    }
}
