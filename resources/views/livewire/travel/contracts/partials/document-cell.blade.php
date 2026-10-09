{{-- Contract-list cell: the signed contract (or newest file) to download, plus how many more. Needs $contract with currentDocuments loaded and $visible. --}}
@php
    $documents = $contract->currentDocuments;
    $main = $documents->firstWhere('type', 'signed_contract') ?? $documents->first();
@endphp
@if (! $visible)
    <span class="text-xs text-ink-subtle">Restricted</span>
@elseif ($main)
    <span class="inline-grid max-w-56 justify-items-end text-right leading-tight">
        <a href="{{ route('travel.contracts.document', $main->id) }}" target="_blank" class="flex max-w-full items-center gap-1 font-medium text-brand-text hover:underline" title="{{ $main->original_name }}">
            <x-ui.icon name="document" class="size-3.5 shrink-0" />
            <span class="truncate">{{ $main->original_name }}</span>
        </a>
        <span class="text-xs text-ink-subtle">{{ $main->sizeLabel() }}@if ($documents->count() > 1) · {{ $documents->count() - 1 }} more @endif</span>
    </span>
@else
    <span class="text-xs text-ink-subtle">None</span>
@endif
