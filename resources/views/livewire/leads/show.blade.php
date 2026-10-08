<div class="grid gap-5">
    <div class="grid gap-3">
        <a href="{{ route('leads.index') }}" wire:navigate class="text-[13px] font-semibold text-brand-text hover:underline">← Leads</a>
        <x-ui.page-header :title="$lead->business_name" :description="$lead->propertyTypeLabel().($lead->location ? ' · '.$lead->location : '').($isOwner ? '' : ' · owned by '.$lead->user->name)">
            <x-slot:actions>
                <x-ui.pill :tone="$lead->status->tone()" class="text-[13px]">{{ $lead->status->label() }}</x-ui.pill>
                @if ($canTransfer)
                    <x-ui.button variant="secondary" size="sm" icon="user" wire:click="openTransfer">Transfer ownership</x-ui.button>
                @endif
                @if ($isOwner)
                    <x-ui.button variant="secondary" size="sm" wire:click="openEdit">Edit</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" icon="calendar" x-on:click="$dispatch('open-schedule', { leadId: {{ $lead->id }} })">Schedule</x-ui.button>
                    <x-ui.button size="sm" icon="plus" wire:click="openActivity">Log activity</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        {{-- Timeline --}}
        <div class="grid content-start gap-4">
            @if ($openFollowUps->isNotEmpty())
                <x-ui.card title="Upcoming schedule" :padding="false">
                    @foreach ($openFollowUps as $item)
                        <div wire:key="fu-{{ $item->id }}" class="flex items-center justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                            <div class="flex min-w-0 items-center gap-3">
                                <span @class(['grid size-8 shrink-0 place-items-center rounded-full', 'bg-brand-soft text-brand-text' => $item->isMeeting(), 'bg-surface-muted text-ink-muted' => ! $item->isMeeting()])><x-ui.icon :name="$item->type->icon()" class="size-4" /></span>
                                <div class="grid min-w-0 leading-tight">
                                    <span class="truncate font-semibold text-ink">{{ $item->type->label() }} — {{ $item->task }}</span>
                                    <span @class(['text-[13px]', 'font-semibold text-danger' => $item->isOverdue(), 'text-ink-subtle' => ! $item->isOverdue()])>
                                        {{ $item->isOverdue() ? 'Overdue · ' : '' }}{{ $item->due_at->isToday() ? 'Today' : $item->due_at->format('D j M Y') }} · {{ $item->timeLabel() }}{{ $item->contact_name ? ' · '.$item->contact_name : '' }}{{ $item->contact_role ? ', '.$item->contact_role : '' }}
                                    </span>
                                </div>
                            </div>
                            @if ($isOwner)
                                <div class="flex shrink-0 gap-1.5">
                                    <x-ui.button size="sm" variant="secondary" icon="check" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }}, complete: true })">Done</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }} })">Edit</x-ui.button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </x-ui.card>
            @endif

            <x-ui.card title="Activity history">
                @if ($lead->activities->isEmpty())
                    <x-ui.empty-state icon="activity" title="Nothing logged yet" description="Log calls, WhatsApp messages, visits and meetings to keep a clear history.">
                        @if ($isOwner)<x-ui.button size="sm" icon="plus" wire:click="openActivity">Log activity</x-ui.button>@endif
                    </x-ui.empty-state>
                @else
                    <ol class="grid">
                        @foreach ($lead->activities as $item)
                            <li wire:key="act-{{ $item->id }}" class="relative grid grid-cols-[36px_1fr] gap-3 pb-5 last:pb-0">
                                @unless ($loop->last)<span class="absolute top-9 bottom-0 left-[17px] w-px bg-line"></span>@endunless
                                <span class="relative z-10 grid size-9 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$item->type->icon()" class="size-4" /></span>
                                <div class="grid gap-1 pt-1">
                                    <p class="text-sm"><span class="font-bold text-ink">{{ $item->type->label() }}</span> <span class="text-ink-subtle">· {{ $item->happened_at->format('j M Y, H:i') }}</span></p>
                                    @if ($item->notes)<p class="text-sm whitespace-pre-line text-ink-muted">{{ $item->notes }}</p>@endif
                                    @if ($item->next_action)<p class="text-[13px] text-ink"><span class="font-semibold">Next:</span> {{ $item->next_action }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>

        {{-- Side panel --}}
        <div class="grid content-start gap-4">
            <x-ui.card title="Ownership" :padding="false">
                <div class="flex items-center gap-3 border-b border-line px-4 py-2.5">
                    <x-ui.avatar :user="$lead->user" size="sm" />
                    <div class="grid leading-tight">
                        <span class="text-[13px] font-semibold text-ink">{{ $lead->user->name }}</span>
                        <span class="text-xs text-ink-subtle">Current salesperson{{ $lead->transfers->first() ? ' since '.$lead->transfers->first()->created_at->format('j M Y') : '' }}</span>
                    </div>
                </div>
                @foreach ($lead->transfers as $transfer)
                    <div wire:key="tr-{{ $transfer->id }}" class="grid gap-0.5 border-b border-line px-4 py-2.5 text-xs last:border-b-0">
                        <span class="text-[13px] text-ink"><span class="text-ink-muted">{{ $transfer->fromUser->name }}</span> → <span class="font-medium">{{ $transfer->toUser->name }}</span></span>
                        <span class="text-ink-muted">{{ $transfer->reasonLabel() }} · by {{ $transfer->transferrer->name }} · {{ $transfer->created_at->format('j M Y, H:i') }}</span>
                        @if ($transfer->notes)<span class="text-ink-subtle">“{{ $transfer->notes }}”</span>@endif
                    </div>
                @endforeach
                <p class="px-4 py-2 text-[11px] text-ink-subtle">Added {{ $lead->created_at->format('j M Y') }}{{ $lead->transfers->last() ? ' by '.$lead->transfers->last()->fromUser->name : '' }}. Logged activity stays with whoever did it.</p>
            </x-ui.card>

            <x-ui.card title="Contact">                <dl class="grid gap-3 text-sm">
                    <div class="grid"><dt class="text-[13px] text-ink-subtle">Name</dt><dd class="font-semibold text-ink">{{ $lead->contact_name ?? '—' }}{{ $lead->contact_role ? ', '.$lead->contact_role : '' }}</dd></div>
                    <div class="grid"><dt class="text-[13px] text-ink-subtle">Phone</dt><dd class="text-ink select-all">{{ $lead->contact_phone ?? '—' }}</dd></div>
                    <div class="grid"><dt class="text-[13px] text-ink-subtle">Email</dt><dd class="break-all text-ink select-all">{{ $lead->contact_email ?? '—' }}</dd></div>
                </dl>
                @if ($lead->notes)
                    <p class="mt-4 border-t border-line pt-4 text-sm whitespace-pre-line text-ink-muted">{{ $lead->notes }}</p>
                @endif
            </x-ui.card>

            @if ($lead->propertyEngagement)
                <x-ui.card title="Property Engagement Registry">
                    <a href="{{ route('registry.show', $lead->propertyEngagement) }}" wire:navigate class="grid gap-1 hover:text-brand-text">
                        <span class="text-sm font-semibold text-ink">{{ $lead->propertyEngagement->name }}</span>
                        <span class="text-xs text-ink-muted">{{ $lead->propertyEngagement->stage->label() }} · {{ $lead->propertyEngagement->status->label() }} · first engaged {{ $lead->propertyEngagement->first_engaged_on->format('j M Y') }}</span>
                        <span class="text-xs font-semibold text-brand-text">View engagement history →</span>
                    </a>
                </x-ui.card>
            @endif

            @if (! $lead->propertyEngagement && $isOwner)
                <x-ui.card title="Property Engagement Registry" description="Not in the registry yet. Add it so managers and the team can see who is engaging this property.">
                    @if ($registryMatches)
                        <div class="mb-3 grid gap-2 rounded-lg border border-warning/40 bg-warning-soft p-3 text-[13px] text-ink">
                            <p class="font-semibold">The registry already has something that looks like this property:</p>
                            @foreach ($registryMatches as $match)
                                <a href="{{ route('registry.show', $match['id']) }}" wire:navigate class="hover:text-brand-text">{{ $match['name'] }}{{ $match['location'] ? ' · '.$match['location'] : '' }}{{ $match['owner'] ? ' · '.$match['owner'] : '' }}</a>
                            @endforeach
                            <p class="text-ink-muted">If it is the same business, ask a manager to link this lead to it. If it is a different one, add it anyway.</p>
                        </div>
                        <x-ui.button size="sm" icon="plus" wire:click="addToRegistry(true)" wire:loading.attr="disabled">Add anyway</x-ui.button>
                    @else
                        <x-ui.button size="sm" icon="plus" wire:click="addToRegistry" wire:loading.attr="disabled">Add to registry</x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            @if ($lead->onboarding)
                <x-ui.card title="tourlast.com signup">
                    <div class="grid gap-2">
                        <x-ui.onboarding-steps :onboarding="$lead->onboarding" class="mb-2" />
                        <x-ui.pill :tone="$lead->onboarding->status->tone()" class="justify-self-start">{{ $lead->onboarding->status->label() }}</x-ui.pill>
                        <p class="text-sm text-ink">{{ $lead->onboarding->property_name }}</p>
                        <p class="text-[13px] text-ink-subtle">Signed up {{ $lead->onboarding->submitted_at?->format('j M Y') }}{{ $lead->onboarding->credited_at ? ' · onboarded '.$lead->onboarding->credited_at->format('j M Y') : '' }}</p>
                    </div>
                </x-ui.card>
            @elseif ($isOwner && $lead->user->referralCode)
                <x-ui.card title="Send your link" description="This version of your link also tells you when this lead clicks it.">
                    @php($link = $lead->trackedLink($lead->user->referralCode))
                    <div class="grid gap-3" x-data="copyText(@js($link))">
                        <p class="truncate rounded-lg bg-surface-muted px-3 py-2 font-mono text-xs text-ink-muted" title="{{ $link }}">{{ $link }}</p>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button size="sm" icon="copy" x-on:click="copy"><span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span></x-ui.button>
                            @if ($lead->contact_phone)
                                <x-ui.button size="sm" variant="secondary" icon="chat" :href="'https://wa.me/'.preg_replace('/\D/', '', $lead->contact_phone).'?text='.rawurlencode('Hello'.($lead->contact_name ? ' '.\Illuminate\Support\Str::before($lead->contact_name, ' ') : '').', here is the link to list your property on Tourlast: '.$link)" target="_blank" rel="noopener">WhatsApp</x-ui.button>
                            @endif
                        </div>
                        <p class="text-[13px] text-ink-subtle">{{ $clicks ? $clicks.' '.\Illuminate\Support\Str::plural('click', $clicks).' so far' : 'Not clicked yet' }}</p>
                    </div>
                </x-ui.card>
            @endif

            @if ($lead->status === \App\Enums\LeadStatus::Lost)
                <x-ui.card title="Why it was lost">
                    <dl class="grid gap-2 text-[13px]">
                        <div class="grid"><dt class="text-xs text-ink-subtle">Primary objection</dt><dd class="font-semibold text-ink">{{ $lead->objection?->label() ?? $lead->lost_reason ?? '—' }}</dd></div>
                        @if ($lead->competitor)
                            <div class="grid"><dt class="text-xs text-ink-subtle">Competitor</dt><dd class="text-ink">{{ $lead->competitor }}</dd></div>
                        @endif
                        @if ($lead->lost_notes)
                            <div class="grid"><dt class="text-xs text-ink-subtle">Notes</dt><dd class="whitespace-pre-line text-ink-muted">{{ $lead->lost_notes }}</dd></div>
                        @endif
                        <div class="grid"><dt class="text-xs text-ink-subtle">Lost</dt><dd class="text-ink">{{ $lead->lost_at?->format('j M Y') ?? '—' }}</dd></div>
                        <div class="grid"><dt class="text-xs text-ink-subtle">Re-engage</dt><dd @class(['font-semibold', 'text-brand-text' => $lead->reengage_on, 'text-ink-subtle' => ! $lead->reengage_on])>{{ $lead->reengage_on?->format('j M Y') ?? 'Not scheduled' }}</dd></div>
                    </dl>
                </x-ui.card>
            @endif

            @if ($isOwner && $lead->status !== \App\Enums\LeadStatus::Onboarded)
                <x-ui.card title="Change status">
                    <div class="grid gap-3">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($manualStatuses as $option)
                                @continue($option === \App\Enums\LeadStatus::Lost || $option === $lead->status)
                                <x-ui.button wire:key="status-{{ $option->value }}" size="sm" variant="secondary" wire:click="setStatus('{{ $option->value }}')">{{ $lead->status === \App\Enums\LeadStatus::Lost ? 'Re-open as '.$option->label() : $option->label() }}</x-ui.button>
                            @endforeach
                            @if ($lead->status !== \App\Enums\LeadStatus::Lost)
                                <x-ui.button size="sm" variant="danger-ghost" wire:click="openLost">Mark lost…</x-ui.button>
                            @endif
                        </div>
                        <p class="text-[13px] text-ink-subtle">“Onboarded” is set automatically once their tourlast.com signup is approved.</p>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($isOwner)
        <x-ui.slide-over wire:model="showEdit" title="Edit lead">
            <form id="lead-edit-form" wire:submit="saveEdit" class="grid gap-4">
                @include('livewire.leads.partials.fields')
                <x-duplicate-matches :matches="$this->duplicates" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="lead-edit-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        <x-ui.slide-over wire:model="showActivity" title="Log activity" :description="$lead->business_name">
            <form id="activity-form" wire:submit="saveActivity" class="grid gap-4">
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-xs font-medium text-ink-muted">What happened?</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($activityTypes as $type)
                            <label wire:key="type-{{ $type->value }}" class="cursor-pointer">
                                <input type="radio" wire:model="activity.type" value="{{ $type->value }}" class="peer sr-only">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-line-strong px-3 py-1.5 text-[13px] font-semibold text-ink-muted peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand-text peer-focus-visible:outline-2 peer-focus-visible:outline-brand">
                                    <x-ui.icon :name="$type->icon()" class="size-3.5" /> {{ $type->label() }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <x-ui.input label="When" type="datetime-local" wire:model="activity.happened_at" id="activity-when" />
                <div class="grid gap-1.5">
                    <label for="activity-notes" class="text-xs font-medium text-ink-muted">Notes</label>
                    <textarea id="activity-notes" wire:model="activity.notes" rows="4" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none" placeholder="e.g. Met the GM. Discussed Tourlast distribution and listing on tourlast.com."></textarea>
                </div>
                <x-ui.input label="Next action" wire:model="activity.next_action" id="activity-next" placeholder="e.g. Send the List Your Property link" />
                <div class="grid grid-cols-[1fr_120px] gap-3">
                    <x-ui.input label="Follow up on" type="date" wire:model="activity.follow_up_at" id="activity-followup" hint="Optional. Adds it to your schedule." />
                    <x-ui.input label="At" type="time" wire:model="activity.follow_up_time" id="activity-followup-time" />
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="activity-form">Save activity</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>


    @endif
    @if ($isOwner)
        <x-ui.slide-over wire:model="showLost" title="Mark as lost" :description="$lead->business_name.' · a reason is required so management can see why properties say no.'">
            <form id="lost-form" wire:submit="markLost">
                <x-outcome-fields model="lost" :objection="$lost['objection'] ?? null" id="lost" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="lost-form" variant="danger">Mark lost</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif

    @if ($canTransfer)
        <x-ui.slide-over wire:model="showTransfer" title="Transfer ownership" :description="$lead->business_name">
            <form id="transfer-form" wire:submit="saveTransfer" class="grid gap-4">
                <div class="grid gap-0.5 rounded-md border border-line bg-surface-muted/60 px-3 py-2 text-[13px]">
                    <span class="text-xs text-ink-subtle">Current salesperson</span>
                    <span class="font-semibold text-ink">{{ $lead->user->name }}</span>
                </div>
                <x-ui.select label="Transfer to" wire:model="transfer.to" id="transfer-to">
                    <option value="">Choose…</option>
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Reason" wire:model.live="transfer.reason" id="transfer-reason">
                    <option value="">Choose…</option>
                    @foreach (\App\Models\LeadTransfer::Reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-1">
                    <label for="transfer-notes" class="text-xs font-medium text-ink-muted">Notes {{ ($transfer['reason'] ?? '') === 'other' ? '' : '(optional)' }}</label>
                    <textarea id="transfer-notes" wire:model="transfer.notes" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none" placeholder="e.g. Territory reassignment from 1 October."></textarea>
                    @error('transfer.notes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                @if ($lead->propertyEngagement)
                    <label class="flex items-start gap-2 text-[13px] text-ink">
                        <input type="checkbox" wire:model="transfer.with_registry" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                        <span>Also make them the representative of <strong>{{ $lead->propertyEngagement->name }}</strong> in the registry{{ $lead->propertyEngagement->salesRep && $lead->propertyEngagement->sales_rep_id !== $lead->user_id ? ' (only if '.$lead->user->name.' is its current rep; it is currently '.$lead->propertyEngagement->salesRep->name.')' : '' }}.</span>
                    </label>
                @endif
                <p class="text-xs text-ink-subtle">Open schedule items move to the new salesperson. Past calls, meetings and notes stay attributed to whoever did them. Recorded: previous and new salesperson, who transferred it, the date and the reason.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="transfer-form">Transfer ownership</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
