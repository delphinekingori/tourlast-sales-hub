<div class="grid gap-5">
    <x-ui.page-header title="Inventory" description="Every package departure with its slots: sold (confirmed), reserved (pending, still on hold) and available. The system never sells more slots than a departure has unless a Sales Admin allows overbooking.">
        <x-slot:actions>
            @if ($manageablePackages->isNotEmpty())
                <x-ui.button icon="plus" wire:click="create">Add departure</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Upcoming departures', number_format($summary['upcoming']), 'text-ink'],
            ['Total slots', number_format($summary['capacity']), 'text-ink'],
            ['Slots sold', number_format($summary['sold']), 'text-success'],
            ['Reserved (on hold)', number_format($summary['reserved']), 'text-brand-text'],
            ['Nearly full', number_format($summary['nearlyFull']), $summary['nearlyFull'] ? 'text-warning' : 'text-ink'],
            ['Full', number_format($summary['full']), $summary['full'] ? 'text-danger' : 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <div class="grid gap-1 self-end">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search package or provider" wide />
            </div>
            <x-ui.select label="Package" wire:model.live="package" id="inv-package">
                <option value="">Any</option>
                @foreach ($packages as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Provider" wire:model.live="provider" id="inv-provider">
                <option value="">Any</option>
                @foreach ($providers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Availability" wire:model.live="status" id="inv-status">
                <option value="">Any</option>
                <option value="low">Low availability (nearly full or full)</option>
                @foreach (\App\Enums\Travel\DepartureStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
            <x-ui.input label="Travel date from" type="date" wire:model.live="from" id="inv-from" />
            <x-ui.input label="Travel date to" type="date" wire:model.live="to" id="inv-to" />
        </div>
        <div class="flex flex-wrap items-center gap-4 text-[13px]">
            <label class="flex items-center gap-2 text-ink-muted">
                <input type="checkbox" wire:model.live="past" class="size-4 accent-[var(--tl-brand)]"> Include past departures
            </label>
            @if ($search !== '' || $package !== '' || $provider !== '' || $status !== '' || $from !== '' || $to !== '' || $past)
                <span class="font-medium text-ink">{{ number_format($departures->total()) }} {{ \Illuminate\Support\Str::plural('departure', $departures->total()) }}</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">Clear all</button>
            @endif
        </div>
    </div>

    <x-ui.table-card :paginator="$departures">
        <table class="w-full min-w-[1280px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th>Package</th>
                    <th>Provider</th>
                    <th>Travel date</th>
                    <th class="text-right">Total slots</th>
                    <th class="text-right">Sold</th>
                    <th class="text-right">Reserved</th>
                    <th class="text-right">Available</th>
                    <th class="text-right">Waitlist</th>
                    <th class="text-right">Bookings</th>
                    <th>Status</th>
                    <th>Trip</th>
                    <th>Created by</th>
                    <th>Approval</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($departures as $departure)
                    @php
                        $availability = $departure->availabilityStatus();
                        $available = $departure->availableSlots();
                        $canChange = $managesAll || $departure->package->owner_id === $userId;
                    @endphp
                    <tr wire:key="dep-{{ $departure->id }}">
                        <td>
                            <span class="grid leading-tight">
                                <span class="font-medium text-ink">{{ $departure->package->name }}</span>
                                <span class="text-xs text-ink-subtle">{{ $departure->package->reference }}</span>
                            </span>
                        </td>
                        <td class="text-ink-muted">{{ $departure->package->provider?->name }}</td>
                        <td class="whitespace-nowrap">
                            <span class="grid leading-tight">
                                <span class="text-ink">{{ $departure->dateLabel() }}</span>
                                @if ($departure->start_time)<span class="text-xs text-ink-subtle">{{ substr((string) $departure->start_time, 0, 5) }}</span>@endif
                            </span>
                        </td>
                        <td class="tabular text-right">{{ $departure->capacity }}</td>
                        <td class="tabular text-right font-medium text-ink">{{ $departure->soldSlots() }}</td>
                        <td class="tabular text-right text-ink-muted">{{ $departure->reservedSlots() }}</td>
                        <td class="tabular text-right">
                            <span @class(['font-semibold', 'text-danger' => $available === 0, 'text-warning' => $available > 0 && $availability === \App\Enums\Travel\DepartureStatus::NearlyFull, 'text-ink' => $availability === \App\Enums\Travel\DepartureStatus::Open])>{{ $available }}</span>
                        </td>
                        <td class="tabular text-right text-ink-muted">{{ $departure->waitlist_count }}</td>
                        <td class="tabular text-right">{{ $departure->live_bookings_count }}</td>
                        <td>
                            <x-ui.pill :tone="$availability->tone()">{{ $availability->label() }}</x-ui.pill>
                            @if ($availability === \App\Enums\Travel\DepartureStatus::NearlyFull)
                                <span class="mt-0.5 block text-xs text-warning">{{ $available }} {{ $available === 1 ? 'slot' : 'slots' }} remaining</span>
                            @endif
                        </td>
                        <td><x-ui.pill :tone="$departure->trip_status->tone()" :dot="false">{{ $departure->trip_status->label() }}</x-ui.pill></td>
                        <td class="text-ink-muted">{{ $departure->package->owner?->name }}</td>
                        <td>
                            <x-ui.pill :tone="$departure->package->status->tone()" :dot="false">{{ $departure->package->status->label() }}</x-ui.pill>
                            @if ($departure->package->workingVersion?->isAwaitingReview())
                                <span class="mt-0.5 block text-xs text-warning">Change awaiting approval</span>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button variant="ghost" size="sm" wire:click="viewBookings({{ $departure->id }})">View bookings</x-ui.button>
                            @if ($canChange)
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $departure->id }})">Edit</x-ui.button>
                            @endif
                            @if ($departure->isBookable() && $departure->package->isSellable())
                                <x-ui.button variant="secondary" size="sm" :href="route('travel.bookings.create', ['package' => $departure->package_id, 'departure' => $departure->id])" wire:navigate>Book</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14">
                            <x-ui.empty-state icon="cube" title="No departures found" description="Add a departure to an approved package to start selling slots." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showForm" :title="$editingId ? 'Edit departure' : 'Add departure'" description="Dates, slots and who runs the trip.">
        <form wire:submit="save" id="departure-form" class="grid gap-3">
            @if (! $editingId)
                <x-ui.select label="Package" wire:model="form.package_id" id="dep-package">
                    <option value="">Choose an approved package</option>
                    @foreach ($manageablePackages as $option)<option value="{{ $option->id }}">{{ $option->name }} ({{ $option->reference }})</option>@endforeach
                </x-ui.select>
            @endif
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input label="Start date" type="date" wire:model="form.starts_on" id="dep-starts" />
                <x-ui.input label="Start time" type="time" wire:model="form.start_time" id="dep-start-time" />
                <x-ui.input label="End date" type="date" wire:model="form.ends_on" id="dep-ends" />
                <x-ui.input label="End time" type="time" wire:model="form.end_time" id="dep-end-time" />
                <x-ui.input label="Total slots" type="number" min="1" wire:model="form.capacity" id="dep-capacity" />
                <x-ui.input label="Waitlist" type="number" min="0" wire:model="form.waitlist_count" id="dep-waitlist" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.select label="Status" wire:model="form.status" id="dep-status">
                    @foreach ([\App\Enums\Travel\DepartureStatus::Open, \App\Enums\Travel\DepartureStatus::Closed, \App\Enums\Travel\DepartureStatus::Cancelled] as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Trip status" wire:model="form.trip_status" id="dep-trip">
                    @foreach (\App\Enums\Travel\TripStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                </x-ui.select>
                <x-ui.select label="Driver" wire:model="form.driver_id" id="dep-driver" hint="Leave empty to use the package's driver.">
                    <option value="">From the package</option>
                    @foreach ($drivers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select label="Guide" wire:model="form.guide_id" id="dep-guide" hint="Leave empty to use the package's guide.">
                    <option value="">From the package</option>
                    @foreach ($guides as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-ui.select>
            </div>
            @if ($managesAll)
                <label class="flex items-start gap-2 text-[13px] text-ink-muted">
                    <input type="checkbox" wire:model="form.allow_overbooking" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                    <span>Allow overbooking on this departure <span class="block text-xs text-ink-subtle">Bookings may go above the total slots. Recorded against your name.</span></span>
                </label>
                <label class="flex items-start gap-2 text-[13px] text-ink-muted">
                    <input type="checkbox" wire:model="form.override_conflict" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                    <span>Override driver/guide schedule clash <span class="block text-xs text-ink-subtle">Only if they really can do both trips. The override is audited.</span></span>
                </label>
            @endif
            <div class="grid gap-1">
                <label for="dep-notes" class="text-xs font-medium text-ink-muted">Notes</label>
                <textarea id="dep-notes" wire:model="form.notes" rows="3" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="departure-form">{{ $editingId ? 'Save changes' : 'Add departure' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>

    <x-ui.modal wire:model="showBookings" title="Bookings on this departure" :description="$bookingList ? $bookingList['departure']->package->name.' · '.$bookingList['departure']->dateLabel() : null" max-width="max-w-lg">
        @if ($bookingList)
            @php $taken = $bookingList['departure']->soldSlots(); @endphp
            <ol class="grid divide-y divide-line rounded-lg border border-line text-[13px]">
                @forelse ($bookingList['bookings'] as $i => $booking)
                    <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="dep-bk-{{ $booking->id }}">
                        <a href="{{ route('travel.bookings.show', $booking) }}" wire:navigate class="min-w-0 truncate text-ink hover:text-brand-text">{{ $i + 1 }}. {{ $booking->client->name }} — {{ $booking->travelers }} {{ $booking->travelers === 1 ? 'slot' : 'slots' }}</a>
                        <x-ui.pill :tone="$booking->status->tone()" :dot="false">{{ $booking->status->label() }}</x-ui.pill>
                    </li>
                @empty
                    <li class="px-3 py-4 text-center text-ink-subtle">No bookings yet.</li>
                @endforelse
            </ol>
            <p class="mt-3 text-sm font-semibold text-ink">Total: {{ $taken }} / {{ $bookingList['departure']->capacity }} sold
                @if ($bookingList['departure']->reservedSlots())<span class="font-normal text-ink-subtle">· {{ $bookingList['departure']->reservedSlots() }} reserved</span>@endif
            </p>
        @endif
    </x-ui.modal>
</div>
