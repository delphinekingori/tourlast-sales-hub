@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $title = match (true) {
        $mode === 'complete' => 'Mark as done',
        $itemId !== null => 'Edit schedule',
        default => 'Schedule',
    };
@endphp

<div>
    <x-ui.slide-over wire:model="show" :title="$title" :description="match (true) { $mode === 'complete' && $travel => 'Record what happened. The outcome stays on this calendar item.', $mode === 'complete' => 'Record what happened. It is added to the lead\'s history and the property\'s engagement history.', $travel => 'A follow-up, meeting or check-in about a provider, package, booking, client or flight. Leave the time empty for an anytime reminder.', default => 'A call, meeting or visit with a property. Leave the time empty for an anytime reminder.' }">
        @if ($mode === 'complete' && $item)
            <div class="mb-4 grid gap-1 rounded-lg border border-line bg-surface-muted/60 px-3 py-2.5">
                <span class="flex items-center gap-2 text-[13px] font-semibold text-ink"><x-ui.icon :name="$item->type->icon()" class="size-4 text-brand-text" /> {{ $item->type->label() }} · {{ $item->task }}</span>
                <span class="text-xs text-ink-muted">{{ $item->lead?->business_name ?? \App\Support\Travel\TravelSubjects::label($item->subject) }} · {{ $item->due_at->format('D j M') }} · {{ $item->timeLabel() }}{{ $item->contact_name ? ' · '.$item->contact_name : '' }}</span>
            </div>
            <form id="schedule-done-form" wire:submit="complete" class="grid gap-4">
                <div class="grid gap-1">
                    <label for="done-outcome" class="text-xs font-medium text-ink-muted">Outcome</label>
                    <textarea id="done-outcome" wire:model="done.outcome" rows="3" class="{{ $textarea }}" placeholder="e.g. GM requested a commercial proposal."></textarea>
                </div>
                <div class="grid gap-3 rounded-lg border border-line p-3">
                    <p class="text-xs font-semibold text-ink">Next step <span class="font-normal text-ink-subtle">(optional)</span></p>
                    <x-ui.input label="Next action" wire:model="done.next_action" id="done-next" placeholder="e.g. Send proposal" />
                    <div class="grid grid-cols-[1fr_1fr_110px] gap-2">
                        <x-ui.select label="Type" wire:model="done.next_type" id="done-next-type">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input label="Follow-up date" type="date" wire:model="done.next_date" id="done-next-date" min="{{ now()->toDateString() }}" />
                        <x-ui.input label="Time" type="time" wire:model="done.next_time" id="done-next-time" />
                    </div>
                </div>
            </form>
        @else
            <form id="schedule-form" wire:submit="save" class="grid gap-4">
                @if ($travel)
                    <x-ui.select label="About" wire:model="form.subject" id="sch-subject" hint="The provider, package, booking, client or flight this is about.">
                        <option value="">Choose…</option>
                        @foreach ($subjects as $group => $options)
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-ui.select>
                @else
                    <x-ui.select label="Property / lead" wire:model.live="form.lead_id" id="sch-lead" hint="Search the Property Engagement Registry first; schedule against your lead for the property.">
                        <option value="">Choose…</option>
                        @foreach ($leads as $lead)
                            <option value="{{ $lead->id }}">{{ $lead->business_name }}{{ $lead->location ? ' · '.$lead->location : '' }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select label="Type" wire:model="form.type" id="sch-type">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Title" wire:model="form.task" id="sch-task" placeholder="e.g. Proposal discussion" />
                </div>
                <div class="grid grid-cols-[1fr_110px_110px] gap-2">
                    <x-ui.input label="Date" type="date" wire:model="form.date" id="sch-date" />
                    <x-ui.input label="Time" type="time" wire:model="form.time" id="sch-time" />
                    <x-ui.select label="Duration" wire:model="form.duration" id="sch-duration">
                        @foreach (['15' => '15 min', '30' => '30 min', '45' => '45 min', '60' => '1 hour', '90' => '1.5 hours', '120' => '2 hours', '180' => '3 hours'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Contact" wire:model="form.contact_name" id="sch-contact" placeholder="e.g. John Wambua" />
                    <x-ui.input label="Their role" wire:model="form.contact_role" id="sch-role" placeholder="e.g. General Manager" />
                </div>
                <x-ui.input label="Location" wire:model="form.location" id="sch-location" placeholder="Property address, office or 'Phone'" />
                <div class="grid gap-1">
                    <label for="sch-notes" class="text-xs font-medium text-ink-muted">Notes</label>
                    <textarea id="sch-notes" wire:model="form.notes" rows="2" class="{{ $textarea }}" placeholder="What to prepare or agree."></textarea>
                </div>
                @if ($item && ! $item->isDone())
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                        <x-ui.button size="sm" variant="secondary" icon="check" wire:click="startComplete">Mark as done…</x-ui.button>
                        <x-ui.button size="sm" variant="danger-ghost" wire:click="delete" wire:confirm="Remove this from your schedule?">Remove</x-ui.button>
                    </div>
                @endif
            </form>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            @if ($mode === 'complete')
                <x-ui.button type="submit" form="schedule-done-form" icon="check">Mark as done</x-ui.button>
            @else
                <x-ui.button type="submit" form="schedule-form">{{ $itemId ? 'Save' : 'Schedule' }}</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.slide-over>
</div>
