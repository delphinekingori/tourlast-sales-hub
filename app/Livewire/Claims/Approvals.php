<?php

namespace App\Livewire\Claims;

use App\Enums\Permission;
use App\Incentives\Claims;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Claims waiting for the signed-in approver (Sales Manager, HR or Finance), plus history.
 */
#[Title('Claim approvals')]
class Approvals extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'mine';

    public ?int $viewingId = null;

    public bool $showDetail = false;

    public string $decisionNote = '';

    public string $decisionAmount = '';

    public string $paymentReference = '';

    public function mount(): void
    {
        abort_unless($this->steps() !== [], 403);
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    public function view(int $claimId): void
    {
        $claim = ExpenseClaim::findOrFail($claimId);
        $this->viewingId = $claim->id;
        $this->decisionNote = '';
        $this->decisionAmount = number_format($claim->payableAmount(), 2, '.', '');
        $this->paymentReference = '';
        $this->resetValidation();
        $this->showDetail = true;
    }

    public function approve(Claims $claims): void
    {
        $claim = ExpenseClaim::findOrFail($this->viewingId);
        $amount = null;

        if ($claim->current_step === 'finance') {
            $this->validate(['decisionAmount' => ['required', 'numeric', 'min:1', 'max:'.$claim->amount]], ['decisionAmount.max' => 'Finance can approve up to the amount claimed.'], ['decisionAmount' => 'amount']);
            $amount = (float) $this->decisionAmount;
        }

        $claims->approve($claim, Auth::user(), $this->decisionNote ?: null, $amount);

        $this->showDetail = false;
        $this->dispatch('toast', message: 'Approved. '.($claim->fresh()->status === 'pending' ? 'Sent on to '.ExpenseClaim::StepLabels[$claim->fresh()->current_step].'.' : 'Fully approved.'));
    }

    public function reject(Claims $claims): void
    {
        $this->validate(['decisionNote' => ['required', 'string', 'min:5', 'max:1000']], ['decisionNote.required' => 'Tell the salesperson why the claim is rejected.'], ['decisionNote' => 'reason']);

        $claims->reject(ExpenseClaim::findOrFail($this->viewingId), Auth::user(), $this->decisionNote);

        $this->showDetail = false;
        $this->dispatch('toast', message: 'Claim rejected.', tone: 'danger');
    }

    public function disburse(Claims $claims): void
    {
        $this->validate(['paymentReference' => ['required', 'string', 'max:100']], [], ['paymentReference' => 'payment reference']);

        $claims->disburse(ExpenseClaim::findOrFail($this->viewingId), Auth::user(), $this->paymentReference);

        $this->showDetail = false;
        $this->dispatch('toast', message: 'Transport request marked as disbursed.');
    }

    public function render(Claims $claims): View
    {
        $user = Auth::user();
        $steps = $this->steps();
        $isFinance = $user->can(Permission::ApproveClaimsFinance->value);

        $query = ExpenseClaim::query()->with(['user', 'attachments'])->latest();

        $query = match ($this->tab) {
            'disburse' => $query->where('type', 'transport_request')->where('status', 'approved'),
            'all' => $query,
            default => $query->where('status', 'pending')->whereIn('current_step', $steps)->where('user_id', '!=', $user->id),
        };

        $viewing = $this->viewingId ? ExpenseClaim::with(['attachments', 'approvals.user', 'partnerAccount', 'lead', 'user'])->find($this->viewingId) : null;

        return view('livewire.claims.approvals', [
            'claims' => $query->paginate(20),
            'viewing' => $viewing,
            'canDecide' => $viewing ? $claims->canDecide($user, $viewing) : false,
            'isFinance' => $isFinance,
            'steps' => $steps,
            'waitingCount' => ExpenseClaim::query()->where('status', 'pending')->whereIn('current_step', $steps)->where('user_id', '!=', $user->id)->count(),
            'disburseCount' => $isFinance ? ExpenseClaim::query()->where('type', 'transport_request')->where('status', 'approved')->count() : 0,
            'airtimeRemaining' => $viewing?->type === 'airtime' ? $claims->airtimeRemaining($viewing) : null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function steps(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return array_values(array_filter(
            array_keys(Claims::StepPermissions),
            fn (string $step): bool => $user->can(Claims::StepPermissions[$step]->value),
        ));
    }
}
