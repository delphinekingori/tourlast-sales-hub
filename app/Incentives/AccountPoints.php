<?php

namespace App\Incentives;

use App\Enums\OnboardingStatus;
use App\Models\IncentiveAgreement;
use App\Models\IncentivePolicy;
use App\Models\InventorySnapshot;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps Partner Accounts and the points ledger in line with Schedule 1.
 *
 * The ledger is derived from facts (Activation Date, verified inventory, live
 * inventory changes, review outcome) and reconciled: unchanged lines stay,
 * changed lines are cancelled and re-issued, so every change leaves a trace.
 */
class AccountPoints
{
    /** Property types scored as Experience Providers. Everything else is a Stay. */
    public const ExperienceTypes = ['tour', 'experience', 'activity', 'restaurant'];

    /**
     * Attach an onboarding to its legal Account (creating it if needed) and refresh the Account.
     */
    public function linkOnboarding(Onboarding $onboarding): PartnerAccount
    {
        return DB::transaction(function () use ($onboarding): PartnerAccount {
            $account = $onboarding->partner_account_id ? PartnerAccount::find($onboarding->partner_account_id) : null;

            while ($account?->merged_into_id) {
                $account = PartnerAccount::find($account->merged_into_id);
            }

            $account ??= PartnerAccount::query()->firstOrCreate(
                ['account_key' => $onboarding->tourlast_account_id ? 'A:'.$onboarding->tourlast_account_id : 'P:'.$onboarding->tourlast_property_id],
                [
                    'legal_name' => $onboarding->legal_name ?? $onboarding->property_name,
                    'category' => $this->categoryFor($onboarding),
                    'inventory_basis' => $this->categoryFor($onboarding) === 'experience' ? 'services' : 'rooms',
                    'user_id' => $onboarding->user_id,
                ],
            );

            if ($onboarding->partner_account_id !== $account->id) {
                $onboarding->forceFill(['partner_account_id' => $account->id])->saveQuietly();
            }

            if (! $account->user_id && $onboarding->user_id) {
                $account->user_id = $onboarding->user_id;
            }

            if ($onboarding->legal_name && ! $account->isVerified()) {
                $account->legal_name = $onboarding->legal_name;
            }

            $account->save();

            return $this->refresh($account);
        });
    }

    /**
     * Re-derive activation, live inventory, automatic checklist items, review
     * warnings and points from the Account's properties.
     */
    public function refresh(PartnerAccount $account): PartnerAccount
    {
        $account->load(['onboardings' => fn ($query) => $query->withTrashed()]);
        $live = $account->onboardings->filter(fn (Onboarding $onboarding): bool => $onboarding->credited_at !== null);
        $firstLive = $live->min('credited_at');

        if ($firstLive && ! $account->activation_date) {
            $account->activation_date = CarbonImmutable::parse($firstLive);
        }

        if ($account->activation_date && ! $account->isVerified()) {
            $atActivation = $live->filter(fn (Onboarding $onboarding): bool => CarbonImmutable::parse($onboarding->credited_at)->lte($account->activation_date->endOfDay()));
            $count = (int) $atActivation->sum('inventory_count');
            $account->activation_inventory = $count > 0 ? $count : $account->activation_inventory;
        }

        $this->recordLiveInventory($account, $live);
        $this->tickAutomaticItems($account);
        $this->flagReviewWarnings($account);

        $account->save();

        $this->reconcile($account);

        return $account->fresh();
    }

    /**
     * Sales Admin approves the Account after checking the qualification checklist.
     */
    public function verify(PartnerAccount $account, User $admin, int $inventory, string $basis, ?string $note = null): PartnerAccount
    {
        $account->load('checklistItems');

        if (! $account->activation_date) {
            throw ValidationException::withMessages(['verifyInventory' => 'This Account is not live on tourlast.com yet, so it has no Activation Date.']);
        }

        if (! $account->checklistComplete()) {
            throw ValidationException::withMessages(['verifyInventory' => 'Complete every item on the qualification checklist before approving points.']);
        }

        $account->update([
            'activation_inventory' => $inventory,
            'inventory_basis' => $basis,
            'inventory_note' => $note,
            'qualification_status' => 'verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->reconcile($account);

        return $account->fresh();
    }

    /**
     * Record that more (or fewer) rooms, units or services are now live, as verified by an admin.
     */
    public function recordInventory(PartnerAccount $account, User $admin, int $count, CarbonImmutable $liveOn, ?string $note = null): InventorySnapshot
    {
        if (! $account->activation_date || $liveOn->lt($account->activation_date)) {
            throw ValidationException::withMessages(['inventoryLiveOn' => 'Inventory changes must be on or after the Activation Date.']);
        }

        $snapshot = $account->inventorySnapshots()->create([
            'count' => $count,
            'live_on' => $liveOn,
            'source' => 'manual',
            'verified_at' => now(),
            'verified_by' => $admin->id,
            'note' => $note,
        ]);

        $this->reconcile($account);

        return $snapshot;
    }

    public function verifySnapshot(InventorySnapshot $snapshot, User $admin): void
    {
        $snapshot->update(['verified_at' => now(), 'verified_by' => $admin->id]);
        $this->reconcile($snapshot->partnerAccount);
    }

    /**
     * The Account failed the 14-day quality review: its points are cancelled.
     */
    public function failReview(PartnerAccount $account, User $admin, string $reason): void
    {
        if (! $account->isInReview()) {
            throw ValidationException::withMessages(['failReason' => 'Only Accounts inside their 14-day review can be failed.']);
        }

        $account->update([
            'review_failed_at' => now(),
            'review_failed_reason' => $reason,
            'review_failed_by' => $admin->id,
        ]);

        $this->reconcile($account);
    }

    /**
     * Combine an artificially separated Account into another (paragraph 3.3).
     */
    public function merge(PartnerAccount $source, PartnerAccount $target, User $admin): PartnerAccount
    {
        return DB::transaction(function () use ($source, $target, $admin): PartnerAccount {
            $source->onboardings()->withTrashed()->update(['partner_account_id' => $target->id]);
            $source->update(['merged_into_id' => $target->id]);

            $source->pointEntries()->where('status', '!=', 'cancelled')->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'reason' => "Merged into {$target->legal_name} by {$admin->name}",
            ]);

            $target->update(['activation_date' => null, 'qualification_status' => 'pending', 'verified_at' => null, 'verified_by' => null]);

            return $this->refresh($target);
        });
    }

    /**
     * Bring the ledger in line with what the Account has earned.
     */
    public function reconcile(PartnerAccount $account): void
    {
        $account->refresh()->load(['inventorySnapshots', 'pointEntries']);
        $desired = $this->desiredEntries($account);
        $existing = $account->pointEntries->where('status', '!=', 'cancelled')->where('type', '!=', 'adjustment');

        foreach ($existing as $entry) {
            $key = $entry->type.':'.($entry->inventory_snapshot_id ?? 0);
            $want = $desired[$key] ?? null;

            if ($want && $want['user_id'] === $entry->user_id && abs($want['points'] - $entry->points) < 0.01 && $want['earned_on']->equalTo($entry->earned_on)) {
                if ($want['status'] !== $entry->status) {
                    $entry->update(['status' => $want['status']]);
                }
                unset($desired[$key]);

                continue;
            }

            $entry->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'reason' => $account->hasFailedReview()
                    ? 'Failed 14-day review: '.$account->review_failed_reason
                    : ($want ? 'Recalculated after a change to the Account' : 'No longer earned'),
            ]);
        }

        foreach ($desired as $want) {
            $policy = IncentivePolicy::for($want['earned_on'])->policy();

            PointEntry::create([
                'user_id' => $want['user_id'],
                'partner_account_id' => $account->id,
                'inventory_snapshot_id' => $want['snapshot_id'],
                'type' => $want['type'],
                'points' => $want['points'],
                'earned_on' => $want['earned_on'],
                'month' => $want['earned_on']->startOfMonth()->toDateString(),
                'bonus_week' => $policy->bonusWeek($want['earned_on']),
                'status' => $want['status'],
                'reason' => $want['reason'],
            ]);
        }
    }

    /**
     * @return array<string, array{type: string, points: float, earned_on: CarbonImmutable, status: string, snapshot_id: ?int, user_id: int, reason: string}>
     */
    private function desiredEntries(PartnerAccount $account): array
    {
        if (! $account->activation_date || ! $account->user_id || $account->hasFailedReview() || $account->merged_into_id || ! $account->activation_inventory) {
            return [];
        }

        $policy = $account->policy();
        $category = $account->category;
        $awarded = $policy->basePoints($category, $account->activation_inventory);
        $lastCount = $account->activation_inventory;
        $halfUsed = false;

        $desired = ['base:0' => [
            'type' => 'base',
            'points' => $awarded,
            'earned_on' => $account->activation_date,
            'status' => $account->isVerified() ? 'approved' : 'provisional',
            'snapshot_id' => null,
            'user_id' => $account->user_id,
            'reason' => $account->activation_inventory.' '.strtolower($account->basisLabel()).' at activation',
        ]];

        $windowEnd = $account->expansionEndsAt();

        foreach ($account->inventorySnapshots as $snapshot) {
            if ($snapshot->live_on->lte($account->activation_date) || $snapshot->live_on->gt($windowEnd)) {
                continue;
            }

            if (! $this->hasAgreementOn($account->user_id, $snapshot->live_on)) {
                continue;
            }

            $points = $policy->basePoints($category, $snapshot->count);
            $status = $snapshot->verified_at ? 'approved' : 'provisional';

            if ($points > $awarded) {
                $desired['expansion:'.$snapshot->id] = [
                    'type' => 'expansion',
                    'points' => $points - $awarded,
                    'earned_on' => $snapshot->live_on,
                    'status' => $status,
                    'snapshot_id' => $snapshot->id,
                    'user_id' => $account->user_id,
                    'reason' => "{$lastCount} → {$snapshot->count}: {$points} − {$awarded} points already awarded",
                ];
                $awarded = $points;
                $lastCount = $snapshot->count;

                continue;
            }

            $sameBand = $policy->bandIndex($category, $snapshot->count) === $policy->bandIndex($category, $lastCount);

            if (! $halfUsed && $sameBand && $snapshot->count >= $lastCount * (1 + $policy->sameCategoryGrowth())) {
                $desired['half:'.$snapshot->id] = [
                    'type' => 'half',
                    'points' => $policy->sameCategoryAward(),
                    'earned_on' => $snapshot->live_on,
                    'status' => $status,
                    'snapshot_id' => $snapshot->id,
                    'user_id' => $account->user_id,
                    'reason' => "{$lastCount} → {$snapshot->count} (+".round(($snapshot->count / $lastCount - 1) * 100).'%, same category)',
                ];
                $halfUsed = true;
            }
        }

        return $desired;
    }

    /**
     * Stored live inventory changes after activation become unverified snapshots.
     *
     * @param  Collection<int, Onboarding>  $live
     */
    private function recordLiveInventory(PartnerAccount $account, $live): void
    {
        if (! $account->activation_date || ! $account->exists) {
            return;
        }

        $total = (int) $live->sum('inventory_count');
        $latest = $account->inventorySnapshots()->latest('live_on')->latest('id')->first();
        $lastSynced = $account->inventorySnapshots()->where('source', 'sync')->latest('live_on')->latest('id')->value('count');
        $current = $latest?->count ?? $account->activation_inventory;

        if ($total === 0 || $current === null || $total === $current || $total === $lastSynced) {
            return;
        }

        $latestLive = CarbonImmutable::parse($live->max('credited_at'));
        $since = $latest?->live_on ?? $account->activation_date;

        $account->inventorySnapshots()->create([
            'count' => $total,
            'live_on' => $latestLive->gt($since) ? $latestLive : now(),
            'source' => 'sync',
        ]);
    }

    private function tickAutomaticItems(PartnerAccount $account): void
    {
        if (! $account->exists) {
            return;
        }

        $onboardings = $account->onboardings;
        $facts = [
            ChecklistItem::ReferralRecorded->value => $onboardings->isNotEmpty() && $onboardings->every(fn (Onboarding $onboarding): bool => filled($onboarding->ref_code) || $onboarding->attribution === 'manual'),
            ChecklistItem::ProvisionallyApproved->value => $onboardings->contains(fn (Onboarding $onboarding): bool => in_array($onboarding->status, [OnboardingStatus::Approved, OnboardingStatus::Active], true)),
            ChecklistItem::LiveAndBookable->value => $onboardings->contains(fn (Onboarding $onboarding): bool => $onboarding->status === OnboardingStatus::Active),
        ];

        foreach ($facts as $item => $isTrue) {
            $row = $account->checklistItems()->firstOrNew(['item' => $item]);

            if ($isTrue && ! $row->completed_at) {
                $row->fill(['completed_at' => now(), 'source' => 'auto'])->save();
            } elseif (! $isTrue && $row->exists && $row->source === 'auto' && $row->completed_at) {
                $row->update(['completed_at' => null]);
            }
        }
    }

    private function flagReviewWarnings(PartnerAccount $account): void
    {
        if (! $account->isInReview()) {
            return;
        }

        $problem = $account->onboardings->first(fn (Onboarding $onboarding): bool => $onboarding->status === OnboardingStatus::Rejected);

        if ($problem && ! $account->review_warning_at) {
            $account->review_warning_at = now();
            $account->review_warning = "tourlast.com reports {$problem->property_name} as rejected or closed.";
        }
    }

    private function hasAgreementOn(int $userId, CarbonImmutable $date): bool
    {
        return IncentiveAgreement::query()->where('user_id', $userId)->coveringDate($date)->exists();
    }

    private function categoryFor(Onboarding $onboarding): string
    {
        if (in_array($onboarding->category, ['stay', 'experience'], true)) {
            return $onboarding->category;
        }

        return in_array($onboarding->property_type, self::ExperienceTypes, true) ? 'experience' : 'stay';
    }
}
