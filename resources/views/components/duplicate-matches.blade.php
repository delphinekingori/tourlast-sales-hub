@props([
    'matches',
    'continue' => false,
    'confirm' => null,
    'confirmLabel' => 'None of these — this is a different property.',
])

{{--
    The Hub-wide "possible existing property" warning. $matches comes from
    App\Support\PropertyDuplicateCheck. With continue=true each match offers
    "Continue existing engagement" (the component handles continueWith()).
--}}
@if ($matches->isNotEmpty())
    <section {{ $attributes->merge(['class' => 'grid gap-2 rounded-lg border border-warning/40 bg-warning-soft/40 p-3']) }} aria-live="polite">
        <p class="flex items-center gap-2 text-[13px] font-semibold text-ink">
            <x-ui.icon name="alert" class="size-4 text-warning" />
            {{ $matches->count() === 1 ? 'Possible existing property found' : $matches->count().' possible existing properties found' }}
        </p>

        @foreach ($matches as $match)
            @php
                $ownerLine = match (true) {
                    $match['owner'] === null => $match['kind'] === 'onboarding' ? 'Signed up on tourlast.com without a salesperson' : 'No salesperson assigned',
                    $match['ownerIsViewer'] => $match['kind'] === 'lead' ? 'Your lead' : 'Assigned to you',
                    $match['active'] => ($match['kind'] === 'onboarding' ? 'Referred by ' : 'Being worked by ').$match['owner'],
                    default => 'Previously contacted by '.$match['owner'],
                };
                $blockedByColleague = $match['kind'] === 'lead' && ! $match['ownerIsViewer'] && $match['active'] && ! $match['engagementId'];
            @endphp
            <div wire:key="dup-{{ $match['key'] }}" class="grid gap-1.5 rounded-md border border-line bg-surface p-2.5">
                <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                    <span class="text-[13px] font-semibold text-ink">{{ $match['name'] }}{{ $match['location'] ? ' — '.$match['location'] : '' }}</span>
                    <span class="text-[11px] font-medium text-ink-subtle uppercase">{{ ['registry' => 'Registry', 'lead' => 'Lead', 'onboarding' => 'tourlast.com'][$match['kind']] }}{{ $match['archived'] ? ' · archived' : '' }}</span>
                </div>
                <dl class="grid gap-0.5 text-xs">
                    <div @class(['font-medium', 'text-danger' => $match['active'] && ! $match['ownerIsViewer'] && $match['owner'], 'text-ink' => ! ($match['active'] && ! $match['ownerIsViewer'] && $match['owner'])])>{{ $ownerLine }}</div>
                    <div class="text-ink-muted">Current stage: <span class="text-ink">{{ $match['stage'] }}</span></div>
                    <div class="text-ink-muted">Last contacted: <span class="text-ink">{{ $match['lastContacted']?->format('j M Y') ?? '—' }}</span></div>
                </dl>
                <div class="flex flex-wrap gap-1">
                    @foreach ($match['reasons'] as $reason)<x-ui.pill tone="warning" :dot="false">{{ $reason }}</x-ui.pill>@endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2 pt-0.5">
                    @if ($match['viewUrl'])
                        <x-ui.button size="sm" variant="secondary" :href="$match['viewUrl']" target="_blank">View existing record</x-ui.button>
                    @endif
                    @if ($continue)
                        @if ($match['kind'] === 'lead' && $match['ownerIsViewer'])
                            <x-ui.button size="sm" wire:click="continueWith('lead', {{ $match['leadId'] }})">Continue existing engagement</x-ui.button>
                        @elseif ($match['engagementId'])
                            <x-ui.button size="sm" wire:click="continueWith('registry', {{ $match['engagementId'] }})">Continue existing engagement</x-ui.button>
                        @elseif ($blockedByColleague)
                            <span class="text-xs text-ink-muted">{{ $match['owner'] }} is working on this. Speak to your manager before approaching.</span>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach

        @if ($confirm)
            <label class="flex items-start gap-2 px-1 pt-1 text-xs text-ink">
                <input type="checkbox" wire:model.live="{{ $confirm }}" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                <span>{{ $confirmLabel }}</span>
            </label>
            @error($confirm)<p class="px-1 text-xs text-danger">{{ $message }}</p>@enderror
        @endif
    </section>
@endif
