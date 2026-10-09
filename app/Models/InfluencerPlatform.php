<?php

namespace App\Models;

use Database\Factories\InfluencerPlatformFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platform an influencer is on (Instagram, TikTok...), with their handle
 * and profile link there.
 */
#[Fillable(['influencer_id', 'platform', 'handle', 'url', 'position'])]
class InfluencerPlatform extends Model
{
    /** @use HasFactory<InfluencerPlatformFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Influencer, $this>
     */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function label(): string
    {
        return Influencer::Platforms[$this->platform] ?? ucfirst($this->platform);
    }

    /**
     * "Instagram @name", or just the platform when there is no handle.
     */
    public function summary(): string
    {
        return trim($this->label().' '.$this->handle);
    }
}
