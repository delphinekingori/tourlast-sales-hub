@php
    $columns = ['type' => 'Type', 'location' => 'Location', 'stage' => 'Stage', 'status' => 'Status', 'rep' => 'Sales representative', 'first' => 'First engaged', 'last' => 'Last engaged'];
    $moreFilters = $country || $region || $city || $source || $firstFrom || $firstTo || $lastFrom || $lastTo || $activity || $onboarded || $archived;
@endphp

<div class="grid gap-5"
    x-data="{
        more: @js((bool) $moreFilters),
        cols: (() => { try { return JSON.parse(localStorage.getItem('tl-registry-cols')) ?? {} } catch (e) { return {} } })(),
        shown(key) { return this.cols[key] !== false },
        toggle(key) { this.cols[key] = ! this.shown(key); try { localStorage.setItem('tl-registry-cols', JSON.stringify(this.cols)) } catch (e) {} },
    }"
>
    <x-ui.page-header title="Property Engagement Registry" description="Every property and business Tourlast has engaged, with who engaged it, when, what happened and where it stands now. Search here before approaching a property.">
        <x-slot:actions>
            @if ($canExport)
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <x-ui.button variant="secondary" icon="register" x-on:click="open = ! open" x-bind:aria-expanded="open">Generate report</x-ui.button>
                    <form x-show="open" x-cloak x-transition.origin.top.right method="GET" action="{{ route('registry.report') }}" x-on:submit="open = false"
                        class="absolute right-0 z-30 mt-1 grid w-72 gap-3 rounded-lg border border-line bg-surface p-3 shadow-overlay">
                        <p class="text-[13px] font-semibold text-ink">Property engagement report</p>
                        @foreach (collect($filters->toQuery())->except(['sort', 'dir']) as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <div class="grid grid-cols-2 gap-2">
                            <x-ui.input label="From" type="date" name="from" id="report-from" value="{{ now()->startOfMonth()->toDateString() }}" />
                            <x-ui.input label="To" type="date" name="to" id="report-to" value="{{ now()->endOfMonth()->toDateString() }}" />
                        </div>
                        <p class="text-xs text-ink-subtle">Includes properties first engaged or engaged again in the period{{ $filters->hasFilters() || $search !== '' ? ', within the current filters' : '' }}.</p>
                        <x-ui.button type="submit" class="justify-self-end">Download PDF</x-ui.button>
                    </form>
                </div>
                <x-ui.button variant="secondary" icon="arrow-right" :href="route('registry.export', $filters->toQuery())">Export Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button icon="plus" :href="route('registry.create')" wire:navigate>Add property</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Total properties', $summary['total'], 'text-ink'],
            ['Currently engaged', $summary['engaged'], 'text-brand-text'],
            ['Onboarding', $summary['onboarding'], 'text-ink'],
            ['Stalled', $summary['stalled'], $summary['stalled'] ? 'text-warning' : 'text-ink'],
            ['Lost / rejected', $summary['lost'], 'text-ink'],
            ['Live partners', $summary['live'], 'text-success'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- Needs attention --}}
    @if (array_sum($attentionCounts) > 0 || $unassignedSignups)
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2.5 shadow-card">
            <span class="mr-1 text-[13px] font-semibold text-ink">Needs attention</span>
            @foreach (\App\Support\EngagementRegistryFilters::Attention as $group => $label)
                @if ($attentionCounts[$group] > 0 || $attention === $group)
                    <button type="button" wire:click="$set('attention', '{{ $attention === $group ? '' : $group }}')" aria-pressed="{{ $attention === $group ? 'true' : 'false' }}"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium {{ $attention === $group ? 'border-brand bg-brand-soft text-brand-text' : 'border-line-strong text-ink hover:bg-surface-muted' }}">
                        {{ $label }} <span class="tabular font-bold {{ $attention === $group ? '' : 'text-warning' }}">{{ number_format($attentionCounts[$group]) }}</span>
                    </button>
                @endif
            @endforeach
            @if ($unassignedSignups)
                <a href="{{ route('onboardings.unattributed') }}" wire:navigate class="ml-auto text-[13px] font-semibold text-brand-text hover:underline">
                    {{ number_format($unassignedSignups) }} {{ \Illuminate\Support\Str::plural('signup', $unassignedSignups) }} waiting for a salesperson →
                </a>
            @endif
        </div>
    @endif

    {{-- Search and filters --}}
    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-56 flex-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search property, business, location, contact, phone, salesperson..." wide aria-label="Search the registry" />
            </div>
            <select wire:model.live="stage" aria-label="Stage" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All stages</option>
                @foreach (\App\Enums\EngagementStage::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" aria-label="Status" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All statuses</option>
                @foreach (\App\Enums\EngagementStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="type" aria-label="Property type" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All types</option>
                @foreach (config('hub.property_types') as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="rep" aria-label="Salesperson" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All salespeople</option>
                @foreach ($salespeople as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </select>
            <x-ui.button variant="ghost" icon="cog" x-on:click="more = ! more" x-bind:aria-expanded="more">More filters</x-ui.button>
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <x-ui.button variant="ghost" icon="register" x-on:click="open = ! open">Columns</x-ui.button>
                <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-30 mt-1 grid w-52 gap-0.5 rounded-lg border border-line bg-surface p-1.5 shadow-overlay">
                    @foreach ($columns as $key => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-ink hover:bg-surface-muted">
                            <input type="checkbox" class="size-3.5 accent-[var(--tl-brand)]" x-bind:checked="shown('{{ $key }}')" x-on:change="toggle('{{ $key }}')"> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-show="more" x-cloak class="grid gap-3 border-t border-line pt-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6">
            <x-ui.select label="Country" wire:model.live="country" id="reg-country">
                <option value="">Any</option>
                @foreach ($countries as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="County / region" wire:model.live="region" id="reg-region">
                <option value="">Any</option>
                @foreach ($regions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="City / town" wire:model.live="city" id="reg-city">
                <option value="">Any</option>
                @foreach ($cities as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Engagement source" wire:model.live="source" id="reg-source">
                <option value="">Any</option>
                @foreach (\App\Enums\EngagementSource::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Active / inactive" wire:model.live="activity" id="reg-activity">
                <option value="">Any</option>
                <option value="active">Active (being worked)</option>
                <option value="inactive">Inactive (won, lost, closed)</option>
            </x-ui.select>
            <x-ui.select label="Onboarded" wire:model.live="onboarded" id="reg-onboarded">
                <option value="">Any</option>
                <option value="yes">Onboarded (live)</option>
                <option value="no">Not onboarded</option>
            </x-ui.select>
            <x-ui.input label="First engaged from" type="date" wire:model.live="firstFrom" id="reg-first-from" />
            <x-ui.input label="First engaged to" type="date" wire:model.live="firstTo" id="reg-first-to" />
            <x-ui.input label="Last engaged from" type="date" wire:model.live="lastFrom" id="reg-last-from" />
            <x-ui.input label="Last engaged to" type="date" wire:model.live="lastTo" id="reg-last-to" />
            @if ($canManage)
                <label class="flex items-center gap-2 self-end pb-2 text-[13px] text-ink-muted">
                    <input type="checkbox" wire:model.live="archived" class="size-4 accent-[var(--tl-brand)]"> Show archived only
                </label>
            @endif
        </div>

        @if ($filters->hasFilters() || $search !== '')
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-medium text-ink">{{ number_format($engagements->total()) }} {{ \Illuminate\Support\Str::plural('property', $engagements->total()) }} found</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">Clear all</button>
            </div>
        @endif
    </div>

    {{-- Registry --}}
    <x-ui.table-card :paginator="$engagements">
        <table class="w-full min-w-[1080px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    @php
                        $sortable = function (string $key, string $label) use ($sort, $dir) {
                            $active = $sort === $key;
                            $arrow = $active ? ($dir === 'asc' ? '↑' : '↓') : '';

                            return '<button type="button" wire:click="sortBy(\''.$key.'\')" class="inline-flex items-center gap-1 uppercase hover:text-white'.($active ? ' text-white' : '').'">'.e($label).'<span class="text-[10px]">'.$arrow.'</span></button>';
                        };
                    @endphp
                    <th class="text-left">{!! $sortable('name', 'Property / business') !!}</th>
                    <th class="text-left" x-show="shown('type')">{!! $sortable('type', 'Type') !!}</th>
                    <th class="text-left" x-show="shown('location')">{!! $sortable('location', 'Location') !!}</th>
                    <th class="text-left" x-show="shown('stage')">{!! $sortable('stage', 'Current stage') !!}</th>
                    <th class="text-left" x-show="shown('status')">{!! $sortable('status', 'Status') !!}</th>
                    <th class="text-left" x-show="shown('rep')">{!! $sortable('rep', 'Sales representative') !!}</th>
                    <th class="text-left" x-show="shown('first')">{!! $sortable('first', 'First engaged') !!}</th>
                    <th class="text-left" x-show="shown('last')">{!! $sortable('last', 'Last engaged') !!}</th>
                    <th class="text-right"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($engagements as $engagement)
                    <tr wire:key="pe-{{ $engagement->id }}">
                        <td class="max-w-72 min-w-56">
                            <a href="{{ route('registry.show', $engagement) }}" wire:navigate class="grid leading-tight">
                                <span class="truncate font-semibold text-ink hover:text-brand-text">{{ $engagement->name }}</span>
                                <span class="truncate text-xs text-ink-subtle">
                                    {{ $engagement->primaryContact?->name ?? 'No contact' }}{{ $engagement->primaryContact?->phone ? ' · '.$engagement->primaryContact->phone : '' }}
                                    @if ($engagement->trashed()) · <span class="text-danger">Archived</span> @endif
                                </span>
                            </a>
                        </td>
                        <td class="whitespace-nowrap text-ink-muted" x-show="shown('type')">{{ $engagement->propertyTypeLabel() }}</td>
                        <td class="min-w-40 text-ink-muted" x-show="shown('location')">{{ $engagement->locationLabel() }}</td>
                        <td class="whitespace-nowrap" x-show="shown('stage')"><x-ui.pill :tone="$engagement->stage->tone()" :dot="false">{{ $engagement->stage->label() }}</x-ui.pill></td>
                        <td class="whitespace-nowrap" x-show="shown('status')"><x-ui.pill :tone="$engagement->status->tone()">{{ $engagement->status->label() }}</x-ui.pill></td>
                        <td class="whitespace-nowrap" x-show="shown('rep')">
                            @if ($engagement->salesRep)
                                <span class="flex items-center gap-2"><x-ui.avatar :user="$engagement->salesRep" size="sm" /><span class="text-ink">{{ $engagement->salesRep->name }}</span></span>
                            @else
                                <span class="text-ink-subtle">Unassigned</span>
                            @endif
                        </td>
                        <td class="tabular whitespace-nowrap text-ink-muted" x-show="shown('first')">{{ $engagement->first_engaged_on->format('j M Y') }}</td>
                        <td class="tabular whitespace-nowrap text-ink-muted" x-show="shown('last')">{{ $engagement->last_engaged_on?->format('j M Y') ?? '—' }}</td>
                        <td class="text-right"><x-ui.button size="sm" variant="secondary" :href="route('registry.show', $engagement)" wire:navigate>View</x-ui.button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state icon="building"
                                :title="$search !== '' ? 'No property matches “'.$search.'”' : 'No properties match these filters'"
                                :description="$search !== '' ? 'Tourlast has no recorded engagement with it. It is safe to approach, and you can start a lead for it.' : 'Clear some filters to see more of the registry.'" />
                            @if ($search !== '' && auth()->user()->role()?->earnsReferrals())
                                <div class="-mt-4 pb-6 text-center"><x-ui.button size="sm" :href="route('leads.index')" wire:navigate icon="plus">Go to my leads</x-ui.button></div>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
