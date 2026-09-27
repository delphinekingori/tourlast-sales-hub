<div class="grid gap-5">
    <x-ui.page-header :eyebrow="$canSeeAll ? 'Sales' : 'Me'" title="Leads" description="Providers you're working with. Guide them to sign up on tourlast.com with your link; a lead turns Onboarded automatically when their signup is approved.">
        <x-slot:actions>
            @if ($canSell)
                <x-ui.button icon="plus" wire:click="openCreate">Add lead</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="status" id="lead-status" aria-label="Status" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="open">Open leads</option>
                <option value="all">All leads</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($canSeeAll)
                <select wire:model.live="owner" id="lead-owner" aria-label="Salesperson" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                    <option value="">Everyone</option>
                    @foreach ($owners as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}{{ $person->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </select>
                @if ($canTransfer && $owner !== '' && $ownerOpenCount > 0)
                    <x-ui.button variant="secondary" icon="user" wire:click="openBulkTransfer">Transfer {{ $ownerOpenCount }} open {{ \Illuminate\Support\Str::plural('lead', $ownerOpenCount) }}</x-ui.button>
                @endif
            @endif
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search business, contact or location" />
    </div>

    <x-ui.table-card :paginator="$leads">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Lead</th>
                    <th class="text-left">Contact</th>
                    @if ($canSeeAll)<th class="text-left">Salesperson</th>@endif
                    <th class="text-left">Status</th>
                    <th class="text-left">Last contact</th>
                    <th class="text-left">Next follow-up</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($leads as $lead)
                    <tr wire:key="lead-{{ $lead->id }}" class="hover:bg-surface-muted/60">
                        <td>
                            <a href="{{ route('leads.show', $lead) }}" wire:navigate class="grid leading-tight">
                                <span class="font-semibold text-ink hover:text-brand-text">{{ $lead->business_name }}</span>
                                <span class="text-[13px] text-ink-subtle">{{ $lead->propertyTypeLabel() }}{{ $lead->location ? ' · '.$lead->location : '' }}</span>
                            </a>
                        </td>
                        <td class="text-ink-muted"><div class="grid leading-tight"><span>{{ $lead->contact_name ?? '—' }}</span><span class="text-[13px] text-ink-subtle">{{ $lead->contact_role }}</span></div></td>
                        @if ($canSeeAll)<td class="text-ink-muted">{{ $lead->user->name }}</td>@endif
                        <td><x-ui.pill :tone="$lead->status->tone()">{{ $lead->status->label() }}</x-ui.pill></td>
                        <td class="text-ink-muted">{{ $lead->last_contacted_at?->diffForHumans() ?? 'Not yet' }}</td>
                        <td>
                            @if ($lead->nextFollowUp)
                                <span @class(['text-[13px]', 'font-semibold text-danger' => $lead->nextFollowUp->isOverdue(), 'text-ink-muted' => ! $lead->nextFollowUp->isOverdue()])>
                                    {{ $lead->nextFollowUp->due_at->isToday() ? 'Today' : $lead->nextFollowUp->due_at->format('D j M') }}
                                </span>
                            @else
                                <span class="text-ink-subtle">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canSeeAll ? 6 : 5 }}">
                        <x-ui.empty-state icon="target" title="No leads here" description="Add the providers you're talking to, so you can log calls and visits and never miss a follow-up.">
                            @if ($canSell)<x-ui.button size="sm" icon="plus" wire:click="openCreate">Add lead</x-ui.button>@endif
                        </x-ui.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    @if ($canSell)
        <x-ui.slide-over wire:model="showCreate" title="Add lead" description="A provider you're prospecting. Only you and your managers can see it.">
            <form id="lead-form" wire:submit="create" class="grid gap-4">
                @include('livewire.leads.partials.fields')

                <p class="-mt-1 text-xs text-ink-subtle">The Hub checks the Property Engagement Registry, every salesperson's leads and tourlast.com signups as you type.</p>

                <x-duplicate-matches :matches="$this->duplicates" continue confirm="confirmDifferent" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="lead-form" :disabled="$this->duplicates->isNotEmpty() && ! $confirmDifferent">Add lead</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
    @if ($canTransfer && $owner !== '')
        @php
            $fromName = $owners->firstWhere('id', (int) $owner)?->name;
        @endphp
        <x-ui.slide-over wire:model="showBulkTransfer" title="Transfer open leads" :description="'All '.$ownerOpenCount.' open leads of '.$fromName.'. Won and lost leads stay with them for the record.'">
            <form id="bulk-transfer-form" wire:submit="saveBulkTransfer" class="grid gap-4">
                <x-ui.select label="Transfer to" wire:model="bulk.to" id="bulk-to">
                    <option value="">Choose…</option>
                    @foreach ($transferTargets as $person)
                        @continue((string) $person->id === $owner)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Reason" wire:model.live="bulk.reason" id="bulk-reason">
                    <option value="">Choose…</option>
                    @foreach (\App\Models\LeadTransfer::Reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Notes" wire:model="bulk.notes" id="bulk-notes" :placeholder="($bulk['reason'] ?? '') === 'other' ? 'Required for Other' : 'Optional'" />
                <p class="text-xs text-ink-subtle">Each lead gets its own ownership record. Open schedule items move with them, and registry properties they represent follow too. Past activity stays attributed to {{ $fromName }}.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="bulk-transfer-form">Transfer {{ $ownerOpenCount }} {{ \Illuminate\Support\Str::plural('lead', $ownerOpenCount) }}</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>