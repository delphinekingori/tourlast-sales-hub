<?php

namespace App\Livewire\Referrals;

use App\Actions\IssueReferralCode;
use App\Models\Onboarding;
use App\Support\Period;
use App\Support\SalesMetrics;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A salesperson's referral link and what came through it: visits, signups,
 * approvals and live partners, with the latest referred providers.
 */
#[Title('Referral Center')]
class Center extends Component
{
    #[Url]
    public string $period = 'month';

    public function mount(): void
    {
        abort_unless(Auth::user()->role()?->earnsReferrals(), 403);
    }

    public function render(SalesMetrics $metrics, IssueReferralCode $issueReferralCode): View
    {
        $user = Auth::user();
        $range = Period::named(array_key_exists($this->period, Period::options()) ? $this->period : 'month');

        return view('livewire.referrals.center', [
            'referralCode' => $issueReferralCode->handle($user),
            'range' => $range,
            'metrics' => $metrics->forUser($user, $range),
            'approvedInPeriod' => Onboarding::query()->where('user_id', $user->id)->whereBetween('approved_at', [$range->from, $range->to])->count(),
            'recent' => Onboarding::query()->where('user_id', $user->id)->latest('submitted_at')->limit(10)->get(),
        ]);
    }
}
