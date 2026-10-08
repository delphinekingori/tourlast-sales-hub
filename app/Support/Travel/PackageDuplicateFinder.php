<?php

namespace App\Support\Travel;

use App\Models\Package;
use App\Models\PropertyEngagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds packages that look like the one being created or renamed: the same
 * provider with a similar name, or the same destination with a very similar
 * name. An exact name for the same provider is "exact" (travel salespeople
 * cannot continue past it; managers can).
 */
class PackageDuplicateFinder
{
    /**
     * @return Collection<int, array{package: Package, exact: bool, reason: string}>
     */
    public static function find(string $name, ?int $providerId, ?string $destination, ?int $exceptPackageId = null): Collection
    {
        $key = PropertyEngagement::nameKey($name);

        if ($key === '') {
            return collect();
        }

        $tokens = self::tokens($key);

        return Package::query()
            ->current()
            ->with(['provider:id,name', 'owner:id,name'])
            ->when($exceptPackageId, fn (Builder $query) => $query->whereKeyNot($exceptPackageId))
            ->where(fn (Builder $query) => $query
                ->when($providerId, fn (Builder $query) => $query->orWhere('travel_provider_id', $providerId))
                ->when(filled($destination), fn (Builder $query) => $query->orWhere('destination', $destination))
                ->orWhere('name_key', $key))
            ->limit(200)
            ->get()
            ->map(function (Package $package) use ($key, $tokens, $providerId, $destination): ?array {
                $sameProvider = $providerId && $package->travel_provider_id === $providerId;
                $sameDestination = filled($destination) && mb_strtolower((string) $package->destination) === mb_strtolower((string) $destination);
                $overlap = self::overlap($tokens, self::tokens((string) $package->name_key));

                return match (true) {
                    $sameProvider && $package->name_key === $key => ['package' => $package, 'exact' => true, 'reason' => 'Same name and provider'],
                    $sameProvider && $overlap >= 0.6 => ['package' => $package, 'exact' => false, 'reason' => 'Similar name, same provider'],
                    $sameDestination && $overlap >= 0.8 => ['package' => $package, 'exact' => false, 'reason' => 'Very similar name, same destination'],
                    default => null,
                };
            })
            ->filter()
            ->sortByDesc(fn (array $match): int => $match['exact'] ? 1 : 0)
            ->values();
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $key): array
    {
        return array_values(array_unique(array_filter(explode(' ', $key), fn (string $token): bool => $token !== '')));
    }

    /**
     * Jaccard similarity of two token sets.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
