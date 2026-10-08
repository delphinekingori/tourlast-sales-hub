@php
    $working = $package->workingVersion;
@endphp
@if ($working)
    <span class="inline-flex flex-wrap items-center gap-1">
        <x-ui.pill :tone="$working->status->tone()">{{ $working->status->label() }}</x-ui.pill>
        @if ($package->live_version_id && $working->material_changes)
            <x-ui.pill tone="danger" :dot="false">Approval required</x-ui.pill>
        @endif
    </span>
@elseif ($package->liveVersion)
    <x-ui.pill tone="success">Approved {{ $package->liveVersion->label() }}</x-ui.pill>
@else
    <span class="text-ink-subtle">—</span>
@endif
