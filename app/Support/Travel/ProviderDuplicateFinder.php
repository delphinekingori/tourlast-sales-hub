<?php

namespace App\Support\Travel;

use App\Enums\Permission;
use App\Models\PropertyEngagement;
use App\Models\PropertyEngagementContact;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\DuplicateEngagementFinder;
use App\Support\MatchKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds travel providers, and Property Engagement Registry records, that may
 * be the provider being entered. A "strong" match shares a registration
 * number, KRA PIN, email, phone, website or the exact name.
 */
class ProviderDuplicateFinder
{
    public function __construct(private DuplicateEngagementFinder $registry) {}

    /**
     * @param  array{name?: ?string, trading_name?: ?string, city?: ?string, phones?: list<?string>, emails?: list<?string>, website?: ?string, registration_number?: ?string, kra_pin?: ?string}  $input
     * @return Collection<int, array{kind: string, key: string, id: int, name: string, location: ?string, owner: ?string, status: string, reasons: list<string>, strong: bool, url: ?string, linked: bool}>
     */
    public function find(array $input, User $viewer, ?int $exceptId = null, ?int $linkedEngagementId = null, int $limit = 6): Collection
    {
        $keys = MatchKeys::from($input);

        if ($keys->isEmpty()) {
            return collect();
        }

        $providers = $this->providers($keys, $exceptId);

        // Registry records are only matched for people who may see the Registry
        // (travel salespeople may not).
        $registry = ! $viewer->can(Permission::ViewEngagementRegistry->value) ? collect() : $this->registry->find($input, null, $limit)
            ->reject(fn (array $match) => $match['engagement']->trashed())
            ->map(fn (array $match): array => [
                'kind' => 'registry',
                'key' => 'registry-'.$match['engagement']->id,
                'id' => $match['engagement']->id,
                'name' => $match['engagement']->name,
                'location' => $match['engagement']->locationLabel(),
                'owner' => $match['engagement']->salesRep?->name,
                'status' => 'Registry · '.$match['engagement']->stage->label(),
                'reasons' => $match['reasons'],
                'strong' => false,
                'url' => $viewer->can('view', $match['engagement']) ? route('registry.show', $match['engagement']->id) : null,
                'linked' => $linkedEngagementId === $match['engagement']->id,
            ]);

        return $providers->concat($registry)
            ->sortByDesc(fn (array $match) => ($match['strong'] ? 100 : 0) + MatchKeys::score($match['reasons']))
            ->take($limit)
            ->values();
    }

    /**
     * Whether any provider match is strong (blocks travel salespeople).
     *
     * @param  Collection<int, array<string, mixed>>  $matches
     */
    public static function hasStrongMatch(Collection $matches): bool
    {
        return $matches->contains(fn (array $match) => $match['kind'] === 'provider' && $match['strong']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function providers(MatchKeys $keys, ?int $exceptId): Collection
    {
        return TravelProvider::query()
            ->with('owner:id,name')
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys->nameSets as $tokens) {
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'name_key'));
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'trading_name'));
                }

                foreach ($keys->inputNames as $name) {
                    $query->orWhere('name_key', $name);
                }

                if ($keys->phones !== []) {
                    $query->orWhereIn('phone_key', $keys->phones);
                }

                if ($keys->emails !== []) {
                    $query->orWhereIn('email', $keys->emails)->orWhereIn('primary_contact_email', $keys->emails);
                }

                if ($keys->website) {
                    $query->orWhere('website', 'like', '%'.$keys->website.'%');
                }

                if ($keys->registration) {
                    $query->orWhere('registration_number', $keys->registration);
                }

                if ($keys->kraPin) {
                    $query->orWhere('kra_pin', $keys->kraPin);
                }
            })
            ->latest('updated_at')
            ->limit(40)
            ->get()
            ->map(function (TravelProvider $provider) use ($keys): array {
                $phones = array_filter([$provider->phone, $provider->primary_contact_phone, $provider->whatsapp]);
                $emails = array_filter([$provider->email, $provider->primary_contact_email]);
                $reasons = $keys->reasons(
                    name: $provider->name.' | '.$provider->trading_name,
                    city: (string) $provider->city,
                    phones: $phones,
                    emails: $emails,
                    website: PropertyEngagement::websiteKey($provider->website),
                    registration: $provider->registration_number,
                    kraPin: $provider->kra_pin,
                );

                $phoneKeys = array_filter(array_map(fn ($phone) => PropertyEngagementContact::phoneKey($phone), $phones));
                $strong = ($keys->registration && $keys->registration === $provider->registration_number)
                    || ($keys->kraPin && $keys->kraPin === strtoupper((string) $provider->kra_pin))
                    || array_intersect($keys->emails, array_map('strtolower', $emails)) !== []
                    || array_intersect($keys->phones, $phoneKeys) !== []
                    || ($keys->website && $keys->website === PropertyEngagement::websiteKey($provider->website))
                    || in_array($provider->name_key, $keys->inputNames, true);

                return [
                    'kind' => 'provider',
                    'key' => 'provider-'.$provider->id,
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'location' => trim(collect([$provider->city, $provider->region])->filter()->implode(', ')) ?: null,
                    'owner' => $provider->owner?->name,
                    'status' => 'Travel provider · '.$provider->status->label().($provider->archived_at ? ' (archived)' : ''),
                    'reasons' => $reasons !== [] ? $reasons : ($strong ? ['Same name'] : []),
                    'strong' => $strong,
                    'url' => route('travel.providers.show', $provider->id),
                    'linked' => false,
                ];
            })
            ->filter(fn (array $match) => $match['reasons'] !== [])
            ->values();
    }
}
