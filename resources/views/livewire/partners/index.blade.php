<div class="grid gap-5">
    <x-ui.page-header eyebrow="Partners" title="Partner Register" description="Partners onboarded on tourlast.com through a salesperson's referral link. Statuses come from tourlast.com.">
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="register" :href="route('partners.report', $exportQuery)">PDF report</x-ui.button>
                <x-ui.button icon="arrow-right" :href="route('partners.export', $exportQuery)">Export Excel</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card>
        <div class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select label="Status" wire:model.live="status" id="reg-status">
                    @foreach (\App\Support\PartnerRegisterFilters::statusOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Salesperson" wire:model.live="salesperson" id="reg-salesperson">
                    <option value="">Everyone</option>
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Property type" wire:model.live="type" id="reg-type">
                    <option value="">All types</option>
                    @foreach (config('hub.property_types') as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-1.5">
                    <span class="text-xs font-medium text-ink-muted">Search</span>
                    <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Property, location or code" wide />
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40"><x-ui.input :label="$filters->dateLabel().' from'" type="date" wire:model.live="from" id="reg-from" /></div>
                <div class="w-40"><x-ui.input label="to" type="date" wire:model.live="to" id="reg-to" /></div>
                <div class="flex flex-wrap gap-1 pb-0.5">
                    @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last-month' => 'Last month', 'all' => 'All time'] as $key => $label)
                        <x-ui.button size="sm" variant="ghost" wire:click="setRange('{{ $key }}')" wire:key="range-{{ $key }}">{{ $label }}</x-ui.button>
                    @endforeach
                </div>
            </div>
        </div>
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="font-bold text-ink">{{ $total }} {{ \Illuminate\Support\Str::plural('partner', $total) }}</span>
        <span class="text-ink-subtle">· {{ $filters->periodLabel() }}</span>
        @foreach ($byType as $key => $count)
            <x-ui.pill tone="brand" :dot="false">{{ config('hub.property_types.'.$key, 'Other') }} · {{ $count }}</x-ui.pill>
        @endforeach
    </div>

    <x-ui.table-card :paginator="$partners">
        <table class="w-full min-w-[980px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Property</th>
                    <th class="text-left">Type</th>
                    <th class="text-left">Salesperson</th>
                    <th class="text-left">Referral code</th>
                    <th class="text-left">Contact</th>
                    <th class="text-left">{{ $filters->dateLabel() }}</th>
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($partners as $partner)
                    @php($date = $partner->{$filters->dateColumn()})
                    <tr wire:key="partner-{{ $partner->id }}">
                        <td><div class="grid leading-tight"><span class="font-semibold text-ink">{{ $partner->property_name }}</span><span class="text-[13px] text-ink-subtle">{{ $partner->location ?? '—' }}</span></div></td>
                        <td class="text-ink-muted">{{ $partner->propertyTypeLabel() }}</td>
                        <td class="text-ink-muted">
                            {{ $partner->user?->name ?? 'Unattributed' }}
                            @if ($partner->attribution === 'manual')<span class="text-xs text-warning">· assigned</span>@endif
                        </td>
                        <td class="font-mono text-[13px] text-brand-text">{{ $partner->ref_code ?? '—' }}</td>
                        <td class="text-ink-muted"><div class="grid leading-tight"><span>{{ $partner->contact_name ?? '—' }}</span><span class="text-[13px] text-ink-subtle">{{ $partner->contact_phone }}</span></div></td>
                        <td class="text-ink-muted">{{ $date?->format('j M Y') ?? '—' }}</td>
                        <td><x-ui.pill :tone="$partner->status->tone()">{{ $partner->status->label() }}</x-ui.pill></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="register" title="No partners match these filters" description="Try a wider date range or a different status." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
