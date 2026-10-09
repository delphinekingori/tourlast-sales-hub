<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PackageContent;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves edits to a package without ever changing an approved version.
 *
 * - An editable working version (draft, changes requested, rejected) is
 *   updated in place.
 * - A version awaiting review cannot be edited until it is withdrawn.
 * - When only the live (approved) version exists, the edits become the next
 *   minor version (v1.0 → v1.1). If nothing material changed it replaces the
 *   live version at once; otherwise it waits as a draft that must be
 *   approved, and the live version keeps selling meanwhile.
 */
class SavePackage
{
    public function __construct(private PackageGuards $guards) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array<string, mixed>>  $itinerary
     */
    public function handle(User $actor, Package $package, array $input, array $itinerary, bool $acceptDuplicate = false): PackageVersion
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);
        abort_if($package->archived_at !== null, 422, 'Archived packages cannot be edited.');

        $package->loadMissing(['workingVersion', 'liveVersion']);
        $working = $package->workingVersion;
        $live = $package->liveVersion;

        if ($working && $working->isAwaitingReview()) {
            throw ValidationException::withMessages(['package' => 'This version is awaiting approval. Withdraw it from review before making changes.']);
        }

        $this->guards->validate($input, $itinerary);
        $base = $working ?? $live;
        $content = PackageContent::normalise($input, PackageContent::contentOf($base), $actor, $package);
        $this->guards->checkContract($content);

        $current = PackageContent::contentOf($base);

        if ($content['name'] !== $current['name'] || (int) $content['travel_provider_id'] !== (int) $current['travel_provider_id']) {
            $this->guards->checkDuplicates($actor, $content, $package, $acceptDuplicate);
        }

        $days = PackageContent::normaliseItinerary($itinerary);

        if (! $working && Audit::diff($current, $content) === [] && PackageContent::itineraryOf($live) === $days) {
            return $live;
        }

        return DB::transaction(function () use ($actor, $package, $working, $live, $content, $days, $current): PackageVersion {
            if ($working && $working->isEditable()) {
                $material = $live ? PackageContent::materialChanges($live, $content, $days) : null;
                $working->fill([...$content, 'material_changes' => $material ?: null, 'updated_by' => $actor->id])->save();
                PackageItinerary::replace($working, $days);

                if (! $live) {
                    $this->copyListing($package, $working);
                }

                $package->forceFill(['updated_by' => $actor->id])->save();
                Audit::record($working, 'package.edited', 'Edited '.$package->reference.' '.$working->label(), Audit::diff($current, $content));

                return $working;
            }

            $material = PackageContent::materialChanges($live, $content, $days);
            $next = PackageVersion::query()->create([
                ...$content,
                'package_id' => $package->id,
                'major' => $live->major,
                'minor' => (int) $package->versions()->where('major', $live->major)->max('minor') + 1,
                'status' => PackageVersionStatus::Draft,
                'material_changes' => $material ?: null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            PackageItinerary::replace($next, $days);

            if ($material === []) {
                $next->forceFill(['status' => PackageVersionStatus::Approved, 'approved_at' => now()])->save();
                PackageLifecycle::makeLive($package, $next);
                $package->forceFill(['updated_by' => $actor->id])->save();
                Audit::record($next, 'package.minor_change', 'Non-material change, applied without approval ('.$next->label().')', Audit::diff($current, $content));

                return $next;
            }

            $package->forceFill(['working_version_id' => $next->id, 'updated_by' => $actor->id])->save();
            Audit::record($next, 'package.change_requires_approval', 'Changes to '.$package->reference.' need approval ('.$next->label().'): '.implode(', ', array_map([PackageContent::class, 'label'], array_keys($material))), $material);

            return $next;
        });
    }

    private function copyListing(Package $package, PackageVersion $version): void
    {
        $package->forceFill([
            'name' => $version->name,
            'package_type' => $version->package_type,
            'destination' => $version->destination,
            'country' => $version->country,
            'travel_provider_id' => $version->travel_provider_id,
            'provider_contract_id' => $version->provider_contract_id,
        ])->save();
    }
}
