<?php

namespace App\Models;

use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\Objection;
use Database\Factories\PropertyEngagementFactory;
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

/**
 * A property or business Tourlast has engaged, at any point, with any outcome.
 * The institutional record of who engaged it, when, and what happened.
 */
#[Fillable([
    'name', 'property_type', 'star_rating', 'tourlast_property_id', 'website', 'trading_name',
    'registration_name', 'registration_number', 'kra_pin', 'rooms', 'capacity',
    'country', 'region', 'city', 'area', 'address', 'latitude', 'longitude',
    'sales_rep_id', 'stage', 'status', 'source', 'summary', 'next_action', 'next_action_on',
    'objection', 'competitor', 'outcome_notes', 'closed_at', 'reengage_on',
    'first_engaged_on', 'last_engaged_on', 'created_by', 'updated_by',
])]
class PropertyEngagement extends Model
{
    /** @use HasFactory<PropertyEngagementFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Fields shown in the audit trail when they change, with their labels.
     */
    public const Audited = [
        'name' => 'Name', 'property_type' => 'Type', 'star_rating' => 'Star rating', 'tourlast_property_id' => 'Tourlast property ID',
        'website' => 'Website', 'trading_name' => 'Trading name', 'registration_name' => 'Registration name',
        'registration_number' => 'Registration number', 'kra_pin' => 'KRA PIN', 'rooms' => 'Rooms / units', 'capacity' => 'Capacity',
        'country' => 'Country', 'region' => 'County / region', 'city' => 'City / town', 'area' => 'Area', 'address' => 'Address',
        'latitude' => 'Latitude', 'longitude' => 'Longitude', 'source' => 'Source', 'summary' => 'Summary',
        'next_action' => 'Next action', 'next_action_on' => 'Next action date', 'first_engaged_on' => 'First engaged',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => EngagementStage::class,
            'status' => EngagementStatus::class,
            'source' => EngagementSource::class,
            'objection' => Objection::class,
            'closed_at' => 'datetime',
            'reengage_on' => 'date',
            'first_engaged_on' => 'date',
            'last_engaged_on' => 'date',
            'next_action_on' => 'date',
            'rooms' => 'integer',
            'capacity' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PropertyEngagement $engagement): void {
            $engagement->name_key = self::nameKey($engagement->name);
            $engagement->website_key = self::websiteKey($engagement->website);
            $engagement->kra_pin = filled($engagement->kra_pin) ? strtoupper(trim($engagement->kra_pin)) : null;
        });
    }

    /**
     * The salesperson currently responsible for the property.
     *
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<PropertyEngagementContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PropertyEngagementContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * @return HasOne<PropertyEngagementContact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(PropertyEngagementContact::class)->where('is_primary', true);
    }

    /**
     * Everyone who has represented the property, most recent first.
     *
     * @return HasMany<PropertyEngagementRep, $this>
     */
    public function reps(): HasMany
    {
        return $this->hasMany(PropertyEngagementRep::class)->orderByDesc('started_on')->orderByDesc('id');
    }

    /**
     * The timeline, newest first.
     *
     * @return HasMany<PropertyEngagementEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PropertyEngagementEvent::class)->orderByDesc('happened_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class)->latest();
    }

    /**
     * @return HasMany<Onboarding, $this>
     */
    public function onboardings(): HasMany
    {
        return $this->hasMany(Onboarding::class)->latest('submitted_at');
    }

    public function propertyTypeLabel(): string
    {
        return config('hub.property_types.'.$this->property_type, 'Other');
    }

    public function isAccommodation(): bool
    {
        return in_array($this->property_type, config('hub.accommodation_types'), true);
    }

    public function starRatingLabel(): ?string
    {
        return $this->star_rating ? config('hub.star_ratings.'.$this->star_rating) : null;
    }

    /**
     * "Diani, Kwale" style location for tables.
     */
    public function locationLabel(): string
    {
        return collect([$this->area, $this->city, $this->region !== $this->city ? $this->region : null])->filter()->implode(', ');
    }

    public function isOnboarded(): bool
    {
        return $this->stage === EngagementStage::Live;
    }

    /**
     * Lowercase letters and digits only, single-spaced, for duplicate matching.
     */
    public static function nameKey(?string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $name)))));
    }

    /**
     * "https://www.ABC-hotel.com/rooms" → "abc-hotel.com".
     */
    public static function websiteKey(?string $website): ?string
    {
        if (blank($website)) {
            return null;
        }

        $host = parse_url(str_contains($website, '://') ? $website : 'http://'.$website, PHP_URL_HOST);

        return $host ? Str::of($host)->lower()->replaceStart('www.', '')->toString() : null;
    }

    /**
     * Server-side search across the property, its contacts and its rep.
     *
     * @param  Builder<PropertyEngagement>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $digits = preg_replace('/\D/', '', $term);

        $query->where(function (Builder $query) use ($like, $digits): void {
            $query->where('name', 'like', $like)
                ->orWhere('trading_name', 'like', $like)
                ->orWhere('registration_name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('region', 'like', $like)
                ->orWhere('area', 'like', $like)
                ->orWhere('tourlast_property_id', 'like', $like)
                ->orWhereHas('contacts', function (Builder $contacts) use ($like, $digits): void {
                    $contacts->where('name', 'like', $like)->orWhere('email', 'like', $like);

                    if (strlen($digits) >= 6) {
                        $contacts->orWhere('phone_key', 'like', '%'.substr($digits, -9).'%');
                    }
                })
                ->orWhereHas('salesRep', fn (Builder $rep) => $rep->where('name', 'like', $like));
        });
    }
}
