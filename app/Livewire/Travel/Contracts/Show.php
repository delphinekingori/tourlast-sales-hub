<?php

namespace App\Livewire\Travel\Contracts;

use App\Actions\Travel\Providers\RemoveContractDocument;
use App\Actions\Travel\Providers\StoreContractDocument;
use App\Actions\Travel\Providers\TransitionContract;
use App\Livewire\Travel\Providers\Concerns\EditsContracts;
use App\Models\AuditEvent;
use App\Models\ContractDocument;
use App\Models\ProviderContract;
use App\Support\Travel\ContractTerms;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One contract: terms, documents, workflow and history.
 */
class Show extends Component
{
    use EditsContracts;
    use WithFileUploads;

    #[Locked]
    public int $contractId;

    public bool $showTransition = false;

    #[Locked]
    public string $transitionAction = '';

    public string $transitionNote = '';

    public string $documentType = 'signed_contract';

    /** @var mixed */
    public $document = null;

    /** The file the next upload replaces (null adds a new file). */
    #[Locked]
    public ?int $replacingDocumentId = null;

    public function mount(int|string $contract): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->contractId = ProviderContract::query()->findOrFail($contract)->id;
    }

    public function openTransition(string $action): void
    {
        abort_unless(ContractTerms::can(Auth::user(), $this->contract(), $action), 403);

        $this->transitionAction = $action;
        $this->transitionNote = '';
        $this->resetErrorBag();
        $this->showTransition = true;
    }

    public function applyTransition(TransitionContract $transition): void
    {
        $transition->handle($this->contract(), $this->transitionAction, Auth::user(), $this->transitionNote ?: null);

        $this->showTransition = false;
        $this->dispatch('toast', message: 'Contract is now '.ContractTerms::Transitions[$this->transitionAction][1]->label().'.');
    }

    public function uploadDocument(StoreContractDocument $store): void
    {
        $this->validate([
            'documentType' => ['required', Rule::in(array_keys(ContractDocument::Types))],
            'document' => ['required', ...ContractDocument::fileRules()],
        ], [], ['document' => 'file']);

        $contract = $this->contract();
        $replacing = $this->replacingDocumentId ? $contract->currentDocuments()->findOrFail($this->replacingDocumentId) : null;

        $store->handle($contract, $this->document, $this->documentType, Auth::user(), $replacing);

        $this->reset('document', 'replacingDocumentId');
        $this->dispatch('toast', message: $replacing ? 'Document replaced. The earlier version is kept.' : 'Document added.');
    }

    public function startReplacing(int $documentId): void
    {
        $document = $this->contract()->currentDocuments()->findOrFail($documentId);
        abort_unless(ContractTerms::canAttach(Auth::user(), $this->contract()->provider), 403);

        $this->replacingDocumentId = $document->id;
        $this->documentType = $document->type;
        $this->reset('document');
        $this->resetErrorBag();
    }

    public function cancelReplacing(): void
    {
        $this->reset('document', 'replacingDocumentId');
        $this->resetErrorBag();
    }

    public function removeDocument(int $documentId, RemoveContractDocument $remove): void
    {
        $remove->handle($this->contract()->currentDocuments()->findOrFail($documentId), Auth::user());

        if ($this->replacingDocumentId === $documentId) {
            $this->reset('replacingDocumentId');
        }

        $this->dispatch('toast', message: 'Document removed. It stays in the contract history.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $contract = ProviderContract::query()
            ->with(['provider.owner:id,name', 'creator:id,name', 'approver:id,name', 'documents' => fn ($query) => $query->latest('id')->with(['uploader:id,name', 'remover:id,name', 'replacement:id,original_name']), 'packages:id,name,reference,status,provider_contract_id'])
            ->findOrFail($this->contractId);
        $provider = $contract->provider;

        return view('livewire.travel.contracts.show', [
            'contract' => $contract,
            'provider' => $provider,
            'state' => $contract->effectiveStatus(),
            'seesCommission' => ContractTerms::seesCommission($user, $provider),
            'seesDocuments' => ContractTerms::seesDocuments($user, $provider),
            'canUpload' => ContractTerms::canAttach($user, $provider),
            'canEdit' => ContractTerms::canEdit($user, $provider, $contract),
            'actions' => ContractTerms::available($user, $contract),
            'history' => AuditEvent::query()->with('user:id,name')
                ->where('subject_type', $contract->getMorphClass())->where('subject_id', $contract->id)
                ->latest('created_at')->latest('id')->limit(50)->get(),
            'contractSeesCommission' => ContractTerms::seesCommission($user, $provider),
            'packageRoute' => Route::has('travel.packages.show'),
        ])->title('Contract '.$contract->contract_number);
    }

    private function contract(): ProviderContract
    {
        return ProviderContract::query()->with('provider')->findOrFail($this->contractId);
    }
}
