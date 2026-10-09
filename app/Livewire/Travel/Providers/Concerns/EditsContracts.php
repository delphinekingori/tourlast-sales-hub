<?php

namespace App\Livewire\Travel\Providers\Concerns;

use App\Actions\Travel\Providers\SaveProviderContract;
use App\Actions\Travel\Providers\StoreContractDocument;
use App\Enums\Travel\CommissionModel;
use App\Models\ContractDocument;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Support\Travel\ContractTerms;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * The add / edit contract pop-up, shared by the provider and contract pages.
 * The component must also use Livewire's WithFileUploads.
 */
trait EditsContracts
{
    public bool $showContract = false;

    #[Locked]
    public ?int $editingContractId = null;

    #[Locked]
    public ?int $contractProviderId = null;

    /** @var array<string, mixed> */
    public array $contractForm = [];

    /** The signed contract file (PDF or Word), optional. */
    public $contractFile = null;

    #[Locked]
    public bool $contractCanAttach = false;

    /** Name and size of the signed contract on file, if any. */
    #[Locked]
    public ?string $contractCurrentFile = null;

    public function openContract(int $providerId, ?int $contractId = null): void
    {
        $user = Auth::user();
        $provider = TravelProvider::query()->findOrFail($providerId);
        $contract = $contractId ? $provider->contracts()->findOrFail($contractId) : null;
        abort_unless(ContractTerms::canEdit($user, $provider, $contract), 403);

        $this->contractProviderId = $provider->id;
        $this->editingContractId = $contract?->id;
        $this->contractForm = [
            'contract_number' => $contract?->contract_number ?? ProviderContract::nextNumber(),
            'contract_type' => $contract?->contract_type ?? 'Commission agreement',
            'starts_on' => $contract?->starts_on?->toDateString() ?? today()->toDateString(),
            'ends_on' => $contract?->ends_on?->toDateString() ?? today()->addYear()->toDateString(),
            'commission_model' => $contract?->commission_model?->value ?? CommissionModel::Percentage->value,
            'commission_rate' => $contract?->commission_rate,
            'fixed_commission' => $contract?->fixed_commission,
            'currency' => $contract?->currency ?? config('travel.currency', 'KES'),
            'payment_terms' => $contract?->payment_terms,
            'settlement_terms' => $contract?->settlement_terms,
            'cancellation_terms' => $contract?->cancellation_terms,
            'refund_terms' => $contract?->refund_terms,
            'notes' => $contract?->notes,
        ];
        $this->contractCanAttach = ContractTerms::canAttach($user, $provider);
        $signed = $contract && $this->contractCanAttach ? $this->signedContractFile($contract) : null;
        $this->contractCurrentFile = $signed ? $signed->original_name.' ('.$signed->sizeLabel().')' : null;
        $this->contractFile = null;
        $this->resetErrorBag();
        $this->showContract = true;
    }

    public function saveContract(SaveProviderContract $save, StoreContractDocument $storeDocument): void
    {
        $provider = TravelProvider::query()->findOrFail($this->contractProviderId);
        $contract = $this->editingContractId ? $provider->contracts()->findOrFail($this->editingContractId) : null;

        $data = $this->validate([
            'contractForm.contract_number' => ['required', 'string', 'max:40', Rule::unique('provider_contracts', 'contract_number')->ignore($contract?->id)],
            'contractForm.contract_type' => ['required', 'string', 'max:60'],
            'contractForm.starts_on' => ['required', 'date'],
            'contractForm.ends_on' => ['nullable', 'date', 'after_or_equal:contractForm.starts_on'],
            'contractForm.commission_model' => ['required', Rule::enum(CommissionModel::class)],
            'contractForm.commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contractForm.fixed_commission' => ['nullable', 'numeric', 'min:0'],
            'contractForm.currency' => ['required', 'string', 'size:3'],
            'contractForm.payment_terms' => ['nullable', 'string', 'max:5000'],
            'contractForm.settlement_terms' => ['nullable', 'string', 'max:5000'],
            'contractForm.cancellation_terms' => ['nullable', 'string', 'max:5000'],
            'contractForm.refund_terms' => ['nullable', 'string', 'max:5000'],
            'contractForm.notes' => ['nullable', 'string', 'max:5000'],
            'contractFile' => ['nullable', ...ContractDocument::fileRules()],
        ], [], [
            'contractForm.contract_number' => 'contract number',
            'contractForm.ends_on' => 'end date',
            'contractForm.starts_on' => 'start date',
            'contractFile' => 'contract document',
        ])['contractForm'];

        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
        $data['currency'] = strtoupper((string) $data['currency']);

        $saved = $save->handle($data, Auth::user(), $provider, $contract);

        if ($this->contractFile) {
            $storeDocument->handle($saved, $this->contractFile, 'signed_contract', Auth::user(), $this->signedContractFile($saved));
        }

        $this->reset('contractFile');
        $this->showContract = false;
        $this->dispatch('toast', message: $contract ? 'Contract updated.' : 'Contract '.$saved->contract_number.' created as a draft.');
    }

    /**
     * The newest signed contract still in use; a new upload replaces it.
     */
    private function signedContractFile(ProviderContract $contract): ?ContractDocument
    {
        return $contract->currentDocuments()->where('type', 'signed_contract')->latest('id')->first();
    }
}
