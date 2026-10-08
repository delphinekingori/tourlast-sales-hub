@php
    $statusUi = \App\Enums\Travel\PackageStatus::class;
    $canSubmit = $canChange && $working && $working->isEditable();
    $canWithdraw = $canChange && $working && $working->isAwaitingReview();
    $canPublish = $canChange && $live && in_array($package->status, [$statusUi::Approved, $statusUi::Unpublished], true);
    $canUnpublish = $canChange && $package->status === $statusUi::Published;
    $ready = collect($readiness)->every(fn ($item) => $item['ok']);
    $money = fn ($value) => $value === null ? '—' : ($version?->currency ?? 'KES').' '.number_format((float) $value, 2);
    $contractStatus = $package->contract?->effectiveStatus();
@endphp

<div class="grid gap-5">
    <x-ui.page-header :title="$package->name">
        <x-slot:description>
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>{{ $package->reference }} · {{ $package->typeLabel() }} · {{ $package->provider?->name }}</span>
                <x-ui.pill :tone="$package->status->tone()">{{ $package->status->label() }}</x-ui.pill>
                @include('livewire.travel.packages.partials.approval-pill', ['package' => $package])
            </span>
        </x-slot:description>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="copy" wire:click="openDuplicate({{ $package->id }})">Duplicate</x-ui.button>
            @if ($canChange && ! $canWithdraw)
                <x-ui.button variant="secondary" :href="route('travel.packages.edit', $package)" wire:navigate>Edit</x-ui.button>
            @endif
            @if ($canWithdraw)
                <x-ui.button variant="secondary" wire:click="withdrawPackage({{ $package->id }})">Withdraw from review</x-ui.button>
            @endif
            @if ($canChange && $working && $live && $working->isEditable())
                <x-ui.button variant="ghost" wire:click="discardChanges({{ $package->id }})" wire:confirm="Discard the draft changes? The live version stays as it is.">Discard changes</x-ui.button>
            @endif
            @if ($canSubmit)
                <x-ui.button wire:click="submitPackage({{ $package->id }})" :disabled="! $ready">Submit for approval</x-ui.button>
            @endif
            @if ($canReview)
                <x-ui.button :href="route('travel.approvals.index', ['review' => $package->id])" wire:navigate icon="shield">Review</x-ui.button>
            @endif
            @if ($canPublish)
                <x-ui.button wire:click="openPublish({{ $package->id }})">Mark as published</x-ui.button>
            @endif
            @if ($canUnpublish)
                <x-ui.button variant="secondary" wire:click="openUnpublish({{ $package->id }})">Unpublish</x-ui.button>
            @endif
            @if ($canChange)
                <x-ui.button variant="danger-ghost" wire:click="archivePackage({{ $package->id }})" wire:confirm="Archive this package? It will no longer be sold.">Archive</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($live && $working && $working->material_changes)
        <div class="flex flex-wrap items-start gap-2 rounded-lg border border-danger/40 bg-danger-soft/40 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="alert" class="size-4 text-danger" />
            <div class="grid gap-0.5">
                <p class="font-semibold">Approval required · {{ $working->label() }} ({{ $working->status->label() }})</p>
                <p>Changed: {{ collect(array_keys($working->material_changes))->map(fn ($f) => \App\Support\Travel\PackageContent::label($f))->implode(', ') }}. Customers still see {{ $live->label() }} until the change is approved.
                    <button type="button" wire:click="$set('view', '{{ $view === 'live' ? '' : 'live' }}')" class="font-medium text-brand-text hover:underline">{{ $view === 'live' ? 'Show the new version' : 'Show the live version' }}</button></p>
            </div>
        </div>
    @endif

    @if ($working && in_array($working->status, [\App\Enums\Travel\PackageVersionStatus::Rejected, \App\Enums\Travel\PackageVersionStatus::ChangesRequested], true))
        @php
            $lastDecision = $working->approvals()->latest('decided_at')->with('user:id,name')->first();
        @endphp
        @if ($lastDecision)
            <div class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink">
                <x-ui.icon name="alert" class="size-4 text-warning" />
                <p><span class="font-semibold">{{ $lastDecision->decision->label() }}</span> by {{ $lastDecision->user->name }} ({{ $lastDecision->level->label() }}, {{ $lastDecision->decided_at->format('j M Y') }}): {{ $lastDecision->reason }}</p>
            </div>
        @endif
    @endif

    {{-- Key facts --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Destination', $version?->destination ?? $package->destination],
            ['Duration', $version?->duration_label ?: (($version?->days ?? 1).' days')],
            ['Adult price', $money($version?->adult_price)],
            ['Capacity', $version?->default_capacity ?: ($version?->max_travelers ?: '—')],
            ['Created by', $package->creator?->name.' · Travel Sales · '.$package->created_at->format('j M Y')],
            ['Updated', ($package->updater?->name ? $package->updater->name.' · ' : '').$package->updated_at->format('j M Y H:i')],
        ] as [$label, $value])
            <div class="grid min-w-0 gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="truncate text-[13px] font-semibold text-ink" title="{{ $value }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="flex flex-wrap gap-1 border-b border-line" role="tablist">
        @foreach (\App\Livewire\Travel\Packages\Show::Tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class(['-mb-px border-b-2 px-3 py-2 text-[13px] font-medium', 'border-brand text-ink' => $tab === $key, 'border-transparent text-ink-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div wire:loading.delay.class="opacity-60">
        @if ($tab === 'overview')
            <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <x-ui.card :title="'Overview · '.$version?->label()" :description="$version?->short_description">
                    <div class="grid gap-4 text-[13px] text-ink">
                        @if ($version?->description)<p class="whitespace-pre-line">{{ $version->description }}</p>@endif
                        @if ($version?->overview)<p class="whitespace-pre-line text-ink-muted">{{ $version->overview }}</p>@endif
                        <div class="grid gap-4 md:grid-cols-3">
                            @foreach (['highlights' => 'Highlights', 'inclusions' => 'Inclusions', 'exclusions' => 'Exclusions'] as $field => $label)
                                <div>
                                    <p class="mb-1 text-xs font-semibold tracking-wide text-ink-subtle uppercase">{{ $label }}</p>
                                    <ul class="list-disc space-y-0.5 pl-4">
                                        @forelse ($version?->{$field} ?? [] as $item)
                                            <li>{{ $item }}</li>
                                        @empty
                                            <li class="list-none text-ink-subtle">None listed</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach ([
                                'Cancellation policy' => $version?->cancellation_policy, 'Refund policy' => $version?->refund_policy,
                                'Requirements' => $version?->requirements, 'Terms & conditions' => $version?->terms,
                                'Meeting point' => $version?->meeting_point, 'Pickup' => $version?->pickup_info, 'Drop-off' => $version?->dropoff_info,
                                'What to bring' => implode(', ', $version?->what_to_bring ?? []),
                            ] as $label => $value)
                                @if (filled($value))
                                    <div>
                                        <p class="text-xs font-semibold tracking-wide text-ink-subtle uppercase">{{ $label }}</p>
                                        <p class="whitespace-pre-line">{{ $value }}</p>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </x-ui.card>
                <div class="grid gap-4">
                    @if ($working)
                        <x-ui.card title="Readiness" :description="$working->label().' · '.$working->status->label()">
                            <ul class="grid gap-1 text-[13px]">
                                @foreach ($readiness as $item)
                                    <li class="flex items-center gap-2">
                                        <span @class(['grid size-4 place-items-center rounded-full', 'bg-success-soft text-success' => $item['ok'], 'bg-danger-soft text-danger' => ! $item['ok']])><x-ui.icon :name="$item['ok'] ? 'check' : 'x'" class="size-3" /></span>
                                        <span @class(['text-ink' => $item['ok'], 'text-ink-muted' => ! $item['ok']])>{{ $item['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-ui.card>
                    @endif
                    @if ($live)
                        <x-ui.card title="Publishing" :description="$package->status === $statusUi::Published ? 'On sale on '.$package->published_channel : 'Approved packages can be published on any channel.'">
                            <div class="grid gap-2 text-[13px]">
                                @if ($package->published_at)
                                    <p class="text-ink">Published {{ $package->published_at->format('j M Y') }} by {{ $package->publisher?->name }}@if ($package->published_url) · <a href="{{ $package->published_url }}" target="_blank" rel="noopener" class="text-brand-text hover:underline">open</a>@endif</p>
                                @endif
                                @if ($package->contract_override_reason)
                                    <p class="text-warning">Contract rule overridden: {{ $package->contract_override_reason }}</p>
                                @endif
                                @if ($publishGate !== [])
                                    <p class="font-semibold text-danger">Cannot publish yet. Missing:</p>
                                    <ul class="list-disc pl-5 text-ink">
                                        @foreach ($publishGate as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-success">Provider, contract, prices and terms are in order.</p>
                                @endif
                            </div>
                        </x-ui.card>
                    @endif
                    @if ($media->isNotEmpty())
                        @php
                            $primary = $media->firstWhere('pivot.is_primary', true) ?? $media->first();
                        @endphp
                        @if ($primary->isImage())
                            <img src="{{ $primary->url() }}" alt="{{ $primary->alt_text ?? $primary->title }}" class="aspect-[4/3] w-full rounded-xl border border-line object-cover">
                        @endif
                    @endif
                </div>
            </div>
        @elseif ($tab === 'itinerary')
            <x-ui.card :title="'Itinerary · '.$version?->label()" :description="($version?->days ?? 0).' days, '.($version?->nights ?? 0).' nights'">
                <ol class="grid gap-3">
                    @forelse ($version?->itineraryDays ?? [] as $day)
                        <li class="grid grid-cols-[56px_1fr] gap-3 border-b border-line pb-3 last:border-b-0 last:pb-0">
                            <span class="text-xs font-bold tracking-wide text-brand-text uppercase">Day {{ $day->day_number }}</span>
                            <div class="grid gap-1 text-[13px]">
                                <p class="font-semibold text-ink">{{ $day->title }}</p>
                                @if ($day->description)<p class="whitespace-pre-line text-ink-muted">{{ $day->description }}</p>@endif
                                <p class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-ink-subtle">
                                    @if ($day->activities)<span>Activities: {{ $day->activities }}</span>@endif
                                    @if ($day->meals)<span>Meals: {{ collect($day->meals)->map(fn ($m) => \App\Models\PackageItineraryDay::Meals[$m] ?? $m)->implode(', ') }}</span>@endif
                                    @if ($day->accommodation)<span>Stay: {{ $day->accommodation }}</span>@endif
                                    @if ($day->transport)<span>Transport: {{ $day->transport }}</span>@endif
                                </p>
                                @if ($day->notes)<p class="text-xs text-ink-muted">{{ $day->notes }}</p>@endif
                            </div>
                        </li>
                    @empty
                        <x-ui.empty-state icon="calendar" title="No itinerary yet" />
                    @endforelse
                </ol>
            </x-ui.card>
        @elseif ($tab === 'pricing')
            <x-ui.card :title="'Pricing · '.$version?->label()">
                <dl class="grid gap-x-6 gap-y-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['Adult' => $version?->adult_price, 'Child' => $version?->child_price, 'Infant' => $version?->infant_price, 'Group (per person)' => $version?->group_price, 'Single supplement' => $version?->single_supplement, 'Discount (per person)' => $version?->discount_amount] as $label => $value)
                        <div><dt class="text-xs text-ink-subtle">{{ $label }}</dt><dd class="tabular font-semibold text-ink">{{ $money($value) }}</dd></div>
                    @endforeach
                    @if ($version?->group_min_size)
                        <div><dt class="text-xs text-ink-subtle">Group price from</dt><dd class="font-semibold text-ink">{{ $version->group_min_size }} people</dd></div>
                    @endif
                </dl>
                @if ($showFinancials)
                    <dl class="mt-4 grid gap-x-6 gap-y-3 border-t border-line pt-4 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
                        <p class="text-xs font-semibold tracking-wide text-ink-subtle uppercase sm:col-span-2 lg:col-span-4">Internal · finance and owner only</p>
                        @foreach (['Original provider price' => $version?->provider_price, 'Net provider price' => $version?->net_provider_price, 'Commission' => $version?->commission_amount, 'Margin per adult' => $version?->margin()] as $label => $value)
                            <div><dt class="text-xs text-ink-subtle">{{ $label }}</dt><dd class="tabular font-semibold text-ink">{{ $money($value) }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
        @elseif ($tab === 'availability')
            <x-ui.card title="Departures" description="Dates, slots sold and available. Managed under Inventory." :padding="false">
                <x-slot:actions>
                    @if ($departuresUrl)
                        <x-ui.button size="sm" variant="secondary" :href="$departuresUrl" wire:navigate>Manage departures</x-ui.button>
                    @endif
                </x-slot:actions>
                @if ($departures->isEmpty())
                    <x-ui.empty-state icon="cube" title="No departures yet" description="Add dates and capacity under Inventory once the package is approved." />
                @else
                    <x-ui.table-card :sticky="false" class="rounded-none border-0 shadow-none">
                        <table class="w-full text-left text-[13px]">
                            <thead><tr><th>Date</th><th class="text-right">Capacity</th><th class="text-right">Sold</th><th class="text-right">Reserved</th><th class="text-right">Available</th><th>Status</th><th>Trip</th><th>Driver</th><th>Guide</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($departures as $departure)
                                    @php
                                        $availability = $departure->availabilityStatus();
                                    @endphp
                                    <tr wire:key="dep-{{ $departure->id }}">
                                        <td class="whitespace-nowrap">{{ $departure->starts_on->format('D j M Y') }}{{ $departure->start_time ? ' · '.substr($departure->start_time, 0, 5) : '' }}</td>
                                        <td class="tabular text-right">{{ $departure->capacity }}</td>
                                        <td class="tabular text-right">{{ $departure->soldSlots() }}</td>
                                        <td class="tabular text-right">{{ $departure->reservedSlots() }}</td>
                                        <td class="tabular text-right font-semibold">{{ $departure->availableSlots() }}</td>
                                        <td><x-ui.pill :tone="$availability->tone()">{{ $availability->label() }}</x-ui.pill></td>
                                        <td class="text-ink-muted">{{ $departure->trip_status->label() }}</td>
                                        <td class="text-ink-muted">{{ $departure->driver?->name ?? $package->driver?->name ?? '—' }}</td>
                                        <td class="text-ink-muted">{{ $departure->guide?->name ?? $package->guide?->name ?? ($package->guide_required ? 'Needed' : 'Not required') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.table-card>
                @endif
            </x-ui.card>
        @elseif ($tab === 'provider')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card title="Provider">
                    <dl class="grid gap-2 text-[13px]">
                        <div><dt class="text-xs text-ink-subtle">Name</dt><dd class="font-semibold text-ink">@if ($providerUrl)<a href="{{ $providerUrl }}" wire:navigate class="hover:underline">{{ $package->provider?->name }}</a>@else{{ $package->provider?->name }}@endif</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Status</dt><dd><x-ui.pill :tone="$package->provider?->status?->tone() ?? 'neutral'">{{ $package->provider?->status?->label() }}</x-ui.pill></dd></div>
                        <div><dt class="text-xs text-ink-subtle">Contact</dt><dd class="text-ink">{{ collect([$package->provider?->primary_contact_name, $package->provider?->phone, $package->provider?->email])->filter()->implode(' · ') ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Location</dt><dd class="text-ink">{{ collect([$package->provider?->city, $package->provider?->region, $package->provider?->country])->filter()->implode(', ') }}</dd></div>
                    </dl>
                </x-ui.card>
                <x-ui.card title="Contract">
                    @if ($package->contract)
                        <dl class="grid gap-2 text-[13px]">
                            <div><dt class="text-xs text-ink-subtle">Contract</dt><dd class="font-semibold text-ink">{{ $package->contract->contract_number }} · {{ $package->contract->contract_type }}</dd></div>
                            <div><dt class="text-xs text-ink-subtle">Status</dt><dd><x-ui.pill :tone="$contractStatus->tone()">{{ $contractStatus->label() }}</x-ui.pill></dd></div>
                            <div><dt class="text-xs text-ink-subtle">Period</dt><dd class="text-ink">{{ $package->contract->starts_on->format('j M Y') }} – {{ $package->contract->ends_on?->format('j M Y') ?? 'open-ended' }}</dd></div>
                            @if ($showFinancials)
                                <div><dt class="text-xs text-ink-subtle">Commission</dt><dd class="text-ink">{{ $package->contract->commission_model->label() }}{{ $package->contract->commission_rate ? ' · '.$package->contract->commission_rate.'%' : '' }}{{ $package->contract->fixed_commission ? ' · '.$package->contract->currency.' '.number_format((float) $package->contract->fixed_commission) : '' }}</dd></div>
                            @endif
                            <div><dt class="text-xs text-ink-subtle">Cancellation terms</dt><dd class="whitespace-pre-line text-ink">{{ $package->contract->cancellation_terms ?: '—' }}</dd></div>
                        </dl>
                    @else
                        <x-ui.empty-state icon="document" title="No contract linked" description="A package cannot be submitted or published without an active provider contract." />
                    @endif
                </x-ui.card>
            </div>
        @elseif ($tab === 'team')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card title="Default driver">
                    @if ($package->driver)
                        <p class="text-[13px] font-semibold text-ink">{{ $package->driver->name }}</p>
                        <p class="text-[13px] text-ink-muted">{{ collect([$package->driver->phone, $package->driver->vehicle, $package->driver->vehicle_registration])->filter()->implode(' · ') }}</p>
                    @else
                        <p class="text-[13px] text-ink-subtle">No default driver. Departures or bookings can still name one.</p>
                    @endif
                </x-ui.card>
                <x-ui.card title="Default guide">
                    @if ($package->guide)
                        <p class="text-[13px] font-semibold text-ink">{{ $package->guide->name }}</p>
                        <p class="text-[13px] text-ink-muted">{{ collect([$package->guide->phone, implode(', ', $package->guide->languages ?? []), $package->guide->specialization])->filter()->implode(' · ') }}</p>
                    @else
                        <p class="text-[13px] text-ink-subtle">{{ $package->guide_required ? 'A guide is required but none is assigned by default.' : 'No guide required.' }}</p>
                    @endif
                </x-ui.card>
            </div>
        @elseif ($tab === 'media')
            <x-ui.card title="Media" :description="$media->count().' images from the Media Gallery'">
                @if ($media->isEmpty())
                    <x-ui.empty-state icon="photo" title="No images yet" />
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($media as $asset)
                            <figure class="grid gap-1">
                                <div class="relative aspect-[4/3] overflow-hidden rounded-lg border border-line bg-surface-muted">
                                    @if ($asset->isImage())<img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?? $asset->title }}" class="size-full object-cover" loading="lazy">@endif
                                    @if ($asset->pivot->is_primary)<span class="absolute top-1.5 left-1.5"><x-ui.pill tone="brand">Primary</x-ui.pill></span>@endif
                                    @if (! $asset->isUsable())<span class="absolute right-1.5 bottom-1.5"><x-ui.pill tone="danger">Permission revoked</x-ui.pill></span>@endif
                                </div>
                                <figcaption class="truncate text-xs text-ink-muted">{{ $asset->title }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @elseif ($tab === 'bookings')
            <div class="grid gap-3">
                <x-ui.segmented wire:model.live="bookingStatus" :options="['' => 'All', 'confirmed' => 'Confirmed', 'pending' => 'Pending', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'completed' => 'Completed']" />
                <x-ui.table-card>
                    <table class="w-full min-w-[1100px] text-left text-[13px]">
                        <thead><tr><th>Booking</th><th>Client</th><th>Travel date</th><th class="text-right">Travelers</th><th class="text-right">Amount</th><th>Payment</th><th>Status</th><th>Driver</th><th>Guide</th><th>Booked by</th><th>Created</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($bookings as $booking)
                                <tr wire:key="bk-{{ $booking->id }}">
                                    <td class="font-semibold">@if ($bookingUrl)<a href="{{ route('travel.bookings.show', $booking) }}" wire:navigate class="hover:underline">{{ $booking->reference }}</a>@else{{ $booking->reference }}@endif</td>
                                    <td>{{ $booking->client?->name }}</td>
                                    <td class="whitespace-nowrap">{{ $booking->departure?->starts_on?->format('j M Y') }}</td>
                                    <td class="tabular text-right">{{ $booking->travelers }} <span class="text-xs text-ink-subtle">({{ $booking->adults }}A{{ $booking->children ? ' '.$booking->children.'C' : '' }}{{ $booking->infants ? ' '.$booking->infants.'I' : '' }})</span></td>
                                    <td class="tabular text-right">{{ $booking->currency }} {{ number_format((float) $booking->amount_total) }}</td>
                                    <td><x-ui.pill :tone="$booking->payment_status->tone()">{{ $booking->payment_status->label() }}</x-ui.pill></td>
                                    <td><x-ui.pill :tone="$booking->status->tone()">{{ $booking->status->label() }}</x-ui.pill></td>
                                    <td class="text-ink-muted">{{ $booking->effectiveDriver()?->name ?? '—' }}</td>
                                    <td class="text-ink-muted">{{ $booking->effectiveGuide()?->name ?? '—' }}</td>
                                    <td class="text-ink-muted">{{ $booking->salesperson?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap text-ink-subtle">{{ $booking->created_at->format('j M Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11"><x-ui.empty-state icon="ticket" title="No bookings" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.table-card>
            </div>
        @elseif ($tab === 'approvals')
            <x-ui.card title="Approval history" description="Every version and every review decision. Decisions cannot be changed." :padding="false">
                <ol class="divide-y divide-line">
                    @foreach ($versions as $item)
                        <li wire:key="ver-{{ $item->id }}" class="grid gap-2 px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                                <span class="font-bold text-ink">{{ $item->label() }}</span>
                                <x-ui.pill :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.pill>
                                @if ($item->id === $package->live_version_id)<x-ui.pill tone="success" :dot="false">Live</x-ui.pill>@endif
                                <span class="text-ink-muted">Created by {{ $item->creator?->name }} · {{ $item->created_at->format('j M Y H:i') }}</span>
                                @if ($item->submitted_at)<span class="text-ink-muted">· submitted {{ $item->submitted_at->format('j M Y H:i') }}{{ $item->submitter ? ' by '.$item->submitter->name : '' }}</span>@endif
                                @if ($item->approved_at)<span class="text-ink-muted">· approved {{ $item->approved_at->format('j M Y H:i') }}</span>@endif
                            </div>
                            @if ($item->change_note)<p class="text-xs text-ink-muted">{{ $item->change_note }}</p>@endif
                            @if ($item->material_changes)
                                <table class="w-full max-w-3xl text-left text-xs">
                                    <thead class="text-ink-subtle"><tr><th class="py-1 pr-3 font-medium">Changed</th><th class="py-1 pr-3 font-medium">Before</th><th class="py-1 font-medium">After</th></tr></thead>
                                    <tbody>
                                        @foreach ($item->material_changes as $field => [$old, $new])
                                            <tr class="border-t border-line"><td class="py-1 pr-3 font-medium text-ink">{{ \App\Support\Travel\PackageContent::label($field) }}</td><td class="py-1 pr-3 text-ink-muted">{{ is_scalar($old) || $old === null ? ($old ?? '—') : json_encode($old) }}</td><td class="py-1 text-ink">{{ is_scalar($new) || $new === null ? ($new ?? '—') : json_encode($new) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            @if ($item->approvals->isNotEmpty())
                                <ul class="grid gap-1 text-[13px]">
                                    @foreach ($item->approvals as $approval)
                                        <li class="flex flex-wrap items-center gap-2">
                                            <x-ui.pill :tone="$approval->decision->tone()">{{ $approval->decision->label() }}</x-ui.pill>
                                            <span class="text-ink">{{ $approval->level->label() }} · {{ $approval->user->name }} · {{ $approval->decided_at->format('j M Y H:i') }}</span>
                                            @if ($approval->reason)<span class="text-ink-muted">“{{ $approval->reason }}”</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        @else
            <x-ui.card title="Activity" description="Audit log for this package and its versions." :padding="false">
                <ol class="divide-y divide-line">
                    @forelse ($activity as $event)
                        <li wire:key="ev-{{ $event->id }}" class="grid gap-0.5 px-4 py-2.5 text-[13px]">
                            <p class="text-ink">{{ $event->summary }}</p>
                            <p class="text-xs text-ink-subtle">{{ $event->user?->name ?? 'System' }} · {{ $event->created_at->format('j M Y H:i') }} · {{ $event->action }}</p>
                            @if ($event->changes && $event->action !== 'package.media_changed')
                                <p class="text-xs text-ink-muted">
                                    @foreach (array_slice($event->changes, 0, 8, true) as $field => $pair)
                                        <span class="mr-3">{{ \App\Support\Travel\PackageContent::label($field) }}: {{ is_array($pair[0] ?? null) ? implode('; ', $pair[0]) : ($pair[0] ?? '—') }} → {{ is_array($pair[1] ?? null) ? implode('; ', $pair[1]) : ($pair[1] ?? '—') }}</span>
                                    @endforeach
                                </p>
                            @endif
                        </li>
                    @empty
                        <li><x-ui.empty-state icon="activity" title="No activity yet" /></li>
                    @endforelse
                </ol>
            </x-ui.card>
        @endif
    </div>

    @include('livewire.travel.packages.partials.action-modals')
</div>
