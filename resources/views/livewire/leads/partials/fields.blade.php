<x-ui.input label="Business name" wire:model.live.debounce.500ms="form.business_name" id="lead-business" placeholder="e.g. ABC Hotel" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.select label="Type" wire:model="form.property_type" id="lead-type">
        @foreach (config('hub.property_types') as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </x-ui.select>
    <x-ui.input label="Location" wire:model="form.location" id="lead-location" placeholder="e.g. Nairobi" />
</div>
<details class="group rounded-md border border-line" @if (filled($form['trading_name'] ?? null) || filled($form['website'] ?? null) || filled($form['registration_number'] ?? null) || filled($form['kra_pin'] ?? null)) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-xs font-medium text-ink-muted hover:text-ink">
        Business identifiers <span class="font-normal text-ink-subtle">optional · improves the duplicate check</span>
    </summary>
    <div class="grid gap-3 border-t border-line p-3 sm:grid-cols-2">
        <x-ui.input label="Trading name" wire:model.live.debounce.500ms="form.trading_name" id="lead-trading" />
        <x-ui.input label="Website" wire:model.live.debounce.500ms="form.website" id="lead-website" placeholder="www.example.com" />
        <x-ui.input label="Registration number" wire:model.live.debounce.500ms="form.registration_number" id="lead-regno" />
        <x-ui.input label="KRA PIN" wire:model.live.debounce.500ms="form.kra_pin" id="lead-kra" placeholder="e.g. P051234567X" />
    </div>
</details>
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input label="Contact name" wire:model="form.contact_name" id="lead-contact" />
    <x-ui.input label="Their role" wire:model="form.contact_role" id="lead-role" placeholder="e.g. General Manager" />
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input label="Phone" type="tel" wire:model.live.debounce.500ms="form.contact_phone" id="lead-phone" />
    <x-ui.input label="Email" type="email" wire:model.live.debounce.500ms="form.contact_email" id="lead-email" />
</div>
<p class="-mt-2 text-[13px] text-ink-subtle">Use the same phone or email the provider will sign up with; that's how their tourlast.com signup gets linked to this lead.</p>
<div class="grid gap-1.5">
    <label for="lead-notes" class="text-xs font-medium text-ink-muted">Notes</label>
    <textarea id="lead-notes" wire:model="form.notes" rows="4" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none" placeholder="e.g. Interested in Tourlast distribution. Currently using another OTA."></textarea>
</div>
