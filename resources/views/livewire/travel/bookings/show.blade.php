@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $money = fn ($value) => $booking->currency.' '.number_format((float) $value, 2);
    $sources = ['booking' => 'set on this booking', 'departure' => 'from the departure', 'package' => 'from the package'];
    $status = $booking->status;
    $isOpen = in_array($status, [\App\Enums\Travel\TravelBookingStatus::Pending, \App\Enums\Travel\TravelBookingStatus::Confirmed], true);
    $tripStarted = ! $booking->departure->starts_on->isFuture();
@endphp

<div class="grid gap-5">
    <x-ui.page-header :title="$booking->reference" :description="$booking->package->name.' · '.$booking->departure->dateLabel()">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="ticket" :href="route('travel.bookings.ticket', $booking)" wire:navigate>{{ in_array($status, [\App\Enums\Travel\TravelBookingStatus::Confirmed, \App\Enums\Travel\TravelBookingStatus::Completed], true) ? 'View ticket' : 'Preview ticket' }}</x-ui.button>
            @if ($canWork)
                @if ($status === \App\Enums\Travel\TravelBookingStatus::Pending)
                    <x-ui.button icon="check" wire:click="confirm">Confirm booking</x-ui.button>
                @endif
                @if ($status === \App\Enums\Travel\TravelBookingStatus::Confirmed && $tripStarted)
                    <x-ui.button icon="check-circle" wire:click="complete" wire:confirm="Mark this trip completed?">Mark completed</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="noShow" wire:confirm="Mark the client as a no-show?">No-show</x-ui.button>
                @endif
                @if ((float) $booking->amount_paid > (float) $booking->amount_refunded)
                    <x-ui.button variant="secondary" icon="refresh" wire:click="openRefund">Request refund</x-ui.button>
                @endif
                @if ($isOpen)
                    <x-ui.button variant="danger-ghost" icon="x" wire:click="openCancel">Cancel booking</x-ui.button>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('status')
        <div class="rounded-lg border border-danger/40 bg-danger-soft/50 px-4 py-2.5 text-[13px] text-danger">{{ $message }}</div>
    @enderror

    <div class="flex flex-wrap items-center gap-2 text-[13px]">
        <span class="text-ink-subtle">Booking</span><x-ui.pill :tone="$status->tone()">{{ $status->label() }}</x-ui.pill>
        <span class="ml-2 text-ink-subtle">Payment</span><x-ui.pill :tone="$booking->payment_status->tone()">{{ $booking->payment_status->label() }}</x-ui.pill>
        <span class="ml-2 text-ink-subtle">Trip</span><x-ui.pill :tone="$booking->departure->trip_status->tone()">{{ $booking->departure->trip_status->label() }}</x-ui.pill>
        <span class="ml-2 text-ink-subtle">Source</span><x-ui.pill :tone="$booking->source->tone()" :dot="false">{{ $booking->source->label() }}</x-ui.pill>
        @if ($status === \App\Enums\Travel\TravelBookingStatus::Pending && $booking->hold_expires_at)
            <span class="ml-2 text-xs {{ $booking->isHoldingSlots() ? 'text-ink-subtle' : 'text-danger' }}">
                {{ $booking->isHoldingSlots() ? 'Slots held until '.$booking->hold_expires_at->format('j M, H:i') : 'Hold expired '.$booking->hold_expires_at->diffForHumans() }}
            </span>
        @endif
        @if ($needsAction)
            <x-ui.pill tone="danger">Pre-trip action required</x-ui.pill>
        @endif
    </div>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-4">
        @foreach ([
            ['Total', $money($booking->amount_total), 'text-ink'],
            ['Paid', $money($booking->amount_paid), 'text-success'],
            ['Refunded', $money($booking->amount_refunded), 'text-ink'],
            ['Balance', $money($booking->balance()), $booking->balance() > 0 ? 'text-warning' : 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="grid gap-4">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.card title="Client">
                    <dl class="grid gap-1.5 text-[13px]">
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Name</dt><dd class="text-right font-medium text-ink">{{ $booking->client->name }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Phone</dt><dd class="text-right text-ink">{{ ($seesContact ? $booking->client->phone : $booking->client->maskedPhone()) ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Email</dt><dd class="truncate text-right text-ink">{{ $booking->client->email ? ($seesContact ? $booking->client->email : \Illuminate\Support\Str::mask($booking->client->email, '*', 2, max(0, strpos($booking->client->email, '@') - 2))) : '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Country</dt><dd class="text-right text-ink">{{ $booking->client->country ?? '—' }}</dd></div>
                        @if ($seesContact && ($booking->emergency_contact_name || $booking->emergency_contact_phone))
                            <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Emergency contact</dt><dd class="text-right text-ink">{{ $booking->emergency_contact_name }} {{ $booking->emergency_contact_phone }}</dd></div>
                        @endif
                    </dl>
                </x-ui.card>

                <x-ui.card title="Travelers">
                    <dl class="grid gap-1.5 text-[13px]">
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Adults</dt><dd class="tabular text-ink">{{ $booking->adults }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Children</dt><dd class="tabular text-ink">{{ $booking->children }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Infants</dt><dd class="tabular text-ink">{{ $booking->infants }}</dd></div>
                        <div class="flex justify-between gap-3 border-t border-line pt-1.5"><dt class="font-medium text-ink">Total travelers</dt><dd class="tabular font-semibold text-ink">{{ $booking->travelers }}</dd></div>
                        @if ($booking->special_requirements)<div class="grid gap-0.5 pt-1"><dt class="text-ink-subtle">Special requirements</dt><dd class="text-ink">{{ $booking->special_requirements }}</dd></div>@endif
                        @if ($booking->dietary_requirements)<div class="grid gap-0.5"><dt class="text-ink-subtle">Dietary</dt><dd class="text-ink">{{ $booking->dietary_requirements }}</dd></div>@endif
                    </dl>
                </x-ui.card>
            </div>

            <x-ui.card title="Guests" :description="$booking->guests->isEmpty() ? null : $booking->guests->count().' of '.$booking->travelers.' '.\Illuminate\Support\Str::plural('traveler', $booking->travelers)" :padding="false">
                <x-slot:actions>
                    @if ($canWork && $booking->guests->isNotEmpty())
                        <x-ui.button variant="secondary" size="sm" wire:click="openGuests">Edit guests</x-ui.button>
                    @endif
                </x-slot:actions>
                @if ($booking->guests->isEmpty())
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 text-[13px]">
                        <span class="text-ink-subtle">Guest details not captured. Only the number of travelers is on this booking.</span>
                        @if ($canWork)
                            <x-ui.button size="sm" icon="plus" wire:click="openGuests">Add guest details</x-ui.button>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-line text-[13px]">
                        @foreach ($booking->guests as $guest)
                            <li class="grid gap-0.5 px-4 py-2.5" wire:key="bk-g-{{ $guest->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-ink">{{ $guest->full_name }}</span>
                                    <span class="text-xs text-ink-subtle">{{ $guest->type->label() }}</span>
                                    @if ($guest->is_booker)<x-ui.pill tone="brand" :dot="false">Booker</x-ui.pill>@endif
                                </div>
                                @php
                                    $facts = array_filter([
                                        $guest->nationality,
                                        $guest->date_of_birth ? 'Born '.$guest->date_of_birth->format('j M Y').' ('.$guest->date_of_birth->age.')' : null,
                                        $guest->id_number ? 'ID '.$guest->maskedIdNumber() : null,
                                        $seesContact ? $guest->phone : null,
                                        $seesContact ? $guest->email : null,
                                    ]);
                                @endphp
                                @if ($facts)<span class="text-xs text-ink-subtle">{{ implode(' · ', $facts) }}</span>@endif
                                @if ($guest->special_requirements)<span class="text-xs text-warning">Needs: {{ $guest->special_requirements }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card title="Trip" :description="$booking->package->provider?->name">
                <x-slot:actions>
                    @if ($canWork && $isOpen)
                        <x-ui.button variant="secondary" size="sm" wire:click="openAssign">Assign driver / guide</x-ui.button>
                    @endif
                </x-slot:actions>
                <dl class="grid gap-1.5 text-[13px] md:grid-cols-2 md:gap-x-6">
                    <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Package version sold</dt><dd class="text-ink">{{ $booking->version?->label() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Travel dates</dt><dd class="text-ink">{{ $booking->departure->dateLabel() }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-subtle">Driver</dt>
                        <dd class="text-right text-ink">{{ $booking->effectiveDriver()?->name ?? 'Not assigned' }}
                            @if ($booking->driverSource())<span class="block text-xs text-ink-subtle">{{ $sources[$booking->driverSource()] }}</span>@endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-subtle">Guide</dt>
                        <dd class="text-right text-ink">{{ $booking->effectiveGuide()?->name ?? ($booking->package->guide_required ? 'Not assigned' : 'No guide required') }}
                            @if ($booking->guideSource())<span class="block text-xs text-ink-subtle">{{ $sources[$booking->guideSource()] }}</span>@endif
                        </dd>
                    </div>
                    @if ($booking->effectiveDriver()?->vehicle)
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Vehicle</dt><dd class="text-ink">{{ $booking->effectiveDriver()->vehicle }} {{ $booking->effectiveDriver()->vehicle_registration }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Salesperson</dt><dd class="text-ink">{{ $booking->salesperson?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Package owner</dt><dd class="text-ink">{{ $booking->package->owner?->name }}</dd></div>
                    @if ($booking->influencerCode)
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Influencer code</dt><dd class="text-ink">{{ $booking->influencerCode->code }} · {{ $booking->influencerCode->influencer?->name }}</dd></div>
                    @endif
                </dl>
                @if ($booking->notes)
                    <p class="mt-3 border-t border-line pt-3 text-[13px] text-ink-muted">{{ $booking->notes }}</p>
                @endif
            </x-ui.card>

            @if (class_exists(\App\Livewire\Travel\Payments\BookingPayments::class))
                <livewire:travel.payments.booking-payments :booking-id="$booking->id" :key="'pay-'.$booking->id" />
            @endif

            <x-ui.card title="Cancellations and refunds" :padding="false">
                @if ($booking->cancellations->isEmpty() && $booking->refunds->isEmpty())
                    <p class="px-4 py-4 text-[13px] text-ink-subtle">None.</p>
                @else
                    <ul class="divide-y divide-line text-[13px]">
                        @foreach ($booking->cancellations as $cancellation)
                            <li class="grid gap-1 px-4 py-2.5" wire:key="bk-c-{{ $cancellation->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium text-ink">Cancellation · {{ $cancellation->created_at->format('j M Y') }} by {{ $cancellation->requester?->name }}</span>
                                    <x-ui.pill :tone="$cancellation->status->tone()">{{ $cancellation->status->label() }}</x-ui.pill>
                                </div>
                                <p class="text-ink-muted">{{ $cancellation->reason }}</p>
                                <p class="text-xs text-ink-subtle">Refund {{ $money($cancellation->refund_amount) }}@if ($cancellation->decider) · decided by {{ $cancellation->decider->name }} {{ $cancellation->decided_at?->format('j M Y') }}@endif @if ($cancellation->decision_note) · “{{ $cancellation->decision_note }}”@endif</p>
                            </li>
                        @endforeach
                        @foreach ($booking->refunds as $refund)
                            <li class="grid gap-1 px-4 py-2.5" wire:key="bk-r-{{ $refund->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium text-ink">Refund {{ $money($refund->amount) }} · requested by {{ $refund->requester?->name }}</span>
                                    <x-ui.pill :tone="$refund->status->tone()">{{ $refund->status->label() }}</x-ui.pill>
                                </div>
                                <p class="text-ink-muted">{{ $refund->reason }}</p>
                                <p class="text-xs text-ink-subtle">
                                    @if ($refund->approver)Approved by {{ $refund->approver->name }}@endif
                                    @if ($refund->processor) · processed by {{ $refund->processor->name }}@endif
                                    @if ($refund->reference) · {{ $refund->method?->label() }} {{ $refund->reference }}@endif
                                    @if ($refund->failure_reason) · {{ $refund->failure_reason }}@endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div class="grid gap-4">
            <x-ui.card title="Pre-trip checklist" :description="$needsAction ? 'Some required items are outstanding and the trip is within a week.' : 'Items in grey are filled in automatically.'" :padding="false">
                <ul class="divide-y divide-line text-[13px]">
                    @foreach ($checklist as $item)
                        <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="chk-{{ $item['key'] }}">
                            <span class="flex min-w-0 items-center gap-2">
                                @if ($item['automatic'] || ! $canWork || ! in_array($status, [\App\Enums\Travel\TravelBookingStatus::Confirmed, \App\Enums\Travel\TravelBookingStatus::Completed], true))
                                    <span @class(['grid size-4 place-items-center rounded border text-[10px]', 'border-success bg-success text-white' => $item['done'], 'border-line-strong' => ! $item['done']])>@if ($item['done'])✓@endif</span>
                                @else
                                    <input type="checkbox" @checked($item['done']) wire:click="toggleItem('{{ $item['key'] }}')" class="size-4 accent-[var(--tl-brand)]" aria-label="{{ $item['label'] }}">
                                @endif
                                <span @class(['text-ink' => $item['done'], 'text-ink-muted' => ! $item['done'], 'font-medium text-danger' => ! $item['done'] && $item['required'] && $needsAction])>{{ $item['label'] }}</span>
                                @if (! $item['required'])<span class="text-xs text-ink-subtle">optional</span>@endif
                            </span>
                            <span class="shrink-0 text-xs text-ink-subtle">{{ $item['automatic'] ? 'Automatic' : ($item['by'] ? $item['by'].' · '.$item['at']?->format('j M') : '') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.card title="History" :padding="false">
                <ol class="divide-y divide-line text-[13px]">
                    @forelse ($audit as $event)
                        <li class="grid gap-0.5 px-4 py-2" wire:key="aud-{{ $event->id }}">
                            <span class="text-ink">{{ $event->summary }}</span>
                            <span class="text-xs text-ink-subtle">{{ $event->user?->name ?? 'System' }} · {{ $event->created_at->format('j M Y, H:i') }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-ink-subtle">No history yet.</li>
                    @endforelse
                </ol>
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal wire:model="showCancel" title="Cancel booking" description="A Sales Admin approves the cancellation before the booking is cancelled.">
        <form wire:submit="requestCancellation" id="cancel-form" class="grid gap-3">
            @if ($booking->version?->cancellation_policy)
                <div class="rounded-lg border border-line bg-surface-muted/50 px-3 py-2 text-[13px] text-ink-muted"><span class="font-medium text-ink">Cancellation policy:</span> {{ $booking->version->cancellation_policy }}</div>
            @endif
            <div class="grid gap-1">
                <label for="cancel-reason" class="text-xs font-medium text-ink-muted">Reason</label>
                <textarea id="cancel-reason" wire:model="cancel.reason" rows="3" class="{{ $textarea }}"></textarea>
                @error('cancel.reason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
            </div>
            <x-ui.input label="Refund to the client ({{ $booking->currency }})" type="number" min="0" step="0.01" wire:model="cancel.refund_amount" id="cancel-refund" hint="Paid so far: {{ $money($booking->amount_paid) }}" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Keep booking</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="cancel-form">Send for approval</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showRefund" title="Request a refund" description="For a refund without cancelling the booking. A Sales Admin approves it, then Accounts pays it out.">
        <form wire:submit="requestRefund" id="refund-form" class="grid gap-3">
            <x-ui.input label="Amount ({{ $booking->currency }})" type="number" min="0" step="0.01" wire:model="refund.refund_amount" id="refund-amount" />
            <div class="grid gap-1">
                <label for="refund-reason" class="text-xs font-medium text-ink-muted">Reason</label>
                <textarea id="refund-reason" wire:model="refund.refund_reason" rows="3" class="{{ $textarea }}"></textarea>
                @error('refund.refund_reason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="refund-form">Send for approval</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showGuests" title="Guest details" description="One row per traveler on this booking. A full name is needed for everyone; the rest is optional. Changes are recorded in the history." max-width="max-w-3xl">
        <form wire:submit="saveGuests" id="guests-form">
            @include('livewire.travel.bookings.partials.guest-rows', ['model' => 'guests', 'rows' => $guests, 'idPrefix' => 'bk-guest'])
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="guests-form">Save guests</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showAssign" title="Driver and guide for this booking" description="Overrides the departure and package. Leave empty to use theirs.">
        <form wire:submit="saveAssign" id="assign-form" class="grid gap-3">
            <x-ui.select label="Driver" wire:model="assign.driver_id" id="assign-driver">
                <option value="">Use the departure / package driver</option>
                @foreach ($drivers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Guide" wire:model="assign.guide_id" id="assign-guide">
                <option value="">Use the departure / package guide</option>
                @foreach ($guides as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            @if ($managesAll)
                <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                    <input type="checkbox" wire:model="assign.override_conflict" class="size-4 accent-[var(--tl-brand)]"> Override a schedule clash (audited)
                </label>
            @endif
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="assign-form">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
