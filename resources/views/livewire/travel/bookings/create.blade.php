@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="New booking" description="Choose an approved package and a departure, then the client, travelers and each guest. Slots are held while the booking is pending.">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('travel.bookings.index')" wire:navigate>Back to bookings</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <p class="text-[13px] text-ink-subtle xl:col-span-2">Everything is required unless it says (optional).</p>
        <div class="grid gap-4">
            <x-ui.card title="1. Package and departure">
                <div class="grid gap-3 md:grid-cols-2">
                    <x-ui.select label="Package" wire:model.live="packageId" id="nb-package">
                        <option value="">Choose a package</option>
                        @foreach ($packages as $option)<option value="{{ $option->id }}">{{ $option->name }} ({{ $option->reference }})</option>@endforeach
                    </x-ui.select>
                    <x-ui.select label="Departure" wire:model.live="departureId" id="nb-departure" :disabled="! $package">
                        <option value="">{{ $package ? ($departures->isEmpty() ? 'No upcoming departures' : 'Choose a date') : 'Choose a package first' }}</option>
                        @foreach ($departures as $option)
                            <option value="{{ $option->id }}" @disabled($option->availableSlots() === 0 && ! $option->allow_overbooking)>
                                {{ $option->dateLabel() }} — {{ $option->availableSlots() }} of {{ $option->capacity }} available
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>
                @error('form.package_id')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
                @error('form.package_departure_id')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
            </x-ui.card>

            <x-ui.card title="2. Client" description="Search first so the same client is never added twice. A new client is matched on phone or email.">
                @if ($client)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-muted/50 px-3 py-2.5">
                        <span class="grid leading-tight">
                            <span class="font-medium text-ink">{{ $client->name }}</span>
                            <span class="text-xs text-ink-subtle">{{ collect([$client->phone, $client->email, $client->country])->filter()->implode(' · ') }}</span>
                        </span>
                        <x-ui.button variant="ghost" size="sm" wire:click="clearClient">Change</x-ui.button>
                    </div>
                @else
                    <div class="grid gap-3">
                        <x-ui.search wire:model.live.debounce.300ms="clientSearch" placeholder="Search existing clients by name, phone or email" wide />
                        @if ($clientMatches->isNotEmpty())
                            <ul class="divide-y divide-line rounded-lg border border-line text-[13px]">
                                @foreach ($clientMatches as $match)
                                    <li wire:key="cm-{{ $match->id }}">
                                        <button type="button" wire:click="pickClient({{ $match->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-surface-muted/50">
                                            <span class="font-medium text-ink">{{ $match->name }}</span>
                                            <span class="text-xs text-ink-subtle">{{ \App\Models\TravelClient::mask($match->phone) }} {{ $match->email ? '· '.\Illuminate\Support\Str::mask($match->email, '*', 2, max(0, strpos($match->email, '@') - 2)) : '' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (strlen(trim($clientSearch)) >= 2)
                            <p class="text-xs text-ink-subtle">No existing client matches. Add them below.</p>
                        @endif
                        <div class="grid gap-3 md:grid-cols-2">
                            <x-ui.input label="Full name" wire:model.blur="form.client_name" id="nb-client-name" />
                            <x-ui.input label="Phone" optional wire:model.blur="form.client_phone" id="nb-client-phone" />
                            <x-ui.input label="Email" optional type="email" wire:model.blur="form.client_email" id="nb-client-email" />
                            <x-ui.input label="Country" optional wire:model.blur="form.client_country" id="nb-client-country" />
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="3. Travelers and requirements">
                <div class="grid gap-3">
                    <div class="grid grid-cols-3 gap-3">
                        <x-ui.input label="Adults" type="number" min="1" wire:model.live.debounce.300ms="form.adults" id="nb-adults" />
                        <x-ui.input label="Children" optional type="number" min="0" wire:model.live.debounce.300ms="form.children" id="nb-children" />
                        <x-ui.input label="Infants" optional type="number" min="0" wire:model.live.debounce.300ms="form.infants" id="nb-infants" />
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="grid gap-1">
                            <label for="nb-special" class="text-xs font-medium text-ink-muted">Special requirements <span class="font-normal text-ink-subtle">(optional)</span></label>
                            <textarea id="nb-special" wire:model="form.special_requirements" rows="2" class="{{ $textarea }}"></textarea>
                        </div>
                        <div class="grid gap-1">
                            <label for="nb-diet" class="text-xs font-medium text-ink-muted">Dietary requirements <span class="font-normal text-ink-subtle">(optional)</span></label>
                            <textarea id="nb-diet" wire:model="form.dietary_requirements" rows="2" class="{{ $textarea }}"></textarea>
                        </div>
                        <x-ui.input label="Emergency contact (if needed for this trip)" optional wire:model="form.emergency_contact_name" id="nb-emergency-name" />
                        <x-ui.input label="Emergency contact phone" optional wire:model="form.emergency_contact_phone" id="nb-emergency-phone" />
                    </div>
                    <div class="grid gap-1">
                        <label for="nb-notes" class="text-xs font-medium text-ink-muted">Notes <span class="font-normal text-ink-subtle">(optional)</span></label>
                        <textarea id="nb-notes" wire:model="form.notes" rows="2" class="{{ $textarea }}"></textarea>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="4. Guests" description="Who is travelling: one row per traveler. A name is needed for everyone; the rest helps the driver, guide and lodges.">
                <div class="grid gap-3">
                    <fieldset class="grid gap-2">
                        <legend class="text-[13px] font-medium text-ink">Who is this booking for?</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            @foreach (['alone' => ['Just me', 'The client travels alone.'], 'group' => ['Me and others', 'The client travels with other people.'], 'others' => ['Someone else', 'The client books but does not travel.']] as $choice => [$choiceLabel, $choiceHint])
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-[13px] has-[:checked]:border-brand has-[:checked]:bg-brand-soft {{ $travellingFor === $choice ? 'border-brand bg-brand-soft' : 'border-line' }}">
                                    <input type="radio" wire:model.live="travellingFor" value="{{ $choice }}" class="mt-0.5 size-4 accent-[var(--tl-brand)]" id="nb-for-{{ $choice }}">
                                    <span class="grid"><span class="font-medium text-ink">{{ $choiceLabel }}</span><span class="text-xs text-ink-subtle">{{ $choiceHint }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    @include('livewire.travel.bookings.partials.guest-rows', ['model' => 'form.guests', 'rows' => $form['guests'], 'idPrefix' => 'nb-guest', 'canRemove' => true])
                    <div class="flex flex-wrap items-end gap-2">
                        <x-ui.select label="Add a" wire:model="newGuestType" id="nb-new-guest-type">
                            @foreach (\App\Enums\Travel\GuestType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.button type="button" variant="secondary" icon="plus" wire:click="addGuest">Add another guest</x-ui.button>
                    </div>
                    <p class="text-xs text-ink-subtle">Adults, children and infants above update as you add or remove guests.</p>
                </div>
            </x-ui.card>
        </div>

        <div class="grid gap-4 xl:sticky xl:top-4">
            <x-ui.card title="Summary">
                <dl class="grid gap-2 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Package</dt><dd class="text-right text-ink">{{ $package?->name ?? '—' }}</dd></div>
                    @if ($version)
                        <div class="flex justify-between gap-3"><dt class="text-ink-subtle">Adult price</dt><dd class="tabular text-ink">{{ $version->currency }} {{ number_format((float) $version->adult_price) }}</dd></div>
                        @if ($version->child_price !== null)<div class="flex justify-between gap-3"><dt class="text-ink-subtle">Child price</dt><dd class="tabular text-ink">{{ $version->currency }} {{ number_format((float) $version->child_price) }}</dd></div>@endif
                    @endif
                    <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="font-medium text-ink">Total</dt><dd class="tabular text-base font-bold text-ink">{{ $price !== null ? ($version->currency.' '.number_format($price)) : '—' }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Attribution">
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <span class="text-[13px] font-medium text-ink">Influencer code <span class="font-normal text-ink-subtle">(optional)</span></span>
                        @if ($selectedCode)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-muted/50 px-3 py-2">
                                <span class="grid leading-tight">
                                    <span class="font-medium text-ink">{{ $selectedCode->code }}</span>
                                    @if (in_array($selectedCode->id, $codeDetailIds, true))
                                        <span class="text-xs text-ink-subtle">{{ $selectedCode->influencer?->name }} · {{ $selectedCode->termsLabel() }}</span>
                                    @endif
                                </span>
                                <x-ui.button variant="ghost" size="sm" wire:click="clearCode">Remove</x-ui.button>
                            </div>
                        @else
                            <x-ui.search wire:model.live.debounce.300ms="codeSearch" placeholder="Type the influencer's name or code" wide id="nb-code" />
                            @if ($codeMatches->isNotEmpty())
                                <ul class="divide-y divide-line rounded-lg border border-line text-[13px]" aria-label="Matching influencer codes">
                                    @foreach ($codeMatches as $match)
                                        <li wire:key="code-{{ $match->id }}">
                                            <button type="button" wire:click="pickCode({{ $match->id }})" class="flex w-full flex-col px-3 py-2 text-left hover:bg-surface-muted/50">
                                                @if (in_array($match->id, $codeDetailIds, true))
                                                    <span class="font-medium text-ink">{{ $match->code }} · {{ $match->influencer?->name }}</span>
                                                    <span class="text-xs text-ink-subtle">{{ $match->termsLabel() }}</span>
                                                @else
                                                    <span class="font-medium text-ink">{{ $match->code }}</span>
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($codeSearch) !== '')
                                <p class="text-xs text-ink-subtle">No running code matches. Check the spelling, or leave this empty.</p>
                            @else
                                <p class="text-xs text-ink-subtle">Only if the client booked with a code. Leave empty if not.</p>
                            @endif
                        @endif
                        @error('influencer_code')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    </div>
                    @if ($managesAll)
                        <x-ui.select label="Salesperson" optional wire:model="form.salesperson_id" id="nb-salesperson">
                            <option value="">Me</option>
                            @foreach ($salespeople as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input label="Override price" optional type="number" min="0" step="0.01" wire:model="form.amount_override" id="nb-override" />
                        <x-ui.input label="Reason for the price change" wire:model="form.override_reason" id="nb-override-reason" />
                    @endif
                </div>
            </x-ui.card>

            <x-ui.button type="submit" size="lg" class="w-full">Take booking</x-ui.button>
        </div>
    </form>
</div>
