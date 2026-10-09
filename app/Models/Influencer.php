<?php

namespace App\Models;

use Database\Factories\InfluencerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone a travel salesperson gives referral codes to. Clients who book
 * with one of their codes earn them commission (see InfluencerCode).
 */
#[Fillable(['name', 'phone', 'email', 'platform', 'handle', 'payout_method', 'payout_details', 'owner_id', 'is_active', 'notes'])]
#[Hidden(['payout_details'])]
class Influencer extends Model
{
    /** @use HasFactory<InfluencerFactory> */
    use HasFactory;

    public const Platforms = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'x' => 'X (Twitter)',
        'facebook' => 'Facebook',
        'blog' => 'Blog / website',
        'other' => 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payout_details' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The travel salesperson who manages this influencer.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Every platform the influencer is on, first one first. The first is
     * also copied to the platform and handle columns.
     *
     * @return HasMany<InfluencerPlatform, $this>
     */
    public function platforms(): HasMany
    {
        return $this->hasMany(InfluencerPlatform::class)->orderBy('position')->orderBy('id');
    }

    /**
     * "Instagram @amina · TikTok @amina.ke", or the fallback when none are set.
     */
    public function platformSummary(string $fallback = 'No platform'): string
    {
        $platforms = $this->platforms->map(fn (InfluencerPlatform $platform): string => $platform->summary());

        return $platforms->isEmpty() ? $fallback : $platforms->implode(' · ');
    }

    /**
     * @return HasMany<InfluencerCode, $this>
     */
    public function codes(): HasMany
    {
        return $this->hasMany(InfluencerCode::class);
    }

    /**
     * @return HasMany<InfluencerCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(InfluencerCommission::class);
    }
}
