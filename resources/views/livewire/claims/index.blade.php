<div class="grid gap-5">
    <x-ui.page-header eyebrow="Me" title="Claims" description="Airtime, transport reimbursements after a trip, and transport requests to Finance before a trip. Transport goes to your Sales Manager, then HR, then Finance.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="phone" wire:click="open('airtime')">Airtime</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow-right" wire:click="open('transport_request')">Request transport</x-ui.button>
            <x-ui.button icon="plus" wire:click="open('transport_reimbursement')">Claim transport</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Airtime this month" :value="'KES '.number_format($airtimeUsed).' / '.number_format($airtimeCap)" hint="Approved so far · allowance doesn't carry forward" />
        <x-ui.stat label="Waiting for approval" :value="$claims->getCollection()->where('status', 'pending')->count()" hint="On this page" />
        <x-ui.stat label="Approved, not yet paid" :value="$claims->getCollection()->where('status', 'approved')->count()" hint="Reimbursements are paid with your monthly statement" />
    </div>

    <x-ui.table-card :paginator="$claims">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Claim</th>
                    <th class="text-left">Trip</th>
                    <th class="text-left">Date</th>
                    <th class="text-right">Amount (KES)</th>
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($claims as $claim)
                    <tr wire:key="claim-{{ $claim->id }}" wire:click="view({{ $claim->id }})" class="cursor-pointer hover:bg-surface-muted/60">
                        <td>
                            <div class="grid leading-tight">
                                <span class="font-semibold text-ink">{{ $claim->typeLabel() }}</span>
                                <span class="truncate text-[13px] text-ink-subtle">{{ \Illuminate\Support\Str::limit($claim->description, 60) }}</span>
                            </div>
                        </td>
                        <td class="text-ink-muted">
                            @if ($claim->isTransport())
                                <div class="grid leading-tight"><span>{{ $claim->pickup }} → {{ $claim->dropoff }}</span><span class="text-[13px] text-ink-subtle">{{ $claim->rideProviderLabel() }}{{ $claim->trip_reference ? ' · '.$claim->trip_reference : '' }}</span></div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-ink-muted">{{ ($claim->travel_date ?? $claim->created_at)->format('j M Y') }}</td>
                        <td class="tabular text-right font-bold text-ink">{{ number_format($claim->payableAmount(), 2) }}</td>
                        <td><x-ui.pill :tone="$claim->statusTone()">{{ $claim->statusLabel() }}</x-ui.pill></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="clipboard" title="No claims yet" description="Claim airtime or transport, or request transport before a trip." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    {{-- New claim --}}
    <x-ui.slide-over wire:model="showForm" :title="\App\Models\ExpenseClaim::Types[$type] ?? 'Claim'" :description="match ($type) {
        'airtime' => 'Airtime for sales and partner-support calls. Up to KES '.number_format($airtimeCap).' a month, approved by Finance.',
        'transport_request' => 'Ask Finance for transport before a trip. It goes to your Sales Manager, then HR, then Finance, who releases the money.',
        default => 'Get refunded for a trip you already took. Attach the receipt, or the Bolt/Uber ride details.',
    }">
        <form id="claim-form" wire:submit="submit" class="grid gap-4">
            @if ($type !== 'airtime')
                <x-ui.input :label="$type === 'transport_request' ? 'Planned travel date' : 'Travel date'" type="date" wire:model="form.travel_date" id="claim-date" />

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-xs font-medium text-ink-muted">How {{ $type === 'transport_request' ? 'will you travel' : 'did you travel' }}?</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Models\ExpenseClaim::RideProviders as $value => $label)
                            <label wire:key="ride-{{ $value }}" class="cursor-pointer">
                                <input type="radio" wire:model.live="form.ride_provider" value="{{ $value }}" class="peer sr-only">
                                <span class="inline-flex rounded-full border border-line-strong px-3 py-1.5 text-[13px] font-semibold text-ink-muted peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand-text peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('form.ride_provider')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Pickup" wire:model="form.pickup" id="claim-pickup" placeholder="e.g. Tourlast office, Westlands" />
                    <x-ui.input label="Drop-off" wire:model="form.dropoff" id="claim-dropoff" placeholder="e.g. Coral Bay Villas, Diani" />
                </div>

                @if ($usesRideApp && $type === 'transport_reimbursement')
                    <div class="grid gap-4 rounded-xl border border-brand/30 bg-brand-soft/50 p-4">
                        <p class="text-[13px] font-bold text-brand-text">{{ \App\Models\ExpenseClaim::RideProviders[$form['ride_provider']] }} ride details</p>
                        <x-ui.input label="Trip ID" wire:model="form.trip_reference" id="claim-trip" placeholder="Shown on the trip receipt in the app" hint="Exactly as it appears in the app's trip history." />
                        <div class="grid gap-1.5">
                            <label for="claim-ride" class="text-xs font-medium text-ink-muted">Ride details from the app</label>
                            <input type="file" multiple wire:model="rideDetails" id="claim-ride" class="text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-brand-text">
                            <p class="text-[13px] text-ink-subtle">Screenshot of the trip receipt (route, time, fare) or the PDF receipt {{ \App\Models\ExpenseClaim::RideProviders[$form['ride_provider']] }} emails you.</p>
                            @error('rideDetails')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                            @error('rideDetails.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endif

                <x-ui.input label="Distance (km)" type="number" step="0.1" wire:model="form.distance_km" id="claim-distance" hint="Optional." />
            @endif

            <x-ui.input :label="$type === 'transport_request' ? 'Estimated amount (KES)' : 'Amount (KES)'" type="number" step="0.01" wire:model="form.amount" id="claim-amount" />
            <div class="grid gap-1.5">
                <label for="claim-description" class="text-xs font-medium text-ink-muted">Purpose</label>
                <textarea id="claim-description" wire:model="form.description" rows="3" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"
                    placeholder="{{ $type === 'airtime' ? 'e.g. Follow-up calls to 12 partners in review' : 'e.g. Site visit and partner training at Coral Bay Villas' }}"></textarea>
                @error('form.description')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
            </div>

            @if ($type !== 'airtime')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select label="Related Account" wire:model="form.partner_account_id" id="claim-account">
                        <option value="">None</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->legal_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Related lead" wire:model="form.lead_id" id="claim-lead">
                        <option value="">None</option>
                        @foreach ($leads as $lead)
                            <option value="{{ $lead->id }}">{{ $lead->business_name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif

            <div class="grid gap-1.5">
                <label for="claim-receipts" class="text-xs font-medium text-ink-muted">{{ $type === 'transport_request' ? 'Quote or supporting document (optional)' : 'Receipts' }}</label>
                <input type="file" multiple wire:model="receipts" id="claim-receipts" class="text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-brand-text">
                <p class="text-[13px] text-ink-subtle">Photos or PDFs, up to {{ intdiv(config('incentives.upload_max_kb'), 1024) }} MB each.</p>
                <div wire:loading wire:target="receipts,rideDetails" class="text-[13px] text-brand-text">Uploading…</div>
                @error('receipts')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                @error('receipts.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="claim-form">{{ $type === 'transport_request' ? 'Send to approval' : 'Submit claim' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>

    {{-- Detail --}}
    <x-ui.slide-over wire:model="showDetail" :title="$viewing?->typeLabel() ?? 'Claim'" :description="$viewing ? 'Submitted '.$viewing->created_at->format('j M Y, H:i') : null">
        @if ($viewing)
            @include('livewire.claims.partials.detail', ['claim' => $viewing])

            @if (! in_array($viewing->status, ['rejected'], true) && ! ($viewing->status === 'paid' && ! $viewing->isRequest()))
                <form wire:submit="addAttachments" class="mt-6 grid gap-3 rounded-xl border border-line p-4">
                    <p class="text-[13px] font-bold text-ink">Add files</p>
                    <label class="grid gap-1 text-[13px] text-ink-muted">Receipts
                        <input type="file" multiple wire:model="moreReceipts" id="more-receipts" class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-brand-text">
                    </label>
                    @if ($viewing->isTransport())
                        <label class="grid gap-1 text-[13px] text-ink-muted">Bolt / Uber ride details
                            <input type="file" multiple wire:model="moreRideDetails" id="more-ride" class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-brand-text">
                        </label>
                    @endif
                    @error('moreReceipts.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    @error('moreRideDetails.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    <div><x-ui.button type="submit" size="sm">Upload</x-ui.button></div>
                </form>
            @endif
        @endif
    </x-ui.slide-over>
</div>
