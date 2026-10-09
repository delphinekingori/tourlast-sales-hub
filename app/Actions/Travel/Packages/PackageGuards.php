<?php

namespace App\Actions\Travel\Packages;

use App\Models\Package;
use App\Models\ProviderContract;
use App\Models\User;
use App\Support\Travel\PackageContent;
use App\Support\Travel\PackageDuplicateFinder;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checks shared by creating and editing packages.
 */
class PackageGuards
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array<string, mixed>>  $itinerary
     */
    public function validate(array $input, array $itinerary): void
    {
        Validator::make(['itinerary' => $itinerary, ...$input], [
            ...PackageContent::rules(),
            ...PackageContent::itineraryRules(),
        ])->validate();
    }

    /**
     * The contract, if chosen, must belong to the chosen provider.
     *
     * @param  array<string, mixed>  $content
     */
    public function checkContract(array $content): void
    {
        if (! $content['provider_contract_id']) {
            return;
        }

        $belongs = ProviderContract::query()
            ->whereKey($content['provider_contract_id'])
            ->where('travel_provider_id', $content['travel_provider_id'])
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['provider_contract_id' => 'Choose a contract that belongs to this provider.']);
        }
    }

    /**
     * Possible duplicates block until confirmed; an exact match (same name and
     * provider) can only be confirmed by a Travel manager.
     *
     * @param  array<string, mixed>  $content
     */
    public function checkDuplicates(User $actor, array $content, ?Package $package, bool $accepted): void
    {
        $matches = PackageDuplicateFinder::find((string) $content['name'], (int) $content['travel_provider_id'], $content['destination'], $package?->id);

        if ($matches->isEmpty()) {
            return;
        }

        $exact = $matches->contains(fn (array $match): bool => $match['exact']);

        if ($accepted && (! $exact || TravelAccess::managesAll($actor))) {
            return;
        }

        $first = $matches->first()['package'];

        throw ValidationException::withMessages([
            'duplicate' => $exact && ! TravelAccess::managesAll($actor)
                ? 'A package with this name already exists for this provider ('.$first->reference.'). Open it instead of creating a duplicate.'
                : 'Possible duplicate package found: '.$first->name.' ('.$first->reference.').',
        ]);
    }
}
