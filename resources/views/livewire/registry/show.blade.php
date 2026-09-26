@php
    $e = $engagement;
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $currentPeriod = $e->reps->first(fn ($rep) => $rep->isCurrent());
    $info = array_filter([
        'Type' => $e->propertyTypeLabel(),
        'Star rating' => $e->starRatingLabel(),
        'Rooms / units' => $e->rooms,
        'Estimated capacity' => $e->capacity,
        'Trading name' => $e->trading_name,
        'Registration name' => $e->registration_name,
        'Registration number' => $e->registration_number,
        'KRA PIN' => $e->kra_pin,
        'Tourlast property ID' => $e->tourlast_property_id,
    ], fn ($value) => filled($value));
    $location = array_filter([
        'Country' => $e->country,
        'County / region' => $e->region,
        'City / town' => $e->city,
        'Area' => $e->area,
        'Address' => $e->address,
        'Coordinates' => $e->latitude && $e->longitude ? $e->latitude.', '.$e->longitude : null,
    ], fn ($value) => filled($value));
@endphp

<div class="grid gap-5" x-data="{ history: 'all' }">
    <a href="{{ route('registry.index') }}" wire:navigate class="text-[13px] font-medium text-brand-text hover:underline">← Property Engagement Registry</a>

    @if ($e->trashed())
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-2.5 text-[13px] text-danger">
            <x-ui.icon name="lock" class="size-4" />
            <span>This record is archived. Its history is kept but it no longer appears in the registry.</span>
            @can('restore', $e)
                <x-ui.button size="sm" variant="secondary" class="ml-auto" wire:click="restore">Restore</x-ui.button>
            @endcan
        </div>
    @endif

    {{-- Header --}}
    <section class="rounded-xl border border-line bg-surface shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
            <div class="grid min-w-0 gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl leading-tight font-bold text-ink">{{ $e->name }}</h1>
                    @unless ($canManage)
                        <span class="inline-flex items-center gap-1 rounded border border-line px-1.5 py-px text-[10.5px] font-semibold tracking-wide text-ink-subtle uppercase"><x-ui.icon name="lock" class="size-3" /> Read only</span>
                    @endunless
                </div>
                <p class="text-sm text-ink-muted">{{ $e->propertyTypeLabel() }}{{ $e->starRatingLabel() ? ' · '.$e->starRatingLabel() : '' }} · {{ $e->locationLabel() }}, {{ $e->country }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="text-xs text-ink-subtle">Stage</span>
                    <x-ui.pill :tone="$e->stage->tone()" :dot="false">{{ $e->stage->order() }}. {{ $e->stage->label() }}</x-ui.pill>
                    <span class="ml-2 text-xs text-ink-subtle">Status</span>
                    <x-ui.pill :tone="$e->status->tone()">{{ $e->status->label() }}</x-ui.pill>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($canManage)
                    <x-ui.button icon="plus" wire:click="openLog">Log engagement</x-ui.button>
                    <x-ui.button variant="secondary" icon="user" wire:click="openReassign">{{ $e->sales_rep_id ? 'Transfer ownership' : 'Assign property' }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('registry.edit', $e)" wire:navigate>Edit</x-ui.button>
                @endif
                @if ($canArchive && ! $e->trashed())
                    <x-ui.button variant="danger-ghost" wire:click="archive" wire:confirm="Archive {{ $e->name }}? It will be hidden from the registry, but its history is kept and it can be restored.">Archive</x-ui.button>
                @endif
            </div>
        </div>

        {{-- Key facts --}}
        <dl class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
            <div class="grid gap-1 bg-surface px-5 py-3">
                <dt class="text-xs font-medium text-ink-subtle">Current sales representative</dt>
                <dd>
                    @if ($e->salesRep)
                        <span class="flex items-center gap-2"><x-ui.avatar :user="$e->salesRep" size="sm" /><span class="grid leading-tight"><span class="text-[13px] font-semibold text-ink">{{ $e->salesRep->name }}</span>@if ($currentPeriod)<span class="text-[11px] text-ink-subtle">since {{ $currentPeriod->started_on->format('j M Y') }}</span>@endif</span></span>
                    @else
                        <span class="text-[13px] text-ink-subtle">Unassigned</span>
                    @endif
                </dd>
            </div>
            @foreach ([
                'First engaged' => $e->first_engaged_on->format('j M Y'),
                'Last engaged' => $e->last_engaged_on?->format('j M Y') ?? '—',
                'Engagements logged' => $interactionCount,
                'Source' => $e->source?->label() ?? '—',
                'Next action' => $e->next_action ? $e->next_action.($e->next_action_on ? ' · '.$e->next_action_on->format('j M') : '') : '—',
            ] as $label => $value)
                <div class="grid content-start gap-1 bg-surface px-5 py-3">
                    <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd @class(['text-[13px] font-semibold', 'text-danger' => $label === 'Next action' && $e->next_action_on?->isPast() && ! $e->next_action_on->isToday(), 'text-ink' => ! ($label === 'Next action' && $e->next_action_on?->isPast() && ! $e->next_action_on->isToday())])>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div class="grid min-w-0 gap-4">
            {{-- Engagement summary --}}
            <x-ui.card title="Engagement summary">
                @if ($e->summary)
                    <p class="text-sm whitespace-pre-line text-ink">{{ $e->summary }}</p>
                @else
                    <p class="text-[13px] text-ink-subtle">No summary recorded yet.</p>
                @endif
                @if ($e->reps->count() > 1)
                    <p class="mt-3 text-xs text-ink-muted">Engaged by {{ $e->reps->count() }} salespeople over time: {{ $e->reps->reverse()->map(fn ($rep) => $rep->user->name)->unique()->implode(' → ') }}.</p>
                @endif
            </x-ui.card>

            {{-- Engagement history --}}
            <x-ui.card title="Engagement history" :description="'Newest first · '.$timeline->count().' entries · includes calls and meetings on linked leads'" :padding="false">
                <x-slot:actions>
                    <div class="inline-flex rounded-md border border-line bg-surface-muted p-0.5 text-xs" role="tablist">
                        <button type="button" x-on:click="history = 'all'" :class="history === 'all' ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted'" class="rounded px-2.5 py-1 font-medium">Everything</button>
                        <button type="button" x-on:click="history = 'engagement'" :class="history === 'engagement' ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted'" class="rounded px-2.5 py-1 font-medium">Engagement only</button>
                    </div>
                </x-slot:actions>

                @if ($upcoming->isNotEmpty())
                    <div class="grid gap-1.5 border-b border-line bg-brand-soft/30 px-4 py-3">
                        <p class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Upcoming</p>
                        @foreach ($upcoming as $next)
                            <div wire:key="up-{{ $next->id }}" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]">
                                <x-ui.icon :name="$next->type->icon()" class="size-3.5 text-brand-text" />
                                <span @class(['font-semibold', 'text-danger' => $next->isOverdue(), 'text-ink' => ! $next->isOverdue()])>{{ $next->due_at->format('j M Y') }} · {{ $next->timeLabel() }}</span>
                                <span class="text-ink">{{ $next->type->label() }} — {{ $next->task }}</span>
                                <span class="text-ink-muted">· {{ $next->user->name }}{{ $next->contact_name ? ' with '.$next->contact_name : '' }}{{ $next->contact_role ? ', '.$next->contact_role : '' }}</span>
                                @if ($next->isOverdue())<x-ui.pill tone="danger">Overdue</x-ui.pill>@endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <ol class="px-4 py-3">
                    @forelse ($timeline as $entry)
                        @if ($entry['kind'] === 'activity')
                            @php
                                $activity = $entry['item'];
                            @endphp
                            <li wire:key="la-{{ $activity->id }}" class="relative grid grid-cols-[28px_1fr] gap-3 pb-4 last:pb-0">
                                @unless ($loop->last)<span class="absolute top-7 bottom-0 left-[13px] w-px bg-line"></span>@endunless
                                <span class="relative z-10 grid size-7 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$activity->type->icon()" class="size-3.5" /></span>
                                <div class="grid min-w-0 gap-0.5 pt-0.5">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="text-[13px] font-semibold text-ink">{{ $activity->happened_at->format('j M Y') }}</span>
                                        <span class="text-[13px] text-ink-muted">{{ $activity->user?->name }}</span>
                                        <span class="text-[13px] font-medium text-ink">· {{ $activity->type->label() }}</span>
                                        <span class="text-[11px] text-ink-subtle">via lead</span>
                                    </div>
                                    @if ($activity->notes)
                                        <p class="text-[13px] whitespace-pre-line text-ink-muted">“{{ $activity->notes }}”</p>
                                    @endif
                                    @if ($activity->next_action)
                                        <p class="text-xs text-ink"><span class="font-semibold">Next action:</span> {{ $activity->next_action }}</p>
                                    @endif
                                </div>
                            </li>
                        @else
                            @php
                                $event = $entry['item'];
                                $transition = $event->transition();
                                $isInteraction = $event->type->isInteraction();
                            @endphp
                            <li wire:key="ev-{{ $event->id }}" x-show="history === 'all' || @js($isInteraction)" class="relative grid grid-cols-[28px_1fr] gap-3 pb-4 last:pb-0">
                                @unless ($loop->last)<span class="absolute top-7 bottom-0 left-[13px] w-px bg-line"></span>@endunless
                                <span @class([
                                    'relative z-10 grid size-7 place-items-center rounded-full',
                                    'bg-brand-soft text-brand-text' => $isInteraction,
                                    'bg-surface-muted text-ink-subtle ring-1 ring-line' => ! $isInteraction,
                                ])><x-ui.icon :name="$event->type->icon()" class="size-3.5" /></span>
                                <div class="grid min-w-0 gap-0.5 pt-0.5">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="text-[13px] font-semibold text-ink">{{ $event->happened_at->format('j M Y') }}</span>
                                        <span class="text-[13px] text-ink-muted">{{ $event->salesRep?->name ?? ($event->type === \App\Enums\EngagementEventType::Onboarding ? 'tourlast.com' : 'Tourlast') }}</span>
                                        <span @class(['text-[13px]', 'font-medium text-ink' => $isInteraction, 'text-ink-muted' => ! $isInteraction])>· {{ $event->type->label() }}</span>
                                    </div>
                                    @if ($transition)
                                        <p class="text-[13px] text-ink">
                                            <span class="text-ink-muted">{{ $transition['from'] ?? '—' }}</span> → <span class="font-medium">{{ $transition['to'] ?? '—' }}</span>
                                        </p>
                                    @endif
                                    @if ($event->summary)
                                        <p class="text-[13px] text-ink">{{ $event->summary }}</p>
                                    @endif
                                    @if ($event->notes)
                                        <p class="text-[13px] whitespace-pre-line text-ink-muted">“{{ $event->notes }}”</p>
                                    @endif
                                    @if ($event->type === \App\Enums\EngagementEventType::Edited && is_array($event->changes))
                                        <ul class="mt-0.5 grid gap-0.5 text-xs text-ink-muted">
                                            @foreach ($event->changes as $change)
                                                @if (is_array($change) && isset($change['label']))
                                                    <li><span class="text-ink-subtle">{{ $change['label'] }}:</span> <span class="line-through decoration-ink-subtle/60">{{ \Illuminate\Support\Str::limit($change['from'] ?? '—', 60) }}</span> → {{ \Illuminate\Support\Str::limit($change['to'] ?? '—', 60) }}</li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if (! empty($event->changes['duplicate_override']))
                                        <p class="text-xs text-warning">Added after confirming it differs from {{ count($event->changes['duplicate_override']) }} similar registry {{ \Illuminate\Support\Str::plural('record', count($event->changes['duplicate_override'])) }}.</p>
                                    @endif
                                    @if ($event->recorder && $event->recorder->id !== $event->sales_rep_id)
                                        <p class="text-[11px] text-ink-subtle">Recorded by {{ $event->recorder->name }} · {{ $event->created_at->format('j M Y, H:i') }}</p>
                                    @endif
                                </div>
                            </li>
                        @endif
                    @empty
                        <li><x-ui.empty-state icon="clock" title="No history yet" /></li>
                    @endforelse
                </ol>
            </x-ui.card>
        </div>

        <div class="grid min-w-0 gap-4">
            @if ($e->objection || $e->reengage_on)
                <x-ui.card :title="in_array($e->status, [\App\Enums\EngagementStatus::Lost, \App\Enums\EngagementStatus::Rejected], true) ? 'Why it was lost' : 'Last objection'">
                    <dl class="grid gap-2 text-[13px]">
                        @if ($e->objection)
                            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Primary objection</dt><dd class="text-right font-semibold text-ink">{{ $e->objection->label() }}</dd></div>
                        @endif
                        @if ($e->competitor)
                            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Competitor</dt><dd class="text-right text-ink">{{ $e->competitor }}</dd></div>
                        @endif
                        @if ($e->closed_at)
                            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Closed</dt><dd class="text-right text-ink">{{ $e->closed_at->format('j M Y') }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Re-engage</dt><dd @class(['text-right font-semibold', 'text-brand-text' => $e->reengage_on, 'text-ink-subtle' => ! $e->reengage_on])>{{ $e->reengage_on?->format('j M Y') ?? 'Not scheduled' }}</dd></div>
                        @if ($e->outcome_notes)
                            <p class="text-xs whitespace-pre-line text-ink-muted">“{{ $e->outcome_notes }}”</p>
                        @endif
                    </dl>
                </x-ui.card>
            @endif
            <x-ui.card title="Property information">
                <dl class="grid gap-2 text-[13px]">
                    @foreach ($info as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">{{ $label }}</dt><dd class="text-right text-ink">{{ $value }}</dd></div>
                    @endforeach
                    @if ($e->website)
                        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Website</dt><dd class="truncate text-right"><a href="{{ str_contains($e->website, '://') ? $e->website : 'https://'.$e->website }}" target="_blank" rel="noopener" class="text-brand-text hover:underline">{{ $e->website_key ?? $e->website }}</a></dd></div>
                    @endif
                    <div class="my-1 border-t border-line"></div>
                    @foreach ($location as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">{{ $label }}</dt><dd class="text-right text-ink">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Contacts" :padding="false">
                @if ($canManage)
                    <x-slot:actions><x-ui.button size="sm" variant="ghost" icon="plus" wire:click="openContact">Add</x-ui.button></x-slot:actions>
                @endif
                @forelse ($e->contacts as $contact)
                    <div wire:key="c-{{ $contact->id }}" class="grid gap-1 border-b border-line px-4 py-2.5 last:border-b-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-[13px] font-semibold text-ink">{{ $contact->name }}</span>
                            @if ($contact->is_primary)<x-ui.pill tone="brand" :dot="false">Primary</x-ui.pill>@endif
                            @if ($contact->is_decision_maker)<x-ui.pill tone="success" :dot="false">Decision maker</x-ui.pill>@endif
                        </div>
                        <span class="text-xs text-ink-muted">{{ $contact->title ?? '—' }}</span>
                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                            @if ($contact->phone)<a href="tel:{{ $contact->phone }}" class="text-ink hover:text-brand-text">{{ $contact->phone }}</a>@endif
                            @if ($contact->whatsapp)<a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->whatsapp) }}" target="_blank" rel="noopener" class="text-ink hover:text-brand-text">WhatsApp {{ $contact->whatsapp }}</a>@endif
                            @if ($contact->email)<a href="mailto:{{ $contact->email }}" class="truncate text-ink hover:text-brand-text">{{ $contact->email }}</a>@endif
                        </div>
                        @if ($canManage && ! $contact->is_primary)
                            <div class="flex gap-3 text-xs">
                                <button type="button" wire:click="makePrimary({{ $contact->id }})" class="font-medium text-brand-text hover:underline">Make primary</button>
                                <button type="button" wire:click="removeContact({{ $contact->id }})" wire:confirm="Remove {{ $contact->name }}? Their details stay in the history." class="font-medium text-danger hover:underline">Remove</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state icon="user" title="No contacts" />
                @endforelse
            </x-ui.card>

            <x-ui.card title="Sales representatives" :padding="false">
                @forelse ($e->reps as $rep)
                    <div wire:key="rep-{{ $rep->id }}" class="flex items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                        <x-ui.avatar :user="$rep->user" size="sm" />
                        <div class="grid min-w-0 flex-1 leading-tight">
                            <span class="truncate text-[13px] font-medium text-ink">{{ $rep->user->name }}</span>
                            <span class="text-xs text-ink-subtle">{{ $rep->started_on->format('j M Y') }} – {{ $rep->ended_on?->format('j M Y') ?? 'now' }}</span>
                            @if ($rep->reason || $rep->assigner)
                                <span class="text-[11px] text-ink-subtle">{{ collect([$rep->reasonLabel(), $rep->assigner ? 'by '.$rep->assigner->name : null])->filter()->implode(' · ') }}</span>
                            @endif
                        </div>
                        @if ($rep->isCurrent())<x-ui.pill tone="brand" :dot="false">Current</x-ui.pill>@else<x-ui.pill tone="neutral" :dot="false">Previous</x-ui.pill>@endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-subtle">No representative assigned.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Onboarding information" :padding="false">
                @forelse ($e->onboardings as $onboarding)
                    <div wire:key="ob-{{ $onboarding->id }}" class="grid gap-2 border-b border-line px-4 py-3 last:border-b-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="grid leading-tight">
                                <span class="text-[13px] font-semibold text-ink">{{ $onboarding->property_name }}</span>
                                <span class="text-xs text-ink-subtle">tourlast.com {{ $onboarding->tourlast_property_id }} · {{ $onboarding->user?->name ?? 'Unattributed' }}</span>
                            </div>
                            <x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill>
                        </div>
                        <x-ui.onboarding-steps :onboarding="$onboarding" />
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-subtle">Not linked to a tourlast.com signup yet. Once linked, the stage follows tourlast.com automatically.</p>
                @endforelse
                @if ($canManage && $suggestedOnboardings->isNotEmpty())
                    <div class="grid gap-1.5 border-t border-line bg-surface-muted/50 px-4 py-2.5">
                        <p class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Possible tourlast.com signups</p>
                        @foreach ($suggestedOnboardings as $candidate)
                            <div wire:key="so-{{ $candidate->id }}" class="flex items-center justify-between gap-2 text-xs">
                                <span class="min-w-0 truncate text-ink">{{ $candidate->property_name }} <span class="text-ink-subtle">· {{ $candidate->status->label() }} · {{ $candidate->user?->name ?? 'Unattributed' }}</span></span>
                                <x-ui.button size="sm" variant="secondary" wire:click="linkOnboarding({{ $candidate->id }})">Link</x-ui.button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Related Tourlast records" :padding="false">
                @forelse ($e->leads as $lead)
                    <a wire:key="lead-{{ $lead->id }}" href="{{ route('leads.show', $lead) }}" wire:navigate class="flex items-center justify-between gap-2 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-[13px] font-medium text-ink">Lead · {{ $lead->business_name }}</span>
                            <span class="text-xs text-ink-subtle">{{ $lead->user->name }} · added {{ $lead->created_at->format('j M Y') }}</span>
                        </span>
                        <x-ui.pill :tone="$lead->status->tone()">{{ $lead->status->label() }}</x-ui.pill>
                    </a>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-subtle">No salesperson leads linked.</p>
                @endforelse
                @if ($canManage && $suggestedLeads->isNotEmpty())
                    <div class="grid gap-1.5 border-t border-line bg-surface-muted/50 px-4 py-2.5">
                        <p class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Possible matching leads</p>
                        @foreach ($suggestedLeads as $candidate)
                            <div wire:key="sl-{{ $candidate->id }}" class="flex items-center justify-between gap-2 text-xs">
                                <span class="min-w-0 truncate text-ink">{{ $candidate->business_name }} <span class="text-ink-subtle">· {{ $candidate->user->name }} · {{ $candidate->status->label() }}</span></span>
                                <x-ui.button size="sm" variant="secondary" wire:click="linkLead({{ $candidate->id }})">Link</x-ui.button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <p class="px-1 text-[11px] text-ink-subtle">
                Added by {{ $e->creator?->name ?? 'Tourlast' }} on {{ $e->created_at->format('j M Y') }}@if ($e->editor) · last changed by {{ $e->editor->name }} {{ $e->updated_at->diffForHumans() }}@endif.
            </p>
        </div>
    </div>

    @if ($canManage)
        {{-- Log engagement --}}
        <x-ui.slide-over wire:model="showLog" title="Log engagement" description="Adds an entry to the history. If another salesperson made the contact, they become the current representative.">
            <form id="log-form" wire:submit="saveLog" class="grid gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select label="Type" wire:model="log.type" id="log-type">
                        @foreach (\App\Enums\EngagementEventType::interactions() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Date" type="date" wire:model="log.happened_on" id="log-date" max="{{ now()->toDateString() }}" />
                </div>
                <x-ui.select label="Salesperson" wire:model="log.rep" id="log-rep">
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}{{ $person->id === $e->sales_rep_id ? ' (current)' : '' }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="What happened" wire:model="log.summary" id="log-summary" placeholder="e.g. Meeting completed with the GM" />
                <div class="grid gap-1">
                    <label for="log-notes" class="text-xs font-medium text-ink-muted">Notes</label>
                    <textarea id="log-notes" wire:model="log.notes" rows="3" class="{{ $textarea }}" placeholder="e.g. GM requested a commercial proposal."></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select label="Move stage to" wire:model="log.stage" id="log-stage">
                        <option value="">Keep: {{ $e->stage->label() }}</option>
                        @foreach (\App\Enums\EngagementStage::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Set status to" wire:model.live="log.status" id="log-status">
                        <option value="">Keep: {{ $e->status->label() }}</option>
                        @foreach (\App\Enums\EngagementStatus::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                @if (in_array($log['status'] ?? '', ['lost', 'rejected', 'closed', 'stalled'], true))
                    <div class="grid gap-2 rounded-md border border-warning/40 bg-warning-soft/40 p-3">
                        <p class="text-xs font-semibold text-ink">{{ in_array($log['status'], ['lost', 'rejected'], true) ? 'Why did they say no? (required)' : 'Why is it '.($log['status'] === 'stalled' ? 'stalled' : 'closed').'? (optional)' }}</p>
                        <x-outcome-fields model="log" notes="outcome_notes" :objection="$log['objection'] ?? null" :required="in_array($log['status'], ['lost', 'rejected'], true)" id="log-outcome" />
                    </div>
                @endif
                <div class="grid grid-cols-[1fr_140px] gap-3">
                    <x-ui.input label="Next action" wire:model="log.next_action" id="log-next" />
                    <x-ui.input label="By" type="date" wire:model="log.next_action_on" id="log-next-on" />
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="log-form">Add to history</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        {{-- Assign / transfer ownership --}}
        <x-ui.slide-over wire:model="showReassign" :title="$e->sales_rep_id ? 'Transfer ownership' : 'Assign property'" description="The previous salesperson's period is closed and kept in the history; their earlier engagement stays attributed to them.">
            <form id="reassign-form" wire:submit="saveReassign" class="grid gap-4">
                <div class="grid gap-1 rounded-md border border-line bg-surface-muted/60 px-3 py-2 text-[13px]">
                    <span class="text-xs text-ink-subtle">Property</span>
                    <span class="font-semibold text-ink">{{ $e->name }}</span>
                    <span class="text-xs text-ink-subtle">Current salesperson: <span class="font-medium text-ink">{{ $e->salesRep?->name ?? 'Unassigned' }}</span></span>
                </div>
                <x-ui.select :label="$e->sales_rep_id ? 'Transfer to' : 'Assign to'" wire:model="newRep" id="reassign-rep">
                    <option value="">Choose…</option>
                    @foreach ($salespeople as $person)
                        @continue($person->id === $e->sales_rep_id)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Reason" wire:model.live="repReason" id="reassign-reason">
                    <option value="">Choose…</option>
                    @foreach (\App\Models\LeadTransfer::Reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-1">
                    <label for="reassign-notes" class="text-xs font-medium text-ink-muted">Notes {{ $repReason === 'other' ? '' : '(optional)' }}</label>
                    <textarea id="reassign-notes" wire:model="repNotes" rows="2" class="{{ $textarea }}" placeholder="e.g. John moves to the Western region from October."></textarea>
                    @error('repNotes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <p class="text-xs text-ink-subtle">Recorded: previous salesperson, new salesperson, who made the change, the date and the reason.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="reassign-form">{{ $e->sales_rep_id ? 'Transfer ownership' : 'Assign property' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        {{-- Add contact --}}
        <x-ui.slide-over wire:model="showContact" title="Add contact" description="Another person at {{ $e->name }}.">
            <form id="contact-form" wire:submit="saveContact" class="grid gap-4">
                <x-ui.input label="Name" wire:model="contact.name" id="ct-name" />
                <x-ui.select label="Job title / position" wire:model="contact.title" id="ct-title">
                    <option value="">Choose…</option>
                    @foreach (config('hub.contact_titles') as $title)
                        <option value="{{ $title }}">{{ $title }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Phone" type="tel" wire:model="contact.phone" id="ct-phone" />
                    <x-ui.input label="WhatsApp" type="tel" wire:model="contact.whatsapp" id="ct-whatsapp" />
                </div>
                <x-ui.input label="Email" type="email" wire:model="contact.email" id="ct-email" />
                <label class="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" wire:model="contact.is_decision_maker" class="size-4 accent-[var(--tl-brand)]"> Decision maker</label>
                <label class="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" wire:model="contact.is_primary" class="size-4 accent-[var(--tl-brand)]"> Make primary contact</label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="contact-form">Add contact</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
