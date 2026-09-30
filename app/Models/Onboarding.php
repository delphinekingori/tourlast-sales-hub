<?php

namespace App\Models;

use App\Enums\OnboardingStatus;
use Carbon\CarbonInterface;
use Database\Factories\OnboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'tourlast_property_id', 'tourlast_account_id', 'partner_account_id', 'legal_name', 'category', 'inventory_count',
    'ref_code', 'referral_code_id', 'user_id', 'property_engagement_id', 'attribution',
    'property_name', 'property_type', 'location', 'contact_name', 'contact_phone', 'contact_email',
    'status', 'submitted_at', 'approved_at', 'active_at', 'rejected_at', 'first_booking_at', 'credited_at',
    'source_updated_at', 'source_payload',
])]
class Onboarding extends Model
{
    /** @use HasFactory<OnboardingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OnboardingStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'active_at' => 'datetime',
            'rejected_at' => 'datetime',
            'first_booking_at' => 'datetime',
            'credited_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'source_payload' => 'array',
            'inventory_count' => 'integer',
        ];
    }

    /**
     * The salesperson credited with this onboarding.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    /**
     * The legal Account this property belongs to.
     *
     * @return BelongsTo<PartnerAccount, $this>
     */
    public function partnerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class);
    }

    /**
     * The registry record for this property, when one exists.
     *
     * @return BelongsTo<PropertyEngagement, $this>
     */
    public function propertyEngagement(): BelongsTo
    {
        return $this->belongsTo(PropertyEngagement::class);
    }

    /**
     * @return HasMany<OnboardingStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OnboardingStatusChange::class)->orderBy('occurred_at');
    }

    /**
     * @return HasMany<AttributionChange, $this>
     */
    public function attributionChanges(): HasMany
    {
        return $this->hasMany(AttributionChange::class)->latest();
    }

    /**
     * @return HasOne<Lead, $this>
     */
    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    /**
     * The configured label when the type is known, otherwise a readable name
     * built from the value the source app sent.
     */
    public function propertyTypeLabel(): string
    {
        $type = (string) $this->property_type;

        return config('hub.property_types.'.$type) ?? ($type === '' ? 'Other' : Str::headline($type));
    }

    public function isStalled(): bool
    {
        return $this->status->isAwaitingApproval()
            && $this->submitted_at?->lt(now()->subDays(config('hub.stalled_after_days')));
    }

    /**
     * Onboardings that count toward a salesperson's numbers.
     *
     * @param  Builder<Onboarding>  $query
     */
    #[Scope]
    protected function onboarded(Builder $query): void
    {
        $query->whereNotNull('credited_at')->whereIn('status', OnboardingStatus::onboardedValues());
    }

    /**
     * @param  Builder<Onboarding>  $query
     */
    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->whereIn('status', OnboardingStatus::awaitingValues());
    }

    /**
     * Onboarded (credited) within the period.
     *
     * @param  Builder<Onboarding>  $query
     */
    #[Scope]
    protected function creditedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->onboarded()->whereBetween('credited_at', [$from, $to]);
    }

    /**
     * @param  Builder<Onboarding>  $query
     */
    #[Scope]
    protected function unattributed(Builder $query): void
    {
        $query->whereNull('user_id');
    }
}
