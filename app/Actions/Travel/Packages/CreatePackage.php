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
 * A new package: a Draft package owned by its creator with a v1.0 draft as
 * the working version. It goes nowhere until it is submitted and approved.
 */
class CreatePackage
{
    public function __construct(private PackageGuards $guards) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array<string, mixed>>  $itinerary
     */
    public function handle(User $actor, array $input, array $itinerary = [], bool $acceptDuplicate = false): Package
    {
        TravelAccess::abortUnlessWorks($actor);

        $this->guards->validate($input, $itinerary);
        $content = PackageContent::normalise($input, [], $actor, null);
        $this->guards->checkContract($content);
        $this->guards->checkDuplicates($actor, $content, null, $acceptDuplicate);
        $days = PackageContent::normaliseItinerary($itinerary);

        return DB::transaction(function () use ($actor, $content, $days): Package {
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
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $version = PackageVersion::query()->create([
                ...$content,
                'package_id' => $package->id,
                'major' => 1,
                'minor' => 0,
                'status' => PackageVersionStatus::Draft,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            PackageItinerary::replace($version, $days);
            $package->forceFill(['working_version_id' => $version->id])->save();

            Audit::record($package, 'package.created', 'Package '.$package->reference.' created: '.$package->name);

            return $package;
        });
    }
}
