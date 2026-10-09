<?php

namespace App\Support\Travel;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The editable content of a package version: which fields exist, how they
 * are validated, how itinerary rows are normalised, and which changes are
 * material (need re-approval) compared with the live version.
 */
class PackageContent
{
    public const Fields = [
        'name', 'short_description', 'description', 'package_type', 'travel_provider_id', 'provider_contract_id',
        'destination', 'country', 'region', 'start_location', 'end_location', 'duration_label', 'days', 'nights',
        'difficulty', 'min_travelers', 'max_travelers', 'default_capacity', 'min_age', 'age_notes',
        'overview', 'highlights', 'inclusions', 'exclusions', 'requirements', 'what_to_bring', 'terms',
        'cancellation_policy', 'refund_policy', 'meeting_point', 'pickup_info', 'dropoff_info',
        'currency', 'adult_price', 'child_price', 'infant_price', 'group_price', 'group_min_size', 'single_supplement',
        'provider_price', 'net_provider_price', 'discount_amount', 'commission_amount',
    ];

    public const ListFields = ['highlights', 'inclusions', 'exclusions', 'what_to_bring'];

    public const Difficulties = ['easy' => 'Easy', 'moderate' => 'Moderate', 'challenging' => 'Challenging'];

    public const ItineraryFields = ['day_number', 'title', 'description', 'activities', 'meals', 'accommodation', 'transport', 'notes'];

    /**
     * Validation rules for the content, keyed with $prefix (e.g. "form.").
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:99999999'];

        return [
            $prefix.'name' => ['required', 'string', 'max:255'],
            $prefix.'short_description' => ['nullable', 'string', 'max:300'],
            $prefix.'description' => ['nullable', 'string', 'max:10000'],
            $prefix.'package_type' => ['required', Rule::in(array_keys(Package::Types))],
            $prefix.'travel_provider_id' => ['required', 'integer', Rule::exists('travel_providers', 'id')->whereNull('archived_at')],
            $prefix.'provider_contract_id' => ['nullable', 'integer', Rule::exists('provider_contracts', 'id')],
            $prefix.'destination' => ['required', 'string', 'max:120'],
            $prefix.'country' => ['required', 'string', 'max:60'],
            $prefix.'region' => ['nullable', 'string', 'max:80'],
            $prefix.'start_location' => ['nullable', 'string', 'max:255'],
            $prefix.'end_location' => ['nullable', 'string', 'max:255'],
            $prefix.'duration_label' => ['nullable', 'string', 'max:60'],
            $prefix.'days' => ['required', 'integer', 'min:1', 'max:365'],
            $prefix.'nights' => ['nullable', 'integer', 'min:0', 'max:365'],
            $prefix.'difficulty' => ['nullable', Rule::in(array_keys(self::Difficulties))],
            $prefix.'min_travelers' => ['nullable', 'integer', 'min:1', 'max:1000'],
            $prefix.'max_travelers' => ['nullable', 'integer', 'min:1', 'max:1000'],
            $prefix.'default_capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            $prefix.'min_age' => ['nullable', 'integer', 'min:0', 'max:99'],
            $prefix.'age_notes' => ['nullable', 'string', 'max:255'],
            $prefix.'overview' => ['nullable', 'string', 'max:10000'],
            $prefix.'highlights' => ['array'],
            $prefix.'highlights.*' => ['nullable', 'string', 'max:255'],
            $prefix.'inclusions' => ['array'],
            $prefix.'inclusions.*' => ['nullable', 'string', 'max:255'],
            $prefix.'exclusions' => ['array'],
            $prefix.'exclusions.*' => ['nullable', 'string', 'max:255'],
            $prefix.'requirements' => ['nullable', 'string', 'max:5000'],
            $prefix.'what_to_bring' => ['array'],
            $prefix.'what_to_bring.*' => ['nullable', 'string', 'max:255'],
            $prefix.'terms' => ['nullable', 'string', 'max:10000'],
            $prefix.'cancellation_policy' => ['nullable', 'string', 'max:5000'],
            $prefix.'refund_policy' => ['nullable', 'string', 'max:5000'],
            $prefix.'meeting_point' => ['nullable', 'string', 'max:255'],
            $prefix.'pickup_info' => ['nullable', 'string', 'max:2000'],
            $prefix.'dropoff_info' => ['nullable', 'string', 'max:2000'],
            $prefix.'currency' => ['required', 'string', 'size:3'],
            $prefix.'adult_price' => $money,
            $prefix.'child_price' => $money,
            $prefix.'infant_price' => $money,
            $prefix.'group_price' => $money,
            $prefix.'group_min_size' => ['nullable', 'integer', 'min:2', 'max:1000'],
            $prefix.'single_supplement' => $money,
            $prefix.'provider_price' => $money,
            $prefix.'net_provider_price' => $money,
            $prefix.'discount_amount' => $money,
            $prefix.'commission_amount' => $money,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itineraryRules(string $prefix = 'itinerary'): array
    {
        return [
            $prefix => ['array', 'max:60'],
            $prefix.'.*.title' => ['required', 'string', 'max:255'],
            $prefix.'.*.description' => ['nullable', 'string', 'max:5000'],
            $prefix.'.*.activities' => ['nullable', 'string', 'max:2000'],
            $prefix.'.*.meals' => ['array'],
            $prefix.'.*.meals.*' => ['string', Rule::in(['breakfast', 'lunch', 'dinner'])],
            $prefix.'.*.accommodation' => ['nullable', 'string', 'max:255'],
            $prefix.'.*.transport' => ['nullable', 'string', 'max:255'],
            $prefix.'.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Content as an array with every field, lists trimmed, blanks as null.
     * Financial fields are taken from $fallback when the user may not set them.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    public static function normalise(array $input, array $fallback, User $actor, ?Package $package): array
    {
        $content = [];

        foreach (self::Fields as $field) {
            $value = array_key_exists($field, $input) ? $input[$field] : ($fallback[$field] ?? null);

            if (in_array($field, self::ListFields, true)) {
                $value = array_values(array_filter(array_map(fn ($item) => trim((string) $item), (array) $value), fn (string $item): bool => $item !== ''));
            } elseif (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }

            $content[$field] = $value;
        }

        if (! self::mayEditFinancials($actor, $package)) {
            foreach (PackageVersion::FinancialFields as $field) {
                $content[$field] = $fallback[$field] ?? null;
            }
        }

        $content['nights'] ??= 0;
        $content['min_travelers'] ??= 1;
        $content['currency'] = strtoupper((string) ($content['currency'] ?? config('travel.currency')));

        return $content;
    }

    /**
     * Cost and commission are for Travel finance viewers and the package owner.
     */
    public static function mayEditFinancials(User $actor, ?Package $package): bool
    {
        return TravelAccess::seesFinancials($actor) || $package === null || $package->owner_id === $actor->id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normaliseItinerary(array $rows): array
    {
        $days = [];

        foreach (array_values($rows) as $index => $row) {
            $days[] = [
                'day_number' => $index + 1,
                'title' => trim((string) ($row['title'] ?? '')),
                'description' => self::blankToNull($row['description'] ?? null),
                'activities' => self::blankToNull($row['activities'] ?? null),
                'meals' => array_values(array_intersect(['breakfast', 'lunch', 'dinner'], (array) ($row['meals'] ?? []))),
                'accommodation' => self::blankToNull($row['accommodation'] ?? null),
                'transport' => self::blankToNull($row['transport'] ?? null),
                'notes' => self::blankToNull($row['notes'] ?? null),
            ];
        }

        return $days;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function itineraryOf(PackageVersion $version): array
    {
        return self::normaliseItinerary($version->itineraryDays()->get()->map(fn ($day) => $day->only(self::ItineraryFields))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function contentOf(PackageVersion $version): array
    {
        $content = [];

        foreach (self::Fields as $field) {
            $value = $version->getAttribute($field);
            $content[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $content;
    }

    /**
     * Material differences from the live version: field => [old, new], plus
     * "itinerary" when the days changed.
     *
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $itinerary
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function materialChanges(PackageVersion $live, array $content, array $itinerary): array
    {
        $changes = [];

        foreach (PackageVersion::MaterialFields as $field) {
            $old = $live->getAttribute($field);
            $new = $content[$field] ?? null;

            if (self::comparable($old) !== self::comparable($new)) {
                $changes[$field] = [self::display($old), self::display($new)];
            }
        }

        $liveItinerary = self::itineraryOf($live);

        if ($liveItinerary !== $itinerary) {
            $changes['itinerary'] = [count($liveItinerary).' days', count($itinerary).' days (changed)'];
        }

        return $changes;
    }

    /**
     * Human label for a material field.
     */
    public static function label(string $field): string
    {
        return match ($field) {
            'travel_provider_id' => 'Provider',
            'provider_contract_id' => 'Contract',
            'default_capacity' => 'Capacity',
            'max_travelers' => 'Maximum travelers',
            'discount_amount' => 'Discount',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private static function comparable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value), fn (string $item): bool => $item !== '')),
            is_numeric($value) => (string) round((float) $value, 2),
            is_string($value) => trim($value) === '' ? null : trim($value),
            default => $value,
        };
    }

    private static function display(mixed $value): mixed
    {
        return is_array($value) ? implode('; ', array_filter($value, fn ($item) => filled($item))) : self::comparable($value);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
