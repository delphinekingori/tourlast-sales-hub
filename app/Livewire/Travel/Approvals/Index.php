<?php

namespace App\Livewire\Travel\Approvals;

use App\Actions\Travel\Packages\ReviewPackage;
use App\Enums\Permission;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageApproval;
use App\Support\Travel\PackageListing;
use App\Support\Travel\PackageReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The package approval queue: Sales Admin review, then Super Admin review.
 */
#[Title('Package approvals')]
class Index extends Component
{
    #[Url]
    public string $tab = 'pending';

    /** Package being reviewed in the slide-over (from ?review=). */
    #[Url(as: 'review')]
    public ?int $reviewId = null;

    public string $reason = '';

    public bool $showReview = false;

    public const Tabs = ['pending' => 'Pending', 'approved' => 'Recently approved', 'rejected' => 'Recently rejected'];

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user->can(Permission::ApprovePackagesFirst->value) || $user->can(Permission::ApprovePackagesFinal->value), 403);

        if (! array_key_exists($this->tab, self::Tabs)) {
            $this->tab = 'pending';
        }

        $this->showReview = $this->reviewId !== null;
    }

    public function review(int $id): void
    {
        $this->reviewId = Package::query()->findOrFail($id)->id;
        $this->reason = '';
        $this->resetErrorBag();
        $this->showReview = true;
    }

    public function updatedShowReview(bool $open): void
    {
        if (! $open) {
            $this->reviewId = null;
        }
    }

    public function approve(ReviewPackage $review): void
    {
        $this->decide($review, ApprovalDecision::Approved, 'Approved.');
    }

    public function reject(ReviewPackage $review): void
    {
        $this->decide($review, ApprovalDecision::Rejected, 'Rejected and sent back to the creator.');
    }

    public function requestChanges(ReviewPackage $review): void
    {
        $this->decide($review, ApprovalDecision::ChangesRequested, 'Changes requested.');
    }

    private function decide(ReviewPackage $review, ApprovalDecision $decision, string $message): void
    {
        $this->resetErrorBag();
        $package = Package::query()->findOrFail((int) $this->reviewId);

        try {
            $review->handle(Auth::user(), $package, $decision, $this->reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        }

        $this->showReview = false;
        $this->reviewId = null;
        $this->reason = '';
        $this->dispatch('toast', message: $message);
    }

    public function render(): View
    {
        $user = Auth::user();
        $statuses = PackageListing::reviewableStatuses($user);

        $pending = $this->tab === 'pending'
            ? Package::query()
                ->whereNull('archived_at')
                ->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', $statuses))
                ->with(['provider:id,name', 'creator:id,name', 'contract', 'workingVersion.approvals.user:id,name', 'workingVersion.submitter:id,name', 'liveVersion:id,package_id,major,minor'])
                ->withMin(['departures as next_departure_on' => fn (Builder $query) => PackageListing::future($query)], 'starts_on')
                ->oldest('updated_at')
                ->get()
            : collect();

        $decisions = $this->tab === 'pending'
            ? collect()
            : PackageApproval::query()
                ->whereIn('decision', $this->tab === 'approved' ? [ApprovalDecision::Approved] : [ApprovalDecision::Rejected, ApprovalDecision::ChangesRequested])
                ->with(['package:id,name,reference,travel_provider_id,created_by', 'package.provider:id,name', 'package.creator:id,name', 'version:id,package_id,major,minor,adult_price,currency', 'user:id,name'])
                ->latest('decided_at')
                ->limit(100)
                ->get();

        $reviewing = $this->reviewId
            ? Package::query()->with(['provider', 'contract', 'creator:id,name', 'liveVersion', 'workingVersion.itineraryDays', 'workingVersion.approvals.user:id,name'])->find($this->reviewId)
            : null;

        return view('livewire.travel.packages.approvals', [
            'pending' => $pending,
            'decisions' => $decisions,
            'reviewing' => $reviewing,
            'reviewReadiness' => $reviewing?->workingVersion ? PackageReadiness::check($reviewing, $reviewing->workingVersion) : [],
            'canDecide' => $reviewing?->workingVersion ? ReviewPackage::canReview($user, $reviewing, $reviewing->workingVersion) : false,
            'counts' => [
                'first' => Package::query()->whereNull('archived_at')->whereHas('workingVersion', fn (Builder $version) => $version->where('status', PackageVersionStatus::Submitted))->count(),
                'final' => Package::query()->whereNull('archived_at')->whereHas('workingVersion', fn (Builder $version) => $version->where('status', PackageVersionStatus::SalesAdminApproved))->count(),
            ],
        ]);
    }
}
