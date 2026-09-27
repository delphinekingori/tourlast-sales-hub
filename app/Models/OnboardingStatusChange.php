<?php

namespace App\Models;

use App\Enums\OnboardingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['onboarding_id', 'from_status', 'to_status', 'source', 'occurred_at'])]
class OnboardingStatusChange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OnboardingStatus::class,
            'to_status' => OnboardingStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Onboarding, $this>
     */
    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }
}
