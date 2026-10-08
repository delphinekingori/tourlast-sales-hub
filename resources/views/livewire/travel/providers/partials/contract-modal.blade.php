{{-- Add / edit contract pop-up (EditsContracts). Needs $contractSeesCommission. --}}
@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<x-ui.modal wire:model="showContract" :title="$editingContractId ? 'Edit contract' : 'New contract'" description="New contracts start as drafts and need a Travel manager's approval before they are active." maxWidth="max-w-2xl">
    <form wire:submit="saveContract" id="contract-form" class="grid gap-3 sm:grid-cols-2">
        <x-ui.input label="Contract number *" wire:model="contractForm.contract_number" id="contract-number" />
        <x-ui.input label="Contract type *" wire:model="contractForm.contract_type" id="contract-type" hint="e.g. Commission agreement, Net rate agreement" />
        <x-ui.input label="Start date *" type="date" wire:model="contractForm.starts_on" id="contract-starts" />
        <x-ui.input label="End date" type="date" wire:model="contractForm.ends_on" id="contract-ends" />
        <x-ui.select label="Commission model *" wire:model.live="contractForm.commission_model" id="contract-model">
            @foreach (\App\Enums\Travel\CommissionModel::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="Currency *" wire:model="contractForm.currency" id="contract-currency" maxlength="3" />
        @if ($contractSeesCommission)
            <x-ui.input label="Commission rate (%)" type="number" step="0.01" wire:model="contractForm.commission_rate" id="contract-rate" />
            <x-ui.input label="Fixed commission per booking" type="number" step="0.01" wire:model="contractForm.fixed_commission" id="contract-fixed" />
        @else
            <p class="text-xs text-ink-subtle sm:col-span-2">Commission figures are visible only to Finance, Travel managers and the provider's salesperson.</p>
        @endif
        @foreach ([
            'payment_terms' => 'Payment terms',
            'settlement_terms' => 'Settlement terms',
            'cancellation_terms' => 'Cancellation terms',
            'refund_terms' => 'Refund terms',
            'notes' => 'Notes',
        ] as $field => $label)
            <div class="grid gap-1 sm:col-span-2">
                <label for="contract-{{ $field }}" class="text-xs font-medium text-ink-muted">{{ $label }}</label>
                <textarea id="contract-{{ $field }}" rows="2" wire:model="contractForm.{{ $field }}" class="{{ $textarea }}"></textarea>
                @error('contractForm.'.$field)<p class="text-xs text-danger">{{ $message }}</p>@enderror
            </div>
        @endforeach
        @if ($contractCanAttach)
            <div class="grid gap-1 sm:col-span-2">
                <label for="contract-file" class="text-xs font-medium text-ink-muted">Contract document (PDF or Word, up to {{ \App\Models\ContractDocument::MaxKilobytes / 1024 }} MB)</label>
                @if ($contractCurrentFile)
                    <p class="text-xs text-ink-subtle">On file: <span class="font-medium text-ink">{{ $contractCurrentFile }}</span>. Choosing a new file replaces it; the earlier version stays in the contract's history.</p>
                @endif
                <input id="contract-file" type="file" wire:model="contractFile" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="text-[13px] text-ink-muted file:mr-2 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-2.5 file:py-1 file:text-[13px]">
                <p wire:loading wire:target="contractFile" class="text-xs text-ink-subtle">Uploading…</p>
                @error('contractFile')<p class="text-xs text-danger">{{ $message }}</p>@enderror
            </div>
        @endif
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button type="submit" form="contract-form" wire:loading.attr="disabled" wire:target="contractFile,saveContract">{{ $editingContractId ? 'Save contract' : 'Create draft' }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
