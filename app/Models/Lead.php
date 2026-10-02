<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\Objection;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'business_name', 'trading_name', 'property_type', 'location', 'contact_name', 'contact_role',
    'contact_phone', 'contact_email', 'website', 'registration_number', 'kra_pin',
    'status', 'lost_reason', 'objection', 'competitor', 'lost_notes', 'lost_at', 'reengage_on',
    'notes', 'last_contacted_at', 'onboarding_id', 'property_engagement_id',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'objection' => Objection::class,
            'last_contacted_at' => 'datetime',
            'lost_at' => 'datetime',
            'reengage_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Normalised keys for the Hub-wide duplicate check.
        static::saving(function (Lead $lead): void {
            $lead->name_key = PropertyEngagement::nameKey($lead->business_name);
            $lead->phone_key = PropertyEngagementContact::phoneKey($lead->contact_phone);
            $lead->website_key = PropertyEngagement::websiteKey($lead->website);
            $lead->kra_pin = filled($lead->kra_pin) ? strtoupper(trim($lead->kra_pin)) : null;
        });
    }

    /**
     * Whether the lead is still being worked (not onboarded or lost).
     */
    public function isOpen(): bool
    {
        return in_array($this->status, LeadStatus::open(), true);
    }

    /**
     * The salesperson who owns the lead.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Onboarding, $this>
     */
    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
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
     * Ownership handovers, newest first.
     *
     * @return HasMany<LeadTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(LeadTransfer::class)->latest()->latest('id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('happened_at');
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class)->orderBy('due_at');
    }

    /**
     * The earliest open follow-up.
     *
     * @return HasOne<FollowUp, $this>
     */
    public function nextFollowUp(): HasOne
    {
        return $this->hasOne(FollowUp::class)->whereNull('completed_at')->oldestOfMany('due_at');
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

    /**
     * Tracked link that also tells the Hub which lead clicked it.
     */
    public function trackedLink(ReferralCode $referralCode): string
    {
        return $referralCode->shareUrl().'?l='.$this->id;
    }

    /**
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (LeadStatus $status): string => $status->value, LeadStatus::open()));
    }
}
