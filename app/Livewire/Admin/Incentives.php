<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Incentives\AccountPoints;
use App\Models\IncentiveAgreement;
use App\Models\IncentivePolicy;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Incentive agreements per salesperson, and the schedule in force.
 */
#[Title('Incentives')]
class Incentives extends Component
{
    public bool $showAgreement = false;

    public ?int $editingId = null;

    /** @var array{user_id: string, starts_on: string, ends_on: string, notes: string} */
    public array $agreement = ['user_id' => '', 'starts_on' => '', 'ends_on' => '', 'notes' => ''];

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageAgreements->value), 403);
    }

    public function openAgreement(?int $agreementId = null): void
    {
        $this->resetValidation();
        $existing = $agreementId ? IncentiveAgreement::findOrFail($agreementId) : null;
        $this->editingId = $existing?->id;
        $this->agreement = [
            'user_id' => (string) ($existing?->user_id ?? ''),
            'starts_on' => $existing?->starts_on->toDateString() ?? now()->startOfMonth()->toDateString(),
            'ends_on' => $existing?->ends_on?->toDateString() ?? '',
            'notes' => (string) ($existing?->notes ?? ''),
        ];
        $this->showAgreement = true;
    }

    public function saveAgreement(AccountPoints $accountPoints): void
    {
        $this->validate([
            'agreement.user_id' => ['required', Rule::in(User::query()->sellers()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'agreement.starts_on' => ['required', 'date'],
            'agreement.ends_on' => ['nullable', 'date', 'after_or_equal:agreement.starts_on'],
            'agreement.notes' => ['nullable', 'string', 'max:250'],
        ], [], ['agreement.user_id' => 'salesperson', 'agreement.starts_on' => 'start date', 'agreement.ends_on' => 'end date']);

        $overlap = IncentiveAgreement::query()
            ->where('user_id', $this->agreement['user_id'])
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $this->agreement['starts_on']))
            ->when($this->agreement['ends_on'], fn ($query) => $query->whereDate('starts_on', '<=', $this->agreement['ends_on']))
            ->exists();

        if ($overlap) {
            $this->addError('agreement.starts_on', 'This salesperson already has an agreement covering these dates. End it first.');

            return;
        }

        $data = [
            'user_id' => (int) $this->agreement['user_id'],
            'starts_on' => $this->agreement['starts_on'],
            'ends_on' => $this->agreement['ends_on'] ?: null,
            'notes' => $this->agreement['notes'] ?: null,
        ];

        $this->editingId
            ? IncentiveAgreement::findOrFail($this->editingId)->update($data)
            : IncentiveAgreement::create([...$data, 'created_by' => Auth::id()]);

        PartnerAccount::query()->current()->where('user_id', $data['user_id'])->whereNotNull('activation_date')
            ->each(fn (PartnerAccount $account) => $accountPoints->reconcile($account));

        $this->showAgreement = false;
        $this->dispatch('toast', message: 'Agreement saved.');
    }

    public function render(): View
    {
        $policy = IncentivePolicy::for(now());

        return view('livewire.admin.incentives', [
            'agreements' => IncentiveAgreement::query()->with('user')->orderByRaw('ends_on is not null')->orderByDesc('starts_on')->get(),
            'withoutAgreement' => User::query()->active()->sellers()
                ->whereDoesntHave('incentiveAgreements', fn ($query) => $query->coveringDate(now()))
                ->orderBy('name')->get(['id', 'name']),
            'sellers' => User::query()->sellers()->orderBy('name')->get(['id', 'name']),
            'policyModel' => $policy,
            'rules' => $policy->policy()->rules,
        ]);
    }
}
