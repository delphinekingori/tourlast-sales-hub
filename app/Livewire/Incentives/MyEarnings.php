<?php

namespace App\Livewire\Incentives;

use App\Actions\SavePaymentDetail;
use App\Incentives\Calculator;
use App\Incentives\MonthlyEarnings;
use App\Incentives\Statements;
use App\Models\IncentiveAgreement;
use App\Models\PartnerAccount;
use App\Models\PaymentDetail;
use App\Models\PayoutStatement;
use App\Models\PointEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What a salesperson can expect to be paid this month, live from the points ledger.
 */
#[Title('My Earnings')]
class MyEarnings extends Component
{
    #[Locked]
    public ?int $subjectId = null;

    #[Url]
    public string $month = '';

    public bool $showPayment = false;

    public string $paymentMethod = 'mpesa';

    /** @var array<string, string> */
    public array $payment = ['mpesa_phone' => '', 'mpesa_name' => '', 'bank_name' => '', 'bank_branch' => '', 'account_number' => '', 'account_name' => ''];

    public function mount(?User $user = null): void
    {
        if ($user?->exists && ! $user->is(Auth::user())) {
            Gate::authorize('view-earnings', $user);
            $this->subjectId = $user->id;
        } else {
            abort_unless(Auth::user()->role()?->earnsReferrals(), 403);
        }

        $this->month = $this->validMonth($this->month)->format('Y-m');
    }

    /**
     * Open the pop-up for M-Pesa or bank details, prefilled with what is saved.
     */
    public function openPayment(string $method): void
    {
        abort_unless($this->subjectId === null, 403);
        abort_unless(array_key_exists($method, PaymentDetail::Methods), 404);

        $saved = Auth::user()->paymentDetail;
        $this->resetValidation();
        $this->paymentMethod = $method;
        $this->payment = [
            'mpesa_phone' => (string) $saved?->mpesa_phone,
            'mpesa_name' => (string) ($saved?->mpesa_name ?? mb_strtoupper(Auth::user()->name)),
            'bank_name' => (string) $saved?->bank_name,
            'bank_branch' => (string) $saved?->bank_branch,
            'account_number' => (string) $saved?->account_number,
            'account_name' => (string) ($saved?->account_name ?? mb_strtoupper(Auth::user()->name)),
        ];
        $this->showPayment = true;
    }

    public function savePayment(SavePaymentDetail $savePaymentDetail): void
    {
        abort_unless($this->subjectId === null, 403);

        $rules = $this->paymentMethod === 'mpesa'
            ? [
                'payment.mpesa_phone' => ['required', 'string', 'regex:/^(\+?254|0)?\s?(7|1)\d{2}\s?\d{3}\s?\d{3}$/'],
                'payment.mpesa_name' => ['required', 'string', 'min:3', 'max:120'],
            ]
            : [
                'payment.bank_name' => ['required', 'string', 'max:120'],
                'payment.bank_branch' => ['nullable', 'string', 'max:120'],
                'payment.account_number' => ['required', 'string', 'regex:/^[0-9A-Za-z\s-]{6,34}$/'],
                'payment.account_name' => ['required', 'string', 'min:3', 'max:120'],
            ];

        $this->validate($rules, [
            'payment.mpesa_phone.regex' => 'Enter a Kenyan mobile number such as 0712 345 678.',
            'payment.account_number.regex' => 'Enter the account number as it appears on your bank statement.',
        ], [
            'payment.mpesa_phone' => 'M-Pesa number', 'payment.mpesa_name' => 'registered name',
            'payment.bank_name' => 'bank', 'payment.account_number' => 'account number', 'payment.account_name' => 'account name',
        ]);

        $savePaymentDetail->handle(Auth::user(), ['method' => $this->paymentMethod, ...$this->payment]);

        $this->showPayment = false;
        $this->dispatch('toast', message: 'Payout details saved. Finance and HR have been notified.');
    }

    public function render(MonthlyEarnings $monthly, Calculator $calculator, Statements $statements): View
    {
        $subject = $this->subjectId ? User::findOrFail($this->subjectId) : Auth::user();
        $month = $this->validMonth($this->month);
        $policy = $monthly->policyFor($month);
        $earnings = $monthly->for($subject, $month);
        $withPending = $calculator->withProvisional($policy, $earnings);
        [$approvedWeeks, $provisionalWeeks] = $monthly->weeklyPoints($subject, $month);
        $allPoints = array_sum($approvedWeeks) + array_sum($provisionalWeeks);

        $accounts = PartnerAccount::query()->current()->where('user_id', $subject->id)->with(['pointEntries', 'checklistItems', 'inventorySnapshots'])->get();

        return view('livewire.incentives.my-earnings', [
            'subject' => $subject,
            'isOwn' => $subject->is(Auth::user()),
            'monthDate' => $month,
            'isCurrentMonth' => $month->isSameMonth(now()),
            'policy' => $policy,
            'earnings' => $earnings,
            'withPending' => $withPending,
            'approvedWeeks' => $approvedWeeks,
            'provisionalWeeks' => $provisionalWeeks,
            'allPoints' => $allPoints,
            'nextStep' => $calculator->nextStep($policy, $earnings->points),
            'hasAgreement' => $monthly->hasAgreement($subject, $month),
            'agreement' => IncentiveAgreement::query()->where('user_id', $subject->id)->latest('starts_on')->first(),
            'statement' => PayoutStatement::query()->where('user_id', $subject->id)->whereDate('month', $month->toDateString())->first(),
            'paymentDue' => $statements->paymentDueOn($month),
            'entries' => PointEntry::query()->where('user_id', $subject->id)->forMonth($month)->with('partnerAccount')->orderBy('earned_on')->get()->reject(fn (PointEntry $entry): bool => $entry->isReplaced()),
            'needsVerification' => $accounts->filter(fn (PartnerAccount $account): bool => $account->activation_date && ! $account->isVerified() && ! $account->hasFailedReview()),
            'inReview' => $accounts->filter(fn (PartnerAccount $account): bool => $account->isInReview()),
            'expansionOpen' => $accounts->filter(fn (PartnerAccount $account): bool => $account->expansionDaysLeft() !== null && ! $account->hasFailedReview()),
            'weeks' => $policy->weeks($month),
            'paymentDetail' => $subject->paymentDetail,
            'monthOptions' => collect(range(0, 5))->mapWithKeys(fn (int $back): array => [
                now()->startOfMonth()->subMonths($back)->format('Y-m') => now()->startOfMonth()->subMonths($back)->format('F Y'),
            ]),
        ])->title($subject->is(Auth::user()) ? 'My Earnings' : $subject->name.' · Earnings');
    }

    private function validMonth(string $value): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m', $value)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }
}
