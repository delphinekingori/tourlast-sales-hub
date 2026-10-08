<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\ApprovalLevel;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageApproval;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A review decision on the version awaiting approval. Sales Admin review
 * first, then Super Admin review, by two different people, neither of whom
 * created the package or submitted the version. Every decision is written
 * to package_approvals and never changed afterwards.
 */
class ReviewPackage
{
    public function handle(User $actor, Package $package, ApprovalDecision $decision, ?string $reason = null): PackageApproval
    {
        $reason = trim((string) $reason) ?: null;

        return DB::transaction(function () use ($actor, $package, $decision, $reason): PackageApproval {
            $package = Package::query()->lockForUpdate()->findOrFail($package->id);
            $version = $package->working_version_id ? PackageVersion::query()->lockForUpdate()->find($package->working_version_id) : null;

            if (! $version || ! $version->isAwaitingReview()) {
                throw ValidationException::withMessages(['package' => 'This package is not waiting for approval.']);
            }

            $level = self::levelFor($version);
            abort_unless(self::canReview($actor, $package, $version), 403);

            if ($decision !== ApprovalDecision::Approved && $reason === null) {
                throw ValidationException::withMessages(['reason' => $decision === ApprovalDecision::Rejected ? 'Give the reason for rejecting.' : 'Say what needs to change.']);
            }

            $approval = PackageApproval::query()->create([
                'package_id' => $package->id,
                'package_version_id' => $version->id,
                'level' => $level,
                'decision' => $decision,
                'reason' => $reason,
                'user_id' => $actor->id,
                'decided_at' => now(),
            ]);

            match (true) {
                $decision === ApprovalDecision::Rejected => $this->sendBack($package, $version, PackageVersionStatus::Rejected),
                $decision === ApprovalDecision::ChangesRequested => $this->sendBack($package, $version, PackageVersionStatus::ChangesRequested),
                $level === ApprovalLevel::SalesAdmin => $version->forceFill(['status' => PackageVersionStatus::SalesAdminApproved])->save(),
                default => $this->approve($package, $version),
            };

            Audit::record($version, 'package.'.$decision->value, $level->label().': '.$decision->label().' '.$package->reference.' '.$version->label().($reason ? ' ('.$reason.')' : ''));

            DB::afterCommit(fn () => $this->notify($actor, $package, $version, $level, $decision, $reason));

            return $approval;
        });
    }

    /**
     * Which review the version is in.
     */
    public static function levelFor(PackageVersion $version): ApprovalLevel
    {
        return $version->status === PackageVersionStatus::SalesAdminApproved ? ApprovalLevel::SuperAdmin : ApprovalLevel::SalesAdmin;
    }

    /**
     * Whether the user may decide the version's current review. Never the
     * package creator, the version author or submitter, and never the person
     * who gave the Sales Admin approval in this round. A Super Admin never
     * takes the Sales Admin review: having done it, they could not give the
     * final approval, and the package would wait for a second Super Admin.
     */
    public static function canReview(User $user, Package $package, PackageVersion $version): bool
    {
        if (! $version->isAwaitingReview()) {
            return false;
        }

        $level = self::levelFor($version);

        if ($level === ApprovalLevel::SalesAdmin && $user->hasRole(Role::SuperAdmin->value)) {
            return false;
        }
        $permission = $level === ApprovalLevel::SuperAdmin ? Permission::ApprovePackagesFinal : Permission::ApprovePackagesFirst;

        if (! $user->can($permission->value) || in_array($user->id, array_filter([$package->created_by, $package->owner_id, $version->created_by, $version->submitted_by]), true)) {
            return false;
        }

        if ($level === ApprovalLevel::SuperAdmin) {
            $firstApprover = PackageApproval::query()
                ->where('package_version_id', $version->id)
                ->where('level', ApprovalLevel::SalesAdmin)
                ->where('decision', ApprovalDecision::Approved)
                ->when($version->submitted_at, fn ($query) => $query->where('decided_at', '>=', $version->submitted_at))
                ->latest('decided_at')
                ->value('user_id');

            if ($firstApprover === $user->id) {
                return false;
            }
        }

        return true;
    }

    private function sendBack(Package $package, PackageVersion $version, PackageVersionStatus $status): void
    {
        $version->forceFill(['status' => $status])->save();

        if (! $package->live_version_id) {
            $package->forceFill(['status' => PackageStatus::Draft])->save();
        }
    }

    private function approve(Package $package, PackageVersion $version): void
    {
        $version->forceFill(['status' => PackageVersionStatus::Approved, 'approved_at' => now()])->save();
        PackageLifecycle::makeLive($package, $version);
    }

    private function notify(User $actor, Package $package, PackageVersion $version, ApprovalLevel $level, ApprovalDecision $decision, ?string $reason): void
    {
        $creator = User::query()->find($package->owner_id);
        $url = route('travel.packages.show', $package);
        $name = $package->name.' ('.$version->label().')';

        match (true) {
            $decision === ApprovalDecision::Rejected => Alerts::sendTravel('package_rejected', 'Package rejected', $name.' was rejected by '.$actor->name.': '.$reason, $url, $creator),
            $decision === ApprovalDecision::ChangesRequested => Alerts::sendTravel('package_changes_requested', 'Changes requested', $actor->name.' asked for changes to '.$name.': '.$reason, $url, $creator),
            $level === ApprovalLevel::SalesAdmin => Alerts::sendToTravelPermission(Permission::ApprovePackagesFinal, 'package_submitted', 'Package ready for Super Admin review', $name.' was approved by '.$actor->name.' (Sales Admin) and needs final approval.', route('travel.approvals.index'), except: $creator),
            default => Alerts::sendTravel('package_approved', 'Package approved', $name.' is approved and can be published.', $url, $creator),
        };
    }
}
