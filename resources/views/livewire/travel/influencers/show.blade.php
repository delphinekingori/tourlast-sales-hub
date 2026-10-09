@php
    $money = fn ($value) => 'KES '.number_format((float) $value, 2);
    $total = fn (string $status) => (float) ($totals[$status] ?? 0);
@endphp

<div class="grid gap-5">
    <x-ui.page-header :title="$influencer->name" :description="$influencer->platformSummary('Influencer').' · managed by '.($influencer->owner?->name ?? '—')">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-right" :href="route('travel.influencers.index')" wire:navigate>All codes</x-ui.button>
            @if ($canManage)
                <x-ui.button variant="secondary" icon="cog" wire:click="edit">Edit influencer</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-4">
        @foreach ([
            ['Pending (booking not fully paid)', $money($total('pending')), 'text-ink'],
            ['Payable', $money($total('payable')), $total('payable') > 0 ? 'text-warning' : 'text-ink'],
            ['Paid', $money($total('paid')), 'text-success'],
            ['Codes', $codes->count(), 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,2.4fr)]">
        <x-ui.card title="Profile">
            <dl class="grid gap-3 text-[13px]">
                <div class="grid gap-0.5">
                    <dt class="text-xs text-ink-subtle">{{ \Illuminate\Support\Str::plural('Platform', $influencer->platforms->count()) }}</dt>
                    <dd class="grid gap-1 text-ink">
                        @forelse ($influencer->platforms as $platform)
                            <span class="flex flex-wrap items-baseline gap-x-1.5">
                                <span class="font-medium">{{ $platform->label() }}</span>
                                @if ($platform->url)
                                    <a href="{{ $platform->url }}" target="_blank" rel="noopener noreferrer" class="text-brand-text hover:underline">{{ $platform->handle ?: 'Profile' }}</a>
                                @elseif ($platform->handle)
                                    <span class="text-ink-muted">{{ $platform->handle }}</span>
                                @endif
                            </span>
                        @empty
                            <span class="text-ink-muted">None added</span>
                        @endforelse
                    </dd>
                </div>
                @foreach ([
                    ['Status', $influencer->is_active ? 'Active' : 'Inactive'],
                    ['Phone', $influencer->phone ?: '—'],
                    ['Email', $influencer->email ?: '—'],
                    ['Managed by', $influencer->owner?->name ?? '—'],
                    ['Added', $influencer->created_at->format('j M Y')],
                ] as [$label, $value])
                    <div class="grid gap-0.5">
                        <dt class="text-xs text-ink-subtle">{{ $label }}</dt>
                        <dd class="text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
                <div class="grid gap-0.5 border-t border-line pt-3">
                    <dt class="flex items-center gap-1.5 text-xs text-ink-subtle"><x-ui.icon name="lock" class="size-3.5" /> Payout details</dt>
                    @if ($seesPayout)
                        <dd class="text-ink">
                            {{ ['mpesa' => 'M-Pesa', 'bank' => 'Bank'][$influencer->payout_method] ?? 'Not set' }}{{ $influencer->payout_details ? ': '.$influencer->payout_details : '' }}
                        </dd>
                    @else
                        <dd class="text-ink-subtle">Hidden</dd>
                    @endif
                </div>
                @if ($influencer->notes)
                    <div class="grid gap-0.5 border-t border-line pt-3">
                        <dt class="text-xs text-ink-subtle">Notes</dt>
                        <dd class="whitespace-pre-line text-ink">{{ $influencer->notes }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Codes" description="Each code carries its own commission terms." :padding="false">
            @if ($codes->isEmpty())
                <x-ui.empty-state icon="megaphone" title="No codes yet" description="Generate a code from the Influencers page." />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-sidebar text-left text-[11px] tracking-[0.06em] text-sidebar-ink uppercase">
                            <tr>
                                <th class="px-4 py-2 font-medium">Code</th>
                                <th class="px-4 py-2 font-medium">Terms</th>
                                <th class="px-4 py-2 font-medium">Period</th>
                                <th class="px-4 py-2 text-right font-medium">Used</th>
                                <th class="px-4 py-2 text-right font-medium">Revenue</th>
                                <th class="px-4 py-2 text-right font-medium">Commission</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($codes as $code)
                                <tr wire:key="show-code-{{ $code->id }}">
                                    <td class="px-4 py-2.5 font-mono text-[12.5px] font-semibold text-ink">{{ $code->code }}</td>
                                    <td class="px-4 py-2.5 text-ink">{{ $code->termsLabel() }}<span class="block text-xs text-ink-subtle">{{ $code->applies_to->label() }}</span></td>
                                    <td class="tabular px-4 py-2.5 whitespace-nowrap text-ink-muted">{{ $code->starts_on->format('j M Y') }} – {{ $code->ends_on?->format('j M Y') ?? 'open' }}</td>
                                    <td class="tabular px-4 py-2.5 text-right text-ink">{{ (int) $code->bookings_used }}{{ $code->max_bookings ? ' / '.$code->max_bookings : '' }}</td>
                                    <td class="tabular px-4 py-2.5 text-right text-ink">{{ $money($code->revenue_generated) }}</td>
                                    <td class="tabular px-4 py-2.5 text-right text-ink">{{ $money($code->commission_earned) }}</td>
                                    <td class="px-4 py-2.5"><x-ui.pill :tone="$code->status->tone()">{{ $code->status->label() }}</x-ui.pill></td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @if ($canManage && $code->status !== \App\Enums\Travel\InfluencerCodeStatus::Ended)
                                            @if ($code->status === \App\Enums\Travel\InfluencerCodeStatus::Active)
                                                <x-ui.button size="sm" variant="ghost" wire:click="setCodeStatus({{ $code->id }}, 'paused')">Pause</x-ui.button>
                                            @else
                                                <x-ui.button size="sm" variant="ghost" wire:click="setCodeStatus({{ $code->id }}, 'active')">Resume</x-ui.button>
                                            @endif
                                            <x-ui.button size="sm" variant="danger-ghost" wire:click="setCodeStatus({{ $code->id }}, 'ended')" wire:confirm="End code {{ $code->code }}? It can't be used again.">End</x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Commission ledger --}}
    <x-ui.card title="Commission" description="One line per booking made with this influencer's codes. Lines are never deleted." :padding="false">
        <x-slot:actions>
            <x-ui.segmented wire:model.live="ledger" :options="['' => 'All', 'pending' => 'Pending', 'payable' => 'Payable', 'paid' => 'Paid', 'cancelled' => 'Cancelled']" />
            @if ($canPay)
                <x-ui.button size="sm" icon="check" wire:click="openPay">Mark selected paid</x-ui.button>
            @endif
        </x-slot:actions>

        @error('selected')
            <p class="border-b border-line bg-danger-soft/40 px-4 py-2 text-[13px] text-danger">{{ $message }}</p>
        @enderror

        <div class="overflow-x-auto" wire:loading.delay.class="opacity-60">
            <table class="w-full min-w-[980px] text-sm">
                <thead class="bg-sidebar text-left text-[11px] tracking-[0.06em] text-sidebar-ink uppercase">
                    <tr>
                        @if ($canPay)<th class="w-8 px-4 py-2"><span class="sr-only">Select</span></th>@endif
                        <th class="px-4 py-2 font-medium">Booking</th>
                        <th class="px-4 py-2 font-medium">Client / customer</th>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 text-right font-medium">Booking amount</th>
                        <th class="px-4 py-2 text-right font-medium">Commission</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Earned</th>
                        <th class="px-4 py-2 font-medium">Paid</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($lines as $line)
                        @php
                            $about = \App\Livewire\Travel\Influencers\Show::describe($line);
                        @endphp
                        <tr wire:key="line-{{ $line->id }}" class="hover:bg-surface-muted/50">
                            @if ($canPay)
                                <td class="px-4 py-2.5">
                                    @if ($line->status === \App\Enums\Travel\CommissionEntryStatus::Payable)
                                        <input type="checkbox" value="{{ $line->id }}" wire:model="selected" class="size-4 accent-[var(--tl-brand)]" aria-label="Select line {{ $about['reference'] }}">
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <span class="grid leading-tight">
                                    @if ($about['url'])
                                        <a href="{{ $about['url'] }}" wire:navigate class="font-semibold text-ink hover:text-brand-text">{{ $about['reference'] }}</a>
                                    @else
                                        <span class="font-semibold text-ink">{{ $about['reference'] }}</span>
                                    @endif
                                    <span class="text-xs text-ink-subtle">{{ $about['kind'] }}</span>
                                </span>
                            </td>
                            <td class="max-w-72 truncate px-4 py-2.5 text-ink-muted">{{ $about['client'] ?: '—' }}</td>
                            <td class="px-4 py-2.5 font-mono text-[12.5px] text-ink">{{ $line->code?->code }}</td>
                            <td class="tabular px-4 py-2.5 text-right text-ink">{{ $money($line->booking_amount) }}</td>
                            <td class="tabular px-4 py-2.5 text-right font-semibold text-ink">{{ $money($line->commission_amount) }}</td>
                            <td class="px-4 py-2.5"><x-ui.pill :tone="$line->status->tone()">{{ $line->status->label() }}</x-ui.pill></td>
                            <td class="tabular px-4 py-2.5 whitespace-nowrap text-ink-muted">{{ $line->earned_at->format('j M Y') }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-ink-muted">
                                @if ($line->paid_at)
                                    <span class="grid leading-tight">
                                        <span class="tabular">{{ $line->paid_at->format('j M Y') }}</span>
                                        <span class="text-xs text-ink-subtle">{{ $line->payer?->name }} · {{ $line->payment_reference }}</span>
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-ui.empty-state icon="wallet" title="No commission yet" description="Commission appears here when a client books with one of this influencer's codes." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($lines->hasPages())
            <div class="border-t border-line px-4 py-2.5">{{ $lines->links() }}</div>
        @endif
    </x-ui.card>

    @include('livewire.travel.influencers.partials.influencer-form', ['title' => 'Edit influencer', 'action' => 'save', 'model' => 'showEdit'])

    <x-ui.modal wire:model="showPay" title="Mark commission paid" description="Record the M-Pesa or bank reference for the payment to the influencer.">
        <form wire:submit="markPaid" id="pay-form" class="grid gap-3">
            <p class="text-[13px] text-ink-muted">{{ count($selected) }} {{ \Illuminate\Support\Str::plural('line', count($selected)) }} selected.</p>
            <x-ui.input label="Payment reference" wire:model="payReference" id="pay-reference" placeholder="e.g. SJK3H7Q2LP" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="pay-form">Mark paid</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
