<x-ui.modal wire:model="{{ $model }}" :title="$title" description="Payout details are only visible to you, Travel managers and Accounts." maxWidth="max-w-lg">
    <form wire:submit="{{ $action }}" id="influencer-form" class="grid gap-3">
        <x-ui.input label="Name" wire:model="influencerForm.name" id="inf-name" />

        <fieldset class="grid gap-2">
            <legend class="mb-1 text-xs font-medium text-ink-muted">Platforms</legend>
            @forelse ($influencerForm['platforms'] ?? [] as $index => $row)
                <div wire:key="inf-platform-{{ $index }}" class="grid gap-2 rounded-md border border-line p-2.5">
                    <div class="grid grid-cols-[9rem_minmax(0,1fr)_auto] items-start gap-2">
                        <select wire:model="influencerForm.platforms.{{ $index }}.platform" aria-label="Platform {{ $index + 1 }}" class="h-9 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:outline-none">
                            <option value="">Choose…</option>
                            @foreach (\App\Models\Influencer::Platforms as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="influencerForm.platforms.{{ $index }}.handle" aria-label="Handle on platform {{ $index + 1 }}" placeholder="@name" class="h-9 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:outline-none" />
                        <button type="button" wire:click="removePlatform({{ $index }})" class="grid size-9 place-items-center rounded-md text-ink-subtle hover:bg-surface-muted hover:text-danger" aria-label="Remove platform {{ $index + 1 }}" title="Remove">
                            <x-ui.icon name="x" class="size-4" />
                        </button>
                    </div>
                    <input type="url" wire:model="influencerForm.platforms.{{ $index }}.url" aria-label="Profile link on platform {{ $index + 1 }}" placeholder="Profile link (optional), e.g. https://instagram.com/name" class="h-9 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:outline-none" />
                    @foreach (['platform', 'handle', 'url'] as $field)
                        @error('influencerForm.platforms.'.$index.'.'.$field) <p class="text-xs text-danger">{{ $message }}</p> @enderror
                    @endforeach
                </div>
            @empty
                <p class="text-[13px] text-ink-muted">No platforms yet.</p>
            @endforelse
            @error('influencerForm.platforms') <p class="text-xs text-danger">{{ $message }}</p> @enderror
            @if (count($influencerForm['platforms'] ?? []) < count(\App\Models\Influencer::Platforms))
                <button type="button" wire:click="addPlatform" class="justify-self-start text-[13px] font-medium text-brand-text hover:underline">+ Add another platform</button>
            @endif
        </fieldset>

        <div class="grid grid-cols-2 gap-3">
            <x-ui.input label="Phone" wire:model="influencerForm.phone" id="inf-phone" />
            <x-ui.input label="Email" type="email" wire:model="influencerForm.email" id="inf-email" />
        </div>
        <div class="grid grid-cols-[10rem_1fr] gap-3">
            <x-ui.select label="Payout method" wire:model="influencerForm.payout_method" id="inf-payout-method">
                <option value="">Not set</option>
                <option value="mpesa">M-Pesa</option>
                <option value="bank">Bank</option>
            </x-ui.select>
            <x-ui.input label="Payout details" wire:model="influencerForm.payout_details" id="inf-payout-details" placeholder="M-Pesa number and name, or bank and account" />
        </div>
        <div class="grid gap-1">
            <label for="inf-notes" class="text-xs font-medium text-ink-muted">Notes</label>
            <textarea id="inf-notes" wire:model="influencerForm.notes" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
        </div>
        <label class="flex items-center gap-2 text-[13px] text-ink-muted">
            <input type="checkbox" wire:model="influencerForm.is_active" class="size-4 accent-[var(--tl-brand)]"> Active
        </label>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button type="submit" form="influencer-form">Save</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
