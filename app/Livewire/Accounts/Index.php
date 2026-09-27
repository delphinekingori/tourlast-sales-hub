<?php

namespace App\Livewire\Accounts;

use App\Enums\Permission;
use App\Models\IncentivePolicy;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Partner Accounts. Salespeople see their own; verifiers and managers see all,
 * with queues for verification, the 14-day review and open expansion windows.
 */
#[Title('Partner Accounts')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $owner = '';

    public function mount(): void
    {
        abort_unless($this->seesAll() || Auth::user()->role()?->earnsReferrals(), 403);
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $base = PartnerAccount::query()
            ->current()
            ->when(! $this->seesAll(), fn ($query) => $query->where('user_id', Auth::id()))
            ->when($this->seesAll() && $this->owner !== '', fn ($query) => $query->where('user_id', $this->owner));

        $policy = IncentivePolicy::for(now())->policy();
        $reviewDays = $policy->reviewDays();
        $expansionDays = $policy->expansionDays();

        $counts = [
            'all' => (clone $base)->count(),
            'verify' => (clone $base)->whereNotNull('activation_date')->where('qualification_status', 'pending')->whereNull('review_failed_at')->count(),
            'review' => (clone $base)->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($reviewDays))->count(),
            'expansion' => (clone $base)->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($expansionDays))->count(),
            'failed' => (clone $base)->whereNotNull('review_failed_at')->count(),
        ];

        $accounts = (clone $base)
            ->with(['user', 'pointEntries', 'checklistItems', 'onboardings:id,partner_account_id,property_name,status'])
            ->when($this->tab === 'verify', fn ($query) => $query->whereNotNull('activation_date')->where('qualification_status', 'pending')->whereNull('review_failed_at'))
            ->when($this->tab === 'review', fn ($query) => $query->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($reviewDays)))
            ->when($this->tab === 'expansion', fn ($query) => $query->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($expansionDays)))
            ->when($this->tab === 'failed', fn ($query) => $query->whereNotNull('review_failed_at'))
            ->when($this->search !== '', fn ($query) => $query->where('legal_name', 'like', "%{$this->search}%"))
            ->orderByRaw('activation_date is null')
            ->orderByDesc('activation_date')
            ->paginate(20);

        return view('livewire.accounts.index', [
            'accounts' => $accounts,
            'counts' => $counts,
            'seesAll' => $this->seesAll(),
            'canVerify' => Auth::user()->can(Permission::VerifyAccounts->value),
            'owners' => $this->seesAll() ? User::query()->sellers()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    private function seesAll(): bool
    {
        $user = Auth::user();

        return $user->can(Permission::VerifyAccounts->value)
            || $user->can(Permission::ViewTeamPerformance->value)
            || $user->can(Permission::ViewTeamEarnings->value);
    }
}
