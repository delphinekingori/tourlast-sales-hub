@php
    $mine = $item->user_id === auth()->id();
    $overdue = $item->isOverdue();
    $tone = match (true) {
        $item->isDone() => 'border-line bg-surface-muted/60 text-ink-subtle',
        $overdue => 'border-danger/30 bg-danger-soft/60',
        $item->isMeeting() => 'border-brand/25 bg-brand-soft/60',
        default => 'border-line bg-surface',
    };
    $url = $item->subjectUrl();
    $linkAttributes = match (true) {
        $mine => 'type="button" x-on:click="$dispatch(\'open-schedule\', { itemId: '.$item->id.' })"',
        $url !== null => 'href="'.e($url).'" wire:navigate',
        default => 'type="button"',
    };
    $tag = ! $mine && $url !== null ? 'a' : 'button';
@endphp

<{{ $tag }} {!! $linkAttributes !!} wire:key="cal-{{ $item->id }}"
    class="group grid w-full min-w-0 gap-0.5 rounded-md border px-2 py-1.5 text-left transition-colors hover:border-brand/50 {{ $tone }}"
    title="{{ $item->type->label() }} · {{ $item->task }} · {{ $item->subjectLabel() }}">
    <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] leading-tight">
        <x-ui.icon :name="$item->type->icon()" @class(['size-3.5 shrink-0', 'text-brand-text' => ! $item->isDone() && ! $overdue, 'text-danger' => $overdue])/>
        <span @class(['tabular shrink-0 font-semibold', 'text-ink' => ! $item->isDone(), 'line-through' => $item->isDone()])>{{ $item->timeLabel() }}</span>
        @if ($overdue)<span class="font-semibold text-danger">Overdue</span>@endif
        @if ($item->isDone())<x-ui.icon name="check" class="size-3 text-success" />@endif
    </span>
    <span @class(['truncate text-xs font-medium', 'text-ink' => ! $item->isDone(), 'line-through' => $item->isDone()])>{{ $item->subjectLabel() }}</span>
    @unless ($compact ?? false)
        <span class="truncate text-[11px] text-ink-muted">{{ $item->type->label() }} — {{ $item->task }}</span>
        @if ($item->contact_name)
            <span class="truncate text-[11px] text-ink-subtle">{{ $item->contact_name }}{{ $item->contact_role ? ' · '.$item->contact_role : '' }}</span>
        @endif
    @endunless
    @if ($showOwner ?? false)
        <span class="truncate text-[10.5px] font-medium text-ink-subtle">{{ $item->user->name }}</span>
    @endif
</{{ $tag }}>
