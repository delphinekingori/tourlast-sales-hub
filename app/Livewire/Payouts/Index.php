<?php

namespace App\Livewire\Payouts;

use App\Enums\Permission;
use App\Incentives\Statements;
use App\Models\PayoutStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monthly payout statements: Sales Admin confirms retainer conditions,
 * Accounts approves and marks them paid by the 5th.
 */
#[Title('Payouts')]
class Index extends Component
{
    #[Url]
    public string $month = '';

    public ?int $viewingId = null;

    public bool $showDetail = false;

    /** @var array<string, bool> */
    public array $compliance = [];

    public string $paymentReference = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewTeamEarnings->value), 403);
        $this->month = $this->validMonth($this->month)->format('Y-m');
    }

    public function generate(Statements $statements): void
    {
        abort_unless(Auth::user()->can(Permission::ManagePayouts->value) || Auth::user()->can(Permission::VerifyAccounts->value), 403);

        $count = $statements->generate($this->validMonth($this->month))->count();
        $this->dispatch('toast', message: "{$count} draft statements refreshed.");
    }

    public function view(int $statementId): void
    {
        $statement = PayoutStatement::findOrFail($statementId);
        $this->viewingId = $statement->id;
        $this->compliance = array_map('boolval', array_merge(array_fill_keys(array_keys(PayoutStatement::ComplianceItems), false), $statement->compliance ?? []));
        $this->paymentReference = '';
        $this->resetValidation();
        $this->showDetail = true;
    }

    public function saveCompliance(Statements $statements): void
    {
        abort_unless(Auth::user()->can(Permission::VerifyAccounts->value), 403);

        $statements->setCompliance(PayoutStatement::findOrFail($this->viewingId), $this->compliance);
        $this->dispatch('toast', message: 'Retainer conditions saved.');
    }

    public function approve(Statements $statements): void
    {
        abort_unless(Auth::user()->can(Permission::ManagePayouts->value), 403);

        $statements->approve(PayoutStatement::findOrFail($this->viewingId), Auth::user());
        $this->dispatch('toast', message: 'Statement approved and frozen.');
    }

    public function markPaid(Statements $statements): void
    {
        abort_unless(Auth::user()->can(Permission::ManagePayouts->value), 403);
        $this->validate(['paymentReference' => ['required', 'string', 'max:100']], [], ['paymentReference' => 'payment reference']);

        $statements->markPaid(PayoutStatement::findOrFail($this->viewingId), Auth::user(), $this->paymentReference);
        $this->showDetail = false;
        $this->dispatch('toast', message: 'Marked as paid.');
    }

    public function render(Statements $statements): View
    {
        $month = $this->validMonth($this->month);
        $list = PayoutStatement::query()->with('user')->whereDate('month', $month->toDateString())->get()->sortBy('user.name');

        return view('livewire.payouts.index', [
            'monthDate' => $month,
            'statements' => $list,
            'totals' => ['total' => $list->sum('total'), 'paid' => $list->where('status', 'paid')->sum('total'), 'count' => $list->count()],
            'dueOn' => $statements->paymentDueOn($month),
            'viewing' => $this->viewingId ? PayoutStatement::with('user')->find($this->viewingId) : null,
            'canManage' => Auth::user()->can(Permission::ManagePayouts->value),
            'canConfirm' => Auth::user()->can(Permission::VerifyAccounts->value),
            'monthOptions' => collect(range(0, 11))->mapWithKeys(fn (int $back): array => [
                now()->startOfMonth()->subMonths($back)->format('Y-m') => now()->startOfMonth()->subMonths($back)->format('F Y'),
            ]),
        ]);
    }

    private function validMonth(string $value): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m', $value)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth()->subMonth();
    }
}
