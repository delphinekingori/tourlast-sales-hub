<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PackageContent;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;

/**
 * Starts a new draft package from an existing one: descriptions, itinerary,
 * inclusions, exclusions, provider and media are copied; prices, capacity,
 * driver and guide are left empty and the contract must be confirmed.
 */
class DuplicatePackage
{
    public function __construct(private PackageGuards $guards) {}

    public function handle(User $actor, Package $source, string $name, bool $acceptDuplicate = false): Package
    {
        TravelAccess::abortUnlessWorks($actor);

        $from = $source->workingVersion()->first() ?? $source->liveVersion()->firstOrFail();
        $content = PackageContent::contentOf($from);
        $content['name'] = trim($name) ?: $content['name'].' (copy)';
        $this->guards->validate(['name' => $content['name']] + $content, []);
        $this->guards->checkDuplicates($actor, $content, null, $acceptDuplicate);

        foreach (['adult_price', 'child_price', 'infant_price', 'group_price', 'group_min_size', 'single_supplement', 'discount_amount',
            'provider_price', 'net_provider_price', 'commission_amount', 'default_capacity'] as $field) {
            $content[$field] = null;
        }

        return DB::transaction(function () use ($actor, $source, $from, $content): Package {
            $package = Package::query()->create([
                'reference' => Package::nextReference(),
                'name' => $content['name'],
                'package_type' => $content['package_type'],
                'destination' => $content['destination'],
                'country' => $content['country'],
                'travel_provider_id' => $content['travel_provider_id'],
                'provider_contract_id' => $content['provider_contract_id'],
                'status' => PackageStatus::Draft,
                'owner_id' => $actor->id,
                'guide_required' => $source->guide_required,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $version = PackageVersion::query()->create([
                ...$content,
                'package_id' => $package->id,
                'major' => 1,
                'minor' => 0,
                'status' => PackageVersionStatus::Draft,
                'change_note' => 'Copied from '.$source->reference.'. Confirm prices, dates, capacity, driver, guide and contract.',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            PackageItinerary::replace($version, PackageContent::itineraryOf($from));
            $package->forceFill(['working_version_id' => $version->id])->save();

            $media = $source->media()->usable()->get()->mapWithKeys(fn ($asset) => [$asset->id => [
                'position' => $asset->pivot->position,
                'is_primary' => $asset->pivot->is_primary,
            ]])->all();
            $package->media()->sync($media);

            Audit::record($package, 'package.duplicated', 'Created '.$package->reference.' from '.$source->reference);

            return $package;
        });
    }
}
