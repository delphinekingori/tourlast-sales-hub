<x-ui.modal wire:model="showPublish" title="Mark as published" description="Record where this approved package is now on sale. You publish it on that channel yourself.">
    <form wire:submit="confirmPublish" id="publish-form" class="grid gap-3">
        @error('package')
            <p class="rounded-md bg-danger-soft px-3 py-2 text-[13px] text-danger">{{ $message }}</p>
        @enderror
        @if ($publishMissing !== [])
            <div class="grid gap-1 rounded-md border border-danger/30 bg-danger-soft/50 px-3 py-2 text-[13px]">
                <p class="font-semibold text-danger">Cannot publish package. Missing:</p>
                <ul class="list-disc pl-5 text-ink">
                    @foreach ($publishMissing as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
                @if ($this->canOverrideContract())
                    <p class="text-xs text-ink-muted">As a Super Admin you can publish anyway with a written reason. It is recorded in the audit log.</p>
                @endif
            </div>
        @endif
        <x-ui.input label="Channel" wire:model="publish.channel" placeholder="tourlast.com, Instagram, partner site…" list="publish-channels" />
        <datalist id="publish-channels">
            <option value="tourlast.com"></option>
            <option value="Instagram"></option>
            <option value="Facebook"></option>
            <option value="TikTok"></option>
            <option value="Partner site"></option>
            <option value="WhatsApp catalogue"></option>
        </datalist>
        <x-ui.input label="Link (optional)" type="url" wire:model="publish.url" placeholder="https://" />
        @if ($publishMissing !== [] && $this->canOverrideContract())
            <div class="grid gap-1">
                <label for="publish-override" class="text-xs font-medium text-ink-muted">Override reason</label>
                <textarea id="publish-override" wire:model="publish.override" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink"></textarea>
            </div>
        @endif
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button type="submit" form="publish-form" :disabled="$publishMissing !== [] && ! $this->canOverrideContract()">Mark as published</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal wire:model="showUnpublish" title="Unpublish package" description="It stays approved and can be published again later.">
    <div class="grid gap-1">
        <label for="unpublish-reason" class="text-xs font-medium text-ink-muted">Reason (optional)</label>
        <textarea id="unpublish-reason" wire:model="unpublishReason" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink"></textarea>
    </div>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button variant="danger" wire:click="confirmUnpublish">Unpublish</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal wire:model="showDuplicate" title="Duplicate package" description="Copies descriptions, itinerary, inclusions, exclusions, provider and media into a new draft. Prices, capacity, driver, guide and contract must be confirmed.">
    <form wire:submit="confirmDuplicate" id="duplicate-form" class="grid gap-3">
        <x-ui.input label="Name of the new package" wire:model.live.debounce.400ms="duplicateName" />
        @if ($duplicateMatches->isNotEmpty())
            <div class="grid gap-1.5 rounded-md border border-warning/40 bg-warning-soft/50 px-3 py-2 text-[13px]">
                <p class="font-semibold text-ink">Possible duplicate package found</p>
                @foreach ($duplicateMatches->take(3) as $match)
                    <a href="{{ route('travel.packages.show', $match['package']) }}" target="_blank" class="text-brand-text hover:underline">{{ $match['package']->name }} ({{ $match['package']->reference }}) · {{ $match['reason'] }}</a>
                @endforeach
                @if (! $duplicateMatches->contains('exact', true) || \App\Support\Travel\TravelAccess::managesAll(auth()->user()))
                    <label class="mt-1 flex items-center gap-2 text-ink">
                        <input type="checkbox" wire:model="duplicateAccepted" class="rounded border-line-strong">
                        Continue anyway: this is a different package
                    </label>
                @endif
            </div>
        @endif
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button type="submit" form="duplicate-form">Create copy</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
