<?php

namespace App\Models;

use App\Enums\Travel\ResourceStatus;
use Database\Factories\GuideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guide who can be assigned to a package, a departure or a booking
 * (the most specific assignment wins).
 */
#[Fillable(['name', 'phone', 'languages', 'specialization', 'travel_provider_id', 'status', 'notes'])]
class Guide extends Model
{
    /** @use HasFactory<GuideFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'status' => ResourceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }
}
