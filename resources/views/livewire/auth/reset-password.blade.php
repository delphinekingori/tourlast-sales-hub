<div class="grid gap-5">
    <div class="grid gap-2">
        <h2 class="text-xl font-bold tracking-tight text-ink">Choose a new password</h2>
        <p class="text-sm text-ink-muted">Use at least 10 characters, with letters and numbers.</p>
    </div>

    <form wire:submit="resetPassword" class="grid gap-4">
        <x-ui.input label="Email" type="email" wire:model="email" autocomplete="email" required />
        <x-ui.input label="New password" type="password" wire:model="password" autocomplete="new-password" required />
        <x-ui.input label="Confirm new password" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
        <x-ui.button type="submit" size="lg" class="w-full">Save new password</x-ui.button>
    </form>
</div>
