@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $money = fn ($value) => 'KES '.number_format((float) $value);
    $hasFilters = $search !== '' || $salesperson !== '' || $status !== '' || $platform !== '' || $appliesTo !== '' || $activeFrom !== '' || $activeTo !== '';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Influencers" description="Referral codes you give to influencers. A client who books with a code earns the influencer commission on the terms set for that code, for a set number of bookings within its dates.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.influencers.export', $filters->toQuery())">Export Excel</x-ui.button>
            @if ($canCreate)
                <x-ui.button variant="secondary" icon="user" wire:click="newInfluencer">Add influencer</x-ui.button>
                <x-ui.button icon="plus" wire:click="newCode">Generate code</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-5">
        @foreach ([
            ['Active codes', number_format($summary['active']), 'text-ink'],
            ['Bookings via codes this month', number_format($summary['bookings']), 'text-brand-text'],
            ['Revenue via codes this month', $money($summary['revenue']), 'text-ink'],
            ['Commission payable', $money($summary['payable']), $summary['payable'] > 0 ? 'text-warning' : 'text-ink'],
            ['Commission paid this month', $money($summary['paidThisMonth']), 'text-success'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- Search and filters --}}
    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-56 flex-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search influencer, handle or code..." wide aria-label="Search influencer codes" />
            </div>
            @if ($seesAll)
                <select wire:model.live="salesperson" aria-label="Salesperson" class="{{ $selectClass }}">
                    <option value="">All salespeople</option>
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="status" aria-label="Status" class="{{ $selectClass }}">
                <option value="">All statuses</option>
                @foreach (\App\Enums\Travel\InfluencerCodeStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="platform" aria-label="Platform" class="{{ $selectClass }}">
                <option value="">All platforms</option>
                @foreach (\App\Models\Influencer::Platforms as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="appliesTo" aria-label="Applies to" class="{{ $selectClass }}">
                <option value="">Packages and flights</option>
                @foreach (\App\Enums\Travel\InfluencerCodeScope::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-1.5">
                <x-ui.input type="date" wire:model.live="activeFrom" aria-label="Running from" id="inf-active-from" />
                <span class="text-xs text-ink-subtle">to</span>
                <x-ui.input type="date" wire:model.live="activeTo" aria-label="Running to" id="inf-active-to" />
            </div>
        </div>
        @if ($hasFilters)
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-medium text-ink">{{ number_format($codes->total()) }} {{ \Illuminate\Support\Str::plural('code', $codes->total()) }} found</span>
                <button type="button" wire:click="$set('search', ''); $set('salesperson', ''); $set('status', ''); $set('platform', ''); $set('appliesTo', ''); $set('activeFrom', ''); $set('activeTo', '')" class="font-medium text-brand-text hover:underline">Clear all</button>
            </div>
        @endif
    </div>

    {{-- Codes --}}
    <x-ui.table-card :paginator="$codes">
        <table class="w-full min-w-[1320px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Influencer</th>
                    <th class="text-left">Code</th>
                    <th class="text-left">Created by</th>
                    <th class="text-left">Commission terms</th>
                    <th class="text-left">Applies to</th>
                    <th class="text-left">Period</th>
                    <th class="text-right">Booking limit</th>
                    <th class="text-right">Used / left</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Commission</th>
                    <th class="text-left">Status</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($codes as $code)
                    @php
                        $remaining = $code->max_bookings !== null ? max(0, $code->max_bookings - (int) $code->bookings_used) : null;
                        $canManageRow = \App\Support\Travel\InfluencerAccess::canManage(auth()->user(), $code->influencer);
                    @endphp
                    <tr wire:key="code-{{ $code->id }}">
                        <td class="max-w-64 min-w-48">
                            <a href="{{ route('travel.influencers.show', $code->influencer) }}" wire:navigate class="grid leading-tight">
                                <span class="truncate font-semibold text-ink hover:text-brand-text">{{ $code->influencer->name }}</span>
                                <span class="truncate text-xs text-ink-subtle">
                                    {{ $code->influencer->platformSummary() }}
                                    @if ($seesAll) · {{ $code->influencer->owner?->name }} @endif
                                </span>
                            </a>
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5" x-data="{ copied: false }">
                                <span class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-[12.5px] font-semibold text-ink">{{ $code->code }}</span>
                                <button type="button" class="rounded p-1 text-ink-subtle hover:bg-surface-muted hover:text-ink" aria-label="Copy code {{ $code->code }}"
                                    x-on:click="navigator.clipboard?.writeText(@js($code->code)); copied = true; setTimeout(() => copied = false, 1500)">
                                    <x-ui.icon name="copy" class="size-3.5" x-show="! copied" />
                                    <x-ui.icon name="check" class="size-3.5 text-success" x-show="copied" x-cloak />
                                </button>
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $code->creator?->name ?? '—' }}</td>
                        <td class="min-w-44 text-ink">{{ $code->termsLabel() }}</td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $code->applies_to->label() }}</td>
                        <td class="tabular whitespace-nowrap text-ink-muted">{{ $code->starts_on->format('j M Y') }} – {{ $code->ends_on?->format('j M Y') ?? 'open' }}</td>
                        <td class="tabular text-right text-ink-muted">{{ $code->max_bookings ?? 'No limit' }}</td>
                        <td class="tabular text-right whitespace-nowrap">
                            <span class="font-semibold text-ink">{{ (int) $code->bookings_used }}</span>
                            <span class="text-ink-subtle">/ {{ $remaining === null ? '∞' : $remaining.' left' }}</span>
                        </td>
                        <td class="tabular text-right whitespace-nowrap text-ink">{{ $money($code->revenue_generated) }}</td>
                        <td class="tabular text-right whitespace-nowrap">
                            <span class="grid leading-tight">
                                <span class="font-semibold text-ink">{{ $money((float) $code->commission_pending + (float) $code->commission_payable + (float) $code->commission_paid) }}</span>
                                <span class="text-[11.5px] text-ink-subtle">{{ $money($code->commission_pending) }} pending · {{ $money($code->commission_payable) }} payable · {{ $money($code->commission_paid) }} paid</span>
                            </span>
                        </td>
                        <td class="whitespace-nowrap"><x-ui.pill :tone="$code->status->tone()">{{ $code->status->label() }}</x-ui.pill></td>
                        <td class="text-right whitespace-nowrap">
                            <div class="relative inline-flex items-center gap-1" x-data="{ open: false }" x-on:click.outside="open = false">
                                <x-ui.button size="sm" variant="secondary" :href="route('travel.influencers.show', $code->influencer)" wire:navigate>View</x-ui.button>
                                @if ($canManageRow && $code->status !== \App\Enums\Travel\InfluencerCodeStatus::Ended)
                                    <button type="button" x-on:click="open = ! open" class="rounded-md p-1.5 text-ink-subtle hover:bg-surface-muted hover:text-ink" aria-label="More actions for {{ $code->code }}">
                                        <x-ui.icon name="dots" class="size-4" />
                                    </button>
                                    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute top-full right-0 z-30 mt-1 grid w-44 gap-0.5 rounded-lg border border-line bg-surface p-1.5 text-left shadow-overlay">
                                        @if ((int) $code->bookings_used === 0)
                                            <button type="button" wire:click="editCode({{ $code->id }})" x-on:click="open = false" class="rounded px-2 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Edit terms</button>
                                        @endif
                                        @if ($code->status === \App\Enums\Travel\InfluencerCodeStatus::Active)
                                            <button type="button" wire:click="setCodeStatus({{ $code->id }}, 'paused')" x-on:click="open = false" class="rounded px-2 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Pause code</button>
                                        @else
                                            <button type="button" wire:click="setCodeStatus({{ $code->id }}, 'active')" x-on:click="open = false" class="rounded px-2 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Resume code</button>
                                        @endif
                                        <button type="button" wire:click="setCodeStatus({{ $code->id }}, 'ended')" wire:confirm="End code {{ $code->code }}? It can't be used again." x-on:click="open = false" class="rounded px-2 py-1.5 text-left text-[13px] text-danger hover:bg-danger-soft">End code</button>
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12">
                            <x-ui.empty-state icon="megaphone" title="{{ $hasFilters ? 'No codes match these filters' : 'No influencer codes yet' }}" description="{{ $hasFilters ? 'Try clearing a filter.' : 'Add an influencer, then generate a code with its commission terms to share with them.' }}">
                                @if ($canCreate && ! $hasFilters)
                                    <x-ui.button icon="user" wire:click="newInfluencer">Add influencer</x-ui.button>
                                @endif
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    @include('livewire.travel.influencers.partials.influencer-form', ['title' => 'Add influencer', 'action' => 'saveInfluencer', 'model' => 'showInfluencerForm'])

    {{-- Generate / edit code --}}
    <x-ui.modal wire:model="showCodeForm" :title="$editingCodeId ? 'Edit code terms' : 'Generate code'" description="The commission terms belong to the code. Once it earns commission they are fixed." maxWidth="max-w-lg">
        <form wire:submit="saveCode" id="code-form" class="grid gap-3">
            <x-ui.select label="Influencer" wire:model.live="codeForm.influencer_id" id="code-influencer" :disabled="(bool) $editingCodeId">
                <option value="">Choose...</option>
                @foreach ($myInfluencers as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}{{ $option->handle ? ' ('.$option->handle.')' : '' }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Code" wire:model="codeForm.code" id="code-code" class="font-mono uppercase" hint="Letters, numbers and dashes, 4–20 characters. Suggested from the influencer's name; change it if you like." :disabled="$editingLocked" />
            <div class="grid grid-cols-2 gap-3">
                <x-ui.select label="Commission" wire:model.live="codeForm.commission_type" id="code-type">
                    @foreach (\App\Enums\Travel\InfluencerCommissionType::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input :label="($codeForm['commission_type'] ?? '') === 'fixed' ? 'Amount per booking (KES)' : 'Percentage (%)'" type="number" step="0.01" min="0" wire:model="codeForm.commission_value" id="code-value" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.select label="Applies to" wire:model="codeForm.applies_to" id="code-applies">
                    @foreach (\App\Enums\Travel\InfluencerCodeScope::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Bookings that earn" type="number" min="1" wire:model="codeForm.max_bookings" id="code-max" hint="Blank for no limit." />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input label="Starts" type="date" wire:model="codeForm.starts_on" id="code-starts" />
                <x-ui.input label="Ends" type="date" wire:model="codeForm.ends_on" id="code-ends" hint="Blank to run until ended." />
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="code-form">{{ $editingCodeId ? 'Save terms' : 'Create code' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
