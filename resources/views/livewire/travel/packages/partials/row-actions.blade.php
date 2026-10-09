@php
    $actor = auth()->user();
    $canChange = \App\Support\Travel\TravelAccess::canChange($actor, $package->owner_id) && ! $package->archived_at;
    $working = $package->workingVersion;
    $canSubmit = $canChange && $working && $working->isEditable();
    $canWithdraw = $canChange && $working && $working->isAwaitingReview();
    $canPublish = $canChange && $package->live_version_id && in_array($package->status, [\App\Enums\Travel\PackageStatus::Approved, \App\Enums\Travel\PackageStatus::Unpublished], true);
    $canUnpublish = $canChange && $package->status === \App\Enums\Travel\PackageStatus::Published;
@endphp
<div class="relative inline-block text-left" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" x-on:click="open = ! open" class="rounded-md p-1 text-ink-subtle hover:bg-surface-muted hover:text-ink" aria-label="Actions for {{ $package->name }}">
        <x-ui.icon name="dots" class="size-4" />
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-30 mt-1 grid w-52 rounded-lg border border-line bg-surface py-1 text-left shadow-overlay">
        <a href="{{ route('travel.packages.show', $package) }}" wire:navigate class="px-3 py-1.5 text-[13px] text-ink hover:bg-surface-muted">View</a>
        @if ($canChange && ! $canWithdraw)
            <a href="{{ route('travel.packages.edit', $package) }}" wire:navigate class="px-3 py-1.5 text-[13px] text-ink hover:bg-surface-muted">Edit</a>
        @endif
        <button type="button" wire:click="openDuplicate({{ $package->id }})" x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Duplicate</button>
        @if ($canSubmit)
            <button type="button" wire:click="submitPackage({{ $package->id }})" x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Submit for approval</button>
        @endif
        @if ($canWithdraw)
            <button type="button" wire:click="withdrawPackage({{ $package->id }})" x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Withdraw from review</button>
        @endif
        <a href="{{ route('travel.packages.show', ['package' => $package, 'tab' => 'approvals']) }}" wire:navigate class="px-3 py-1.5 text-[13px] text-ink hover:bg-surface-muted">Approval history</a>
        @if ($canPublish)
            <button type="button" wire:click="openPublish({{ $package->id }})" x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Mark as published</button>
        @endif
        @if ($canUnpublish)
            <button type="button" wire:click="openUnpublish({{ $package->id }})" x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Unpublish</button>
        @endif
        @if ($canChange)
            <button type="button" wire:click="archivePackage({{ $package->id }})" wire:confirm="Archive {{ $package->name }}? It will no longer be sold." x-on:click="open = false" class="px-3 py-1.5 text-left text-[13px] text-danger hover:bg-danger-soft">Archive</button>
        @endif
    </div>
</div>
