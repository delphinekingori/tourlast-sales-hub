<?php

namespace App\Livewire\Accounts;

use App\Enums\Permission;
use App\Incentives\AccountPoints;
use App\Incentives\ChecklistItem;
use App\Models\InventorySnapshot;
use App\Models\PartnerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One Partner Account: properties, qualification checklist, inventory, points
 * and the 14-day review. Sales Admin verifies; the salesperson adds evidence.
 */
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $accountId;

    public string $verifyInventory = '';

    public string $verifyBasis = 'rooms';

    public string $verifyNote = '';

    public string $verifyCategory = 'stay';

    public string $inventoryCount = '';

    public string $inventoryLiveOn = '';

    public string $inventoryNote = '';

    public string $failReason = '';

    public string $mergeTarget = '';

    public ?string $evidenceItem = null;

    /** @var mixed */
    public $evidenceFile = null;

    public bool $showVerify = false;

    public bool $showInventory = false;

    public bool $showFail = false;

    public bool $showMerge = false;

    public function mount(PartnerAccount $account): void
    {
        Gate::authorize('view-account', $account);

        if ($account->merged_into_id) {
            $this->redirectRoute('accounts.show', $account->merged_into_id, navigate: true);
        }

        $this->accountId = $account->id;
    }

    public function toggleItem(string $item): void
    {
        $this->authorizeVerifier();
        $item = ChecklistItem::from($item);
        abort_if($item->isAutomatic(), 422);

        $row = $this->account()->checklistItems()->firstOrNew(['item' => $item->value]);
        $row->fill($row->completed_at
            ? ['completed_at' => null, 'completed_by' => null]
            : ['completed_at' => now(), 'completed_by' => Auth::id(), 'source' => 'manual'])->save();
    }

    public function uploadEvidence(): void
    {
        $account = $this->account();
        abort_unless($account->user_id === Auth::id() || Auth::user()->can(Permission::VerifyAccounts->value), 403);

        $this->validate([
            'evidenceItem' => ['required', Rule::enum(ChecklistItem::class)],
            'evidenceFile' => ['required', 'file', 'mimes:'.implode(',', config('incentives.upload_mimes')), 'max:'.config('incentives.upload_max_kb')],
        ], [], ['evidenceFile' => 'file']);

        $row = $account->checklistItems()->firstOrNew(['item' => $this->evidenceItem]);
        $row->fill([
            'evidence_path' => $this->evidenceFile->store('evidence/'.$account->id, 'local'),
            'evidence_name' => mb_substr($this->evidenceFile->getClientOriginalName(), 0, 250),
        ])->save();

        $this->reset('evidenceItem', 'evidenceFile');
        $this->dispatch('toast', message: 'Evidence uploaded.');
    }

    public function openVerify(): void
    {
        $this->authorizeVerifier();
        $account = $this->account();
        $this->resetValidation();
        $this->verifyInventory = (string) ($account->activation_inventory ?? '');
        $this->verifyBasis = $account->inventory_basis;
        $this->verifyCategory = $account->category;
        $this->verifyNote = (string) $account->inventory_note;
        $this->showVerify = true;
    }

    public function verify(AccountPoints $accountPoints): void
    {
        $this->authorizeVerifier();

        $this->validate([
            'verifyInventory' => ['required', 'integer', 'min:1', 'max:100000'],
            'verifyBasis' => ['required', Rule::in(array_keys(PartnerAccount::Bases))],
            'verifyCategory' => ['required', Rule::in(array_keys(PartnerAccount::Categories))],
            'verifyNote' => [in_array($this->verifyBasis, ['outlets', 'packages', 'products'], true) ? 'required' : 'nullable', 'string', 'max:250'],
        ], ['verifyNote.required' => 'Record why services were not a reasonable measure (paragraph 4.2).'], [
            'verifyInventory' => 'verified count', 'verifyBasis' => 'measure', 'verifyCategory' => 'category',
        ]);

        $account = $this->account();
        $account->update(['category' => $this->verifyCategory]);
        $accountPoints->verify($account, Auth::user(), (int) $this->verifyInventory, $this->verifyBasis, $this->verifyNote ?: null);

        $this->showVerify = false;
        $this->dispatch('toast', message: 'Account verified. Its points are now approved.');
    }

    public function openInventory(): void
    {
        $this->authorizeVerifier();
        $this->resetValidation();
        $this->inventoryCount = (string) ($this->account()->currentInventory() ?? '');
        $this->inventoryLiveOn = now()->format('Y-m-d');
        $this->inventoryNote = '';
        $this->showInventory = true;
    }

    public function recordInventory(AccountPoints $accountPoints): void
    {
        $this->authorizeVerifier();

        $this->validate([
            'inventoryCount' => ['required', 'integer', 'min:1', 'max:100000'],
            'inventoryLiveOn' => ['required', 'date', 'before_or_equal:today'],
            'inventoryNote' => ['required', 'string', 'max:250'],
        ], ['inventoryNote.required' => 'Say what was added and how it was verified.'], ['inventoryCount' => 'total now live', 'inventoryLiveOn' => 'live date']);

        $accountPoints->recordInventory($this->account(), Auth::user(), (int) $this->inventoryCount, CarbonImmutable::parse($this->inventoryLiveOn)->setTimeFrom(now()), $this->inventoryNote);

        $this->showInventory = false;
        $this->dispatch('toast', message: 'Inventory recorded. Expansion points were recalculated.');
    }

    public function verifySnapshot(int $snapshotId, AccountPoints $accountPoints): void
    {
        $this->authorizeVerifier();
        $snapshot = InventorySnapshot::query()->where('partner_account_id', $this->accountId)->findOrFail($snapshotId);
        $accountPoints->verifySnapshot($snapshot, Auth::user());
        $this->dispatch('toast', message: 'Inventory change verified.');
    }

    public function failReview(AccountPoints $accountPoints): void
    {
        $this->authorizeVerifier();
        $this->validate(['failReason' => ['required', 'string', 'min:10', 'max:250']], [], ['failReason' => 'reason']);

        $accountPoints->failReview($this->account(), Auth::user(), $this->failReason);

        $this->showFail = false;
        $this->dispatch('toast', message: 'Review failed. The Account\'s points were cancelled.', tone: 'danger');
    }

    public function merge(AccountPoints $accountPoints): void
    {
        $this->authorizeVerifier();
        $this->validate(['mergeTarget' => ['required', 'integer', Rule::exists('partner_accounts', 'id')->whereNull('merged_into_id'), Rule::notIn([$this->accountId])]], [], ['mergeTarget' => 'Account']);

        $target = PartnerAccount::findOrFail($this->mergeTarget);
        $accountPoints->merge($this->account(), $target, Auth::user());

        $this->dispatch('toast', message: "Merged into {$target->legal_name}.");
        $this->redirectRoute('accounts.show', $target, navigate: true);
    }

    public function render(): View
    {
        $account = PartnerAccount::with([
            'user', 'verifier', 'onboardings', 'checklistItems.completer', 'inventorySnapshots.verifier', 'pointEntries',
        ])->findOrFail($this->accountId);

        $items = $account->checklistItems->keyBy(fn ($row) => $row->item->value);

        return view('livewire.accounts.show', [
            'account' => $account,
            'policy' => $account->policy(),
            'items' => $items,
            'checklist' => ChecklistItem::cases(),
            'canVerify' => Auth::user()->can(Permission::VerifyAccounts->value),
            'isOwner' => $account->user_id === Auth::id(),
            'mergeOptions' => Auth::user()->can(Permission::VerifyAccounts->value)
                ? PartnerAccount::query()->current()->whereKeyNot($account->id)->where('category', $account->category)->orderBy('legal_name')->limit(200)->get(['id', 'legal_name'])
                : collect(),
        ])->title($account->legal_name);
    }

    private function account(): PartnerAccount
    {
        return PartnerAccount::findOrFail($this->accountId);
    }

    private function authorizeVerifier(): void
    {
        abort_unless(Auth::user()->can(Permission::VerifyAccounts->value), 403);
    }
}
