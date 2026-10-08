@php
    $c = $contract;
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $days = $c->daysUntilExpiry();
    $terms = array_filter([
        'Payment terms' => $c->payment_terms,
        'Settlement terms' => $c->settlement_terms,
        'Cancellation terms' => $c->cancellation_terms,
        'Refund terms' => $c->refund_terms,
        'Notes' => $c->notes,
    ], fn ($value) => filled($value));
    $needsNote = in_array($transitionAction, ['return_draft', 'suspend', 'terminate'], true);
@endphp

<div class="grid gap-5">
    <a href="{{ route('travel.providers.show', $provider->id) }}" wire:navigate class="text-[13px] font-medium text-brand-text hover:underline">← {{ $provider->name }}</a>

    @if ($state === \App\Enums\Travel\ContractStatus::Expired)
        <div class="flex items-center gap-2 rounded-xl border border-danger/30 bg-danger-soft px-4 py-2.5 text-[13px] text-danger">
            <x-ui.icon name="alert" class="size-4" /> This contract ended on {{ $c->ends_on->format('j M Y') }}. Packages on it cannot be published until a new contract is active.
        </div>
    @elseif ($state === \App\Enums\Travel\ContractStatus::ExpiringSoon)
        <div class="flex items-center gap-2 rounded-xl border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="clock" class="size-4 text-warning" /> This contract expires in {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} ({{ $c->ends_on->format('j M Y') }}).
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
            <div class="grid min-w-0 gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl leading-tight font-bold text-ink">{{ $c->contract_number }}</h1>
                    <x-ui.pill :tone="$state->tone()">{{ $state->label() }}</x-ui.pill>
                </div>
                <p class="text-sm text-ink-muted">{{ $c->contract_type }} with <a href="{{ route('travel.providers.show', $provider->id) }}" wire:navigate class="font-medium text-brand-text hover:underline">{{ $provider->name }}</a></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($actions as $action => $label)
                    <x-ui.button size="md" :variant="in_array($action, ['approve', 'submit_review', 'submit_approval', 'reactivate'], true) ? 'primary' : (in_array($action, ['terminate', 'suspend'], true) ? 'danger-ghost' : 'secondary')" wire:click="openTransition('{{ $action }}')">{{ $label }}</x-ui.button>
                @endforeach
                @if ($canEdit)
                    <x-ui.button variant="secondary" wire:click="openContract({{ $provider->id }}, {{ $c->id }})">Edit</x-ui.button>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['Starts', $c->starts_on->format('j M Y')],
                ['Ends', $c->ends_on ? $c->ends_on->format('j M Y') : 'Open-ended'],
                ['Commission model', $c->commission_model->label()],
                ['Commission', $seesCommission ? collect([$c->commission_rate !== null ? rtrim(rtrim((string) $c->commission_rate, '0'), '.').'%' : null, $c->fixed_commission !== null ? $c->currency.' '.number_format((float) $c->fixed_commission) : null])->filter()->implode(' + ') ?: '—' : 'Restricted'],
                ['Created by', ($c->creator?->name ?? '—').' · '.$c->created_at->format('j M Y')],
                ['Approved by', $c->approver ? $c->approver->name.' · '.$c->approved_at?->format('j M Y') : '—'],
            ] as [$label, $value])
                <div class="grid gap-0.5 bg-surface px-4 py-2.5">
                    <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd class="truncate text-[13px] font-semibold text-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="grid gap-4">
            <x-ui.card title="Terms">
                <dl class="grid gap-3 text-[13px]">
                    @forelse ($terms as $label => $value)
                        <div class="grid gap-0.5"><dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt><dd class="whitespace-pre-line text-ink">{{ $value }}</dd></div>
                    @empty
                        <p class="text-ink-subtle">No terms recorded yet.</p>
                    @endforelse
                </dl>
            </x-ui.card>

            <x-ui.card title="Documents" description="Signed contract, addenda, rate sheets and supporting documents (PDF or Word)" :padding="false">
                @if ($seesDocuments)
                    @php
                        $currentDocuments = $c->documents->filter->isCurrent();
                        $earlierDocuments = $c->documents->reject->isCurrent();
                        $replacing = $replacingDocumentId ? $currentDocuments->firstWhere('id', $replacingDocumentId) : null;
                    @endphp
                    <ul class="divide-y divide-line">
                        @forelse ($currentDocuments as $doc)
                            <li wire:key="doc-{{ $doc->id }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                                <span class="flex min-w-0 items-center gap-2">
                                    <x-ui.icon name="document" class="size-4 shrink-0 text-ink-subtle" />
                                    <span class="grid min-w-0 leading-tight">
                                        <a href="{{ route('travel.contracts.document', $doc->id) }}" target="_blank" class="truncate font-medium text-brand-text hover:underline">{{ $doc->original_name }}</a>
                                        <span class="truncate text-xs text-ink-subtle">{{ $doc->typeLabel() }} · {{ $doc->sizeLabel() }} · {{ $doc->uploader?->name }} · {{ $doc->created_at->format('j M Y') }}</span>
                                    </span>
                                </span>
                                @if ($canUpload)
                                    <span class="flex shrink-0 items-center gap-1">
                                        <x-ui.button size="sm" variant="ghost" wire:click="startReplacing({{ $doc->id }})">Replace</x-ui.button>
                                        <x-ui.button size="sm" variant="danger-ghost" wire:click="removeDocument({{ $doc->id }})" wire:confirm="Remove {{ $doc->original_name }} from this contract? It stays in the contract history.">Remove</x-ui.button>
                                    </span>
                                @endif
                            </li>
                        @empty
                            <li class="px-4 py-4 text-[13px] text-ink-subtle">No documents yet.</li>
                        @endforelse
                    </ul>
                    @if ($earlierDocuments->isNotEmpty())
                        <details class="border-t border-line px-4 py-2.5 text-[13px]">
                            <summary class="cursor-pointer text-xs font-medium text-ink-muted">Earlier versions and removed files ({{ $earlierDocuments->count() }})</summary>
                            <ul class="mt-2 grid gap-2">
                                @foreach ($earlierDocuments as $doc)
                                    <li wire:key="old-doc-{{ $doc->id }}" class="grid min-w-0 leading-tight">
                                        <a href="{{ route('travel.contracts.document', $doc->id) }}" target="_blank" class="truncate text-ink-muted hover:text-brand-text hover:underline">{{ $doc->original_name }}</a>
                                        <span class="truncate text-xs text-ink-subtle">
                                            {{ $doc->typeLabel() }} · {{ $doc->sizeLabel() }} · {{ $doc->uploader?->name }} · {{ $doc->created_at->format('j M Y') }} ·
                                            @if ($doc->removed_at)
                                                removed by {{ $doc->remover?->name ?? 'someone' }} on {{ $doc->removed_at->format('j M Y') }}
                                            @else
                                                replaced by {{ $doc->replacement?->original_name }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if ($canUpload)
                        <form wire:submit="uploadDocument" class="grid gap-2 border-t border-line px-4 py-3">
                            @if ($replacing)
                                <p class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                                    Replacing <span class="font-medium text-ink">{{ $replacing->original_name }}</span> (the earlier version is kept).
                                    <button type="button" wire:click="cancelReplacing" class="font-medium text-brand-text hover:underline">Cancel</button>
                                </p>
                            @endif
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="w-44">
                                    <x-ui.select label="Type" wire:model="documentType" id="doc-type">
                                        @foreach (\App\Models\ContractDocument::Types as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                                <div class="grid min-w-52 flex-1 gap-1">
                                    <label for="doc-file" class="text-xs font-medium text-ink-muted">File (PDF or Word, up to {{ \App\Models\ContractDocument::MaxKilobytes / 1024 }} MB)</label>
                                    <input id="doc-file" type="file" wire:model="document" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="text-[13px] text-ink-muted file:mr-2 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-2.5 file:py-1 file:text-[13px]">
                                    @error('document')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                                </div>
                                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="document,uploadDocument">{{ $replacing ? 'Upload new version' : 'Upload' }}</x-ui.button>
                            </div>
                        </form>
                    @endif
                @else
                    <p class="px-4 py-4 text-[13px] text-ink-subtle">Contract documents are visible to Finance, Travel managers and the provider's salesperson.</p>
                @endif
            </x-ui.card>

            <x-ui.card title="Packages on this contract" :padding="false">
                <ul class="divide-y divide-line">
                    @forelse ($c->packages as $package)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                            @if ($packageRoute)
                                <a href="{{ route('travel.packages.show', $package->id) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $package->name }}</a>
                            @else
                                <span class="font-medium text-ink">{{ $package->name }}</span>
                            @endif
                            <x-ui.pill :tone="$package->status->tone()">{{ $package->status->label() }}</x-ui.pill>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-[13px] text-ink-subtle">No packages use this contract yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card title="History" :padding="false">
            <ol class="divide-y divide-line">
                @forelse ($history as $event)
                    <li class="grid gap-0.5 px-4 py-2.5 text-[13px]">
                        <span class="text-ink">{{ $event->summary }}</span>
                        <span class="text-xs text-ink-subtle">{{ $event->user?->name ?? 'System' }} · {{ $event->created_at->format('j M Y, H:i') }}</span>
                        @if ($event->changes)
                            <span class="text-xs text-ink-muted">
                                @foreach ($event->changes as $field => [$old, $new])
                                    @continue(in_array($field, ['commission_rate', 'fixed_commission'], true) && ! $seesCommission)
                                    <span class="block">{{ str_replace('_', ' ', $field) }}: {{ is_scalar($old) && $old !== '' ? \Illuminate\Support\Str::limit((string) $old, 40) : '—' }} → {{ is_scalar($new) && $new !== '' ? \Illuminate\Support\Str::limit((string) $new, 40) : '—' }}</span>
                                @endforeach
                            </span>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-4 text-[13px] text-ink-subtle">No history yet.</li>
                @endforelse
            </ol>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showTransition" :title="$transitionAction ? \App\Support\Travel\ContractTerms::Transitions[$transitionAction][2] : 'Change status'" :description="$transitionAction === 'approve' ? 'The contract becomes active and its packages can be published.' : null">
        <form wire:submit="applyTransition" id="transition-form" class="grid gap-1">
            <label for="transition-note" class="text-xs font-medium text-ink-muted">{{ $needsNote ? 'Reason *' : 'Note (optional)' }}</label>
            <textarea id="transition-note" rows="3" wire:model="transitionNote" class="{{ $textarea }}"></textarea>
            @error('transitionNote')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="transition-form" :variant="in_array($transitionAction, ['terminate', 'suspend'], true) ? 'danger' : 'primary'">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @include('livewire.travel.providers.partials.contract-modal')
</div>
