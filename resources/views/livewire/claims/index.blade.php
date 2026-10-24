<div class="grid gap-5">
    <x-ui.page-header eyebrow="Me" title="Claims" description="Get money back for airtime or a trip you took, or ask Finance for transport money before you travel." />

    {{-- Start here: three plain choices, each saying who approves it. --}}
    <section aria-labelledby="claims-start" class="grid gap-3">
        <h2 id="claims-start" class="text-sm font-bold text-ink">What do you need?</h2>
        <div class="grid gap-3 md:grid-cols-3">
            <button type="button" wire:click="open('transport_reimbursement')" class="group grid content-start gap-2 rounded-xl border border-line bg-surface p-4 text-left shadow-card transition hover:border-brand hover:bg-brand-soft/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                <span class="grid size-9 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon name="wallet" class="size-5" /></span>
                <span class="text-sm font-bold text-ink">Get my trip money back</span>
                <span class="text-[13px] text-ink-muted">I already took the trip and paid. I have a receipt or Bolt/Uber details.</span>
                <span class="text-xs text-ink-subtle">Approved by Sales Manager, then HR, then Finance</span>
                <span class="mt-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-brand px-4 text-sm font-medium text-brand-ink group-hover:bg-brand-hover">Start <x-ui.icon name="arrow-right" class="size-4" /></span>
            </button>
            <button type="button" wire:click="open('transport_request')" class="group grid content-start gap-2 rounded-xl border border-line bg-surface p-4 text-left shadow-card transition hover:border-brand hover:bg-brand-soft/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                <span class="grid size-9 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon name="truck" class="size-5" /></span>
                <span class="text-sm font-bold text-ink">Ask for transport money</span>
                <span class="text-[13px] text-ink-muted">I have a trip coming up and want Finance to send the money before I go.</span>
                <span class="text-xs text-ink-subtle">Approved by Sales Manager, then HR, then Finance</span>
                <span class="mt-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-brand px-4 text-sm font-medium text-brand-ink group-hover:bg-brand-hover">Start <x-ui.icon name="arrow-right" class="size-4" /></span>
            </button>
            <button type="button" wire:click="open('airtime')" class="group grid content-start gap-2 rounded-xl border border-line bg-surface p-4 text-left shadow-card transition hover:border-brand hover:bg-brand-soft/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                <span class="grid size-9 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon name="phone" class="size-5" /></span>
                <span class="text-sm font-bold text-ink">Claim airtime</span>
                <span class="text-[13px] text-ink-muted">I spent my own money on airtime for work calls. KES {{ number_format($airtimeLeft) }} left this month.</span>
                <span class="text-xs text-ink-subtle">Approved by Finance</span>
                <span class="mt-1 inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-brand px-4 text-sm font-medium text-brand-ink group-hover:bg-brand-hover">Start <x-ui.icon name="arrow-right" class="size-4" /></span>
            </button>
        </div>
    </section>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Airtime used this month" :value="'KES '.number_format($airtimeUsed).' of '.number_format($airtimeCap)" hint="Counts approved claims. Unused airtime does not carry over." />
        <x-ui.stat label="Waiting for approval" :value="$pendingCount" hint="All your claims, not only this page" />
        <x-ui.stat label="Approved, not paid yet" :value="$approvedCount" hint="Trip refunds are paid with your monthly statement" />
    </div>

    <div class="grid gap-2">
        <h2 class="text-sm font-bold text-ink">Your claims</h2>
        <p class="text-[13px] text-ink-subtle">Select a claim to see its files, who approved it, and to add more files.</p>
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
                                    <button type="button" wire:click.stop="view({{ $claim->id }})" aria-label="Open claim: {{ $claim->typeLabel() }}" class="text-left font-semibold text-brand-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">{{ $claim->typeLabel() }}</button>
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
                            <td><div class="flex items-center justify-between gap-2"><x-ui.pill :tone="$claim->statusTone()">{{ $claim->statusLabel() }}</x-ui.pill><x-ui.icon name="arrow-right" class="size-4 text-ink-subtle" /></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="clipboard" title="No claims yet" description="Choose one of the three options above to send your first claim." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    </div>

    {{-- New claim --}}
    <x-ui.slide-over wire:model="showForm" :title="match ($type) {
        'airtime' => 'Claim airtime',
        'transport_request' => 'Ask for transport money',
        default => 'Get my trip money back',
    }" :description="match ($type) {
        'airtime' => 'Airtime you paid for yourself for sales and partner-support calls. Finance approves up to KES '.number_format($airtimeCap).' a month.',
        'transport_request' => 'For a trip you have not taken yet. Finance sends the money once the three approvals are done.',
        default => 'For a trip you already took and paid for. You are refunded once it is approved.',
    }">
        <form id="claim-form" wire:submit="submit" class="grid gap-5">
            <div class="grid gap-1.5 rounded-xl bg-surface-muted/70 px-4 py-3">
                <p class="text-xs font-medium text-ink-muted">Who approves this, in order</p>
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-semibold text-ink">
                    @foreach ($approvalSteps as $step)
                        <li class="flex items-center gap-2" wire:key="step-{{ $step }}">
                            @unless ($loop->first)<x-ui.icon name="arrow-right" class="size-3.5 text-ink-subtle" />@endunless
                            <span>{{ $loop->iteration }}. {{ \App\Models\ExpenseClaim::StepLabels[$step] ?? ucfirst($step) }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="text-xs text-ink-subtle">Everything below is required unless it says (optional).</p>
            </div>

            @if ($errors->any())
                <div role="alert" class="grid gap-1 rounded-xl border border-danger/40 bg-danger-soft px-4 py-3">
                    <p class="text-[13px] font-bold text-danger">Fix {{ $errors->count() === 1 ? 'this' : 'these '.$errors->count().' things' }} before sending:</p>
                    <ul class="list-disc pl-5 text-[13px] text-danger">
                        @foreach (collect($errors->all())->unique() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($type !== 'airtime')
                <fieldset class="grid gap-4">
                    <legend class="mb-1 text-sm font-bold text-ink">The trip</legend>

                    <x-ui.input :label="$type === 'transport_request' ? 'Date you will travel' : 'Date you travelled'" type="date" wire:model="form.travel_date" id="claim-date"
                        :min="$type === 'transport_request' ? now()->toDateString() : null"
                        :max="$type === 'transport_request' ? null : now()->toDateString()"
                        :hint="$type === 'transport_request' ? 'Today or a day in the future.' : 'Today or a day in the past.'" />

                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-xs font-medium text-ink-muted">How {{ $type === 'transport_request' ? 'will you travel' : 'did you travel' }}?</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\ExpenseClaim::RideProviders as $value => $label)
                                <label wire:key="ride-{{ $value }}" class="cursor-pointer">
                                    <input type="radio" wire:model.live="form.ride_provider" value="{{ $value }}" class="peer sr-only">
                                    <span class="inline-flex min-h-9 items-center rounded-full border border-line-strong px-3.5 py-1.5 text-[13px] font-semibold text-ink-muted peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand-text peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('form.ride_provider')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    </fieldset>

                    <div class="grid gap-4">
                        <x-ui.place-field label="Where from" model="form.pickup" lat-model="form.pickup_lat" lng-model="form.pickup_lng" id="claim-pickup" placeholder="Search a place, e.g. Westlands, Nairobi" />
                        <x-ui.place-field label="Where to" model="form.dropoff" lat-model="form.dropoff_lat" lng-model="form.dropoff_lng" id="claim-dropoff" placeholder="Search a place, e.g. Diani Beach" />
                    </div>

                    <x-ui.input label="Distance in km" type="number" step="0.1" min="0" wire:model="form.distance_km" id="claim-distance" optional />
                </fieldset>

                @if ($usesRideApp && $type === 'transport_reimbursement')
                    <fieldset class="grid gap-4 rounded-xl border border-brand/30 bg-brand-soft/50 p-4">
                        <legend class="px-1 text-[13px] font-bold text-brand-text">{{ \App\Models\ExpenseClaim::RideProviders[$form['ride_provider']] }} ride details</legend>
                        <x-ui.input label="Trip ID" wire:model="form.trip_reference" id="claim-trip" placeholder="e.g. RB12345678" hint="Copy it exactly from the trip history in the app." />
                        <div class="grid gap-1.5">
                            <label for="claim-ride" class="text-xs font-medium text-ink-muted">Trip receipt from the app <span class="font-bold text-danger">(required)</span></label>
                            <input type="file" multiple wire:model="rideDetails" id="claim-ride" class="text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-brand-text">
                            <p class="text-[13px] text-ink-subtle">A screenshot showing the route, time and fare, or the PDF receipt {{ \App\Models\ExpenseClaim::RideProviders[$form['ride_provider']] }} emails you.</p>
                            @if (count($rideDetails))<p class="text-[13px] font-semibold text-success">{{ trans_choice('{1} 1 file ready|[2,*] :count files ready', count($rideDetails)) }}</p>@endif
                            @error('rideDetails')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                            @error('rideDetails.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>
                @endif
            @endif

            <fieldset class="grid gap-4">
                <legend class="mb-1 text-sm font-bold text-ink">The cost</legend>
                <x-ui.input :label="$type === 'transport_request' ? 'Estimated cost in KES' : 'Amount in KES'" type="number" step="0.01" min="1" wire:model="form.amount" id="claim-amount"
                    :hint="match ($type) {
                        'airtime' => 'Finance approves up to KES '.number_format($airtimeLeft).' more this month.',
                        'transport_request' => 'Your best estimate of the total cost.',
                        default => 'The total you paid, as shown on the receipt.',
                    }" />
                <div class="grid gap-1.5">
                    <label for="claim-description" class="text-xs font-medium text-ink-muted">What was it for?</label>
                    <textarea id="claim-description" wire:model="form.description" rows="3" maxlength="1000" @error('form.description') aria-invalid="true" @enderror class="w-full rounded-md border bg-surface px-3 py-2 text-[13px] text-ink focus:ring-3 focus:outline-none {{ $errors->has('form.description') ? 'border-danger focus:ring-danger-soft' : 'border-line-strong focus:border-brand focus:ring-brand-soft' }}"
                        placeholder="{{ $type === 'airtime' ? 'e.g. Follow-up calls to 12 partners in review' : 'e.g. Site visit and partner training at Coral Bay Villas' }}"></textarea>
                    @error('form.description')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            @if ($type !== 'airtime')
                <fieldset class="grid gap-4">
                    <legend class="mb-1 text-sm font-bold text-ink">Link it to a partner or lead <span class="font-normal text-ink-subtle">(optional)</span></legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select label="Partner" optional wire:model="form.partner_account_id" id="claim-account">
                            <option value="">Not linked to a partner</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->legal_name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="Lead" optional wire:model="form.lead_id" id="claim-lead">
                            <option value="">Not linked to a lead</option>
                            @foreach ($leads as $lead)
                                <option value="{{ $lead->id }}">{{ $lead->business_name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </fieldset>
            @endif

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-bold text-ink">Proof</legend>
                <label for="claim-receipts" class="text-xs font-medium text-ink-muted">
                    @if ($type === 'transport_request')
                        Quote or supporting document <span class="font-normal text-ink-subtle">(optional)</span>
                    @elseif ($type === 'airtime')
                        Airtime receipt or M-Pesa message <span class="font-normal text-ink-subtle">(optional)</span>
                    @elseif ($receiptRequired)
                        Receipt <span class="font-bold text-danger">(required)</span>
                    @else
                        Receipt <span class="font-normal text-ink-subtle">(optional for this kind of trip. Add one if you have it.)</span>
                    @endif
                </label>
                <input type="file" multiple wire:model="receipts" id="claim-receipts" class="text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-brand-text">
                <p class="text-[13px] text-ink-subtle">Photos or PDFs, up to {{ intdiv(config('incentives.upload_max_kb'), 1024) }} MB each.</p>
                @if (count($receipts))<p class="text-[13px] font-semibold text-success">{{ trans_choice('{1} 1 file ready|[2,*] :count files ready', count($receipts)) }}</p>@endif
                <div wire:loading wire:target="receipts,rideDetails" class="text-[13px] text-brand-text">Uploading… wait for this to finish before you send.</div>
                @error('receipts')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                @error('receipts.*')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
            </fieldset>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" size="lg" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" size="lg" form="claim-form" wire:loading.attr="disabled" wire:target="submit,receipts,rideDetails">
                <span wire:loading.remove wire:target="submit">Send for approval</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </x-ui.button>
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
