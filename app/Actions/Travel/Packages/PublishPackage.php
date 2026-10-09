<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Permission;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use App\Support\Travel\PublishGate;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that an approved package is now on sale on a channel. The travel
 * salesperson publishes it there themselves; the Hub only allows this once
 * both approvals are in and the contract rules pass. A Super Admin may
 * override the contract rules with a written reason.
 */
class PublishPackage
{
    public function handle(User $actor, Package $package, string $channel, ?string $url = null, ?string $overrideReason = null): Package
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        $channel = trim($channel);
        $url = trim((string) $url) ?: null;
        $overrideReason = trim((string) $overrideReason) ?: null;

        if ($channel === '') {
            throw ValidationException::withMessages(['channel' => 'Say where the package is published.']);
        }

        if ($url !== null && ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['url' => 'Enter a full link, starting with https://.']);
        }

        $live = $package->live_version_id ? PackageVersion::query()->find($package->live_version_id) : null;

        if (! $live || $live->status !== PackageVersionStatus::Approved || $package->archived_at
            || ! in_array($package->status, [PackageStatus::Approved, PackageStatus::Unpublished], true)) {
            throw ValidationException::withMessages(['package' => 'Only an approved package that is not already published can be published. It needs Sales Admin and Super Admin approval first.']);
        }

        $missing = PublishGate::missing($package);
        $overriding = false;

        if ($missing !== []) {
            if ($overrideReason === null || ! $actor->can(Permission::OverridePackageContract->value)) {
                throw ValidationException::withMessages(['package' => 'Cannot publish package. Missing: '.implode(', ', $missing).'.']);
            }

            $overriding = true;
        }

        DB::transaction(function () use ($actor, $package, $channel, $url, $overrideReason, $overriding, $missing): void {
            $package->forceFill([
                'status' => PackageStatus::Published,
                'published_at' => now(),
                'published_by' => $actor->id,
                'published_channel' => $channel,
                'published_url' => $url,
                'unpublished_at' => null,
                'contract_override_by' => $overriding ? $actor->id : $package->contract_override_by,
                'contract_override_reason' => $overriding ? $overrideReason : $package->contract_override_reason,
                'updated_by' => $actor->id,
            ])->save();

            if ($overriding) {
                Audit::record($package, 'package.contract_override', 'Published '.$package->reference.' despite: '.implode(', ', $missing).'. Reason: '.$overrideReason);
            }

            Audit::record($package, 'package.published', 'Published '.$package->reference.' on '.$channel.($url ? ' ('.$url.')' : ''));
        });

        Alerts::sendTravel('package_published', 'Package published', $package->name.' is now published on '.$channel.'.', route('travel.packages.show', $package), User::query()->find($package->owner_id));

        return $package;
    }
}
