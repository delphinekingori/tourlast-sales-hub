<?php

namespace App\Support;

use App\Models\PropertyEngagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds registry records that may be the same property, so nobody starts a
 * second acquisition effort for a business Tourlast already knows.
 */
class DuplicateEngagementFinder
{
    /**
     * Words too common in property names to identify one on their own.
     */
    private const GenericWords = [
        'the', 'and', 'of', 'at', 'by', 'hotel', 'hotels', 'resort', 'resorts', 'lodge', 'lodges', 'apartment', 'apartments',
        'villa', 'villas', 'guest', 'house', 'guesthouse', 'camp', 'camps', 'suites', 'suite', 'inn', 'spa', 'beach',
        'safari', 'safaris', 'tours', 'tour', 'travel', 'travels', 'restaurant', 'cottage', 'cottages', 'homes', 'home',
        'stay', 'stays', 'ltd', 'limited', 'co', 'company', 'kenya',
    ];

    /**
     * @param  array{name?: ?string, trading_name?: ?string, city?: ?string, phones?: list<?string>, emails?: list<?string>, website?: ?string, registration_number?: ?string, kra_pin?: ?string}  $input
     * @return Collection<int, array{engagement: PropertyEngagement, reasons: list<string>}>
     */
    public function find(array $input, ?int $exceptId = null, int $limit = 5): Collection
    {
        $keys = MatchKeys::from($input);

        if ($keys->isEmpty()) {
            return collect();
        }

        $candidates = PropertyEngagement::query()
            ->withTrashed()
            ->with(['salesRep:id,name', 'contacts:id,property_engagement_id,phone_key,email'])
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys->nameSets as $tokens) {
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'name_key'));
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'trading_name'));
                }

                if ($keys->exactName !== null) {
                    $query->orWhere('name_key', $keys->exactName);
                }

                if ($keys->phones !== [] || $keys->emails !== []) {
                    $query->orWhereHas('contacts', function (Builder $contacts) use ($keys): void {
                        $contacts->where(function (Builder $match) use ($keys): void {
                            if ($keys->phones !== []) {
                                $match->orWhereIn('phone_key', $keys->phones);
                            }

                            if ($keys->emails !== []) {
                                $match->orWhereIn('email', $keys->emails);
                            }
                        });
                    });
                }

                if ($keys->website) {
                    $query->orWhere('website_key', $keys->website);
                }

                if ($keys->registration) {
                    $query->orWhere('registration_number', $keys->registration);
                }

                if ($keys->kraPin) {
                    $query->orWhere('kra_pin', $keys->kraPin);
                }
            })
            ->limit(60)
            ->get();

        return $candidates
            ->map(fn (PropertyEngagement $engagement): array => [
                'engagement' => $engagement,
                'reasons' => $keys->reasons(
                    name: $engagement->name.' | '.$engagement->trading_name,
                    city: $engagement->city.' '.$engagement->area.' '.$engagement->region,
                    phones: $engagement->contacts->pluck('phone_key')->filter()->all(),
                    emails: $engagement->contacts->pluck('email')->filter()->all(),
                    website: $engagement->website_key,
                    registration: $engagement->registration_number,
                    kraPin: $engagement->kra_pin,
                ),
            ])
            ->filter(fn (array $match) => $match['reasons'] !== [])
            ->sortByDesc(fn (array $match) => MatchKeys::score($match['reasons']))
            ->take($limit)
            ->values();
    }

    /**
     * Distinctive words of a name: "PrideInn Paradise Beach Resort" → [prideinn, paradise].
     *
     * @return list<string>
     */
    public static function significantTokens(?string $name): array
    {
        return collect(explode(' ', PropertyEngagement::nameKey($name)))
            ->filter(fn (string $word) => strlen($word) >= 3 && ! in_array($word, self::GenericWords, true))
            ->unique()
            ->values()
            ->all();
    }
}
