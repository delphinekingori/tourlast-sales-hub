<div class="grid gap-5">
    <div class="grid gap-2">
        <h2 class="text-xl font-bold tracking-tight text-ink">Reset your password</h2>
        <p class="text-sm text-ink-muted">Enter your work email and we'll send you a link to choose a new password.</p>
    </div>

    @if ($sent)
        <div class="grid gap-2 rounded-xl border border-line bg-surface p-5">
            <p class="flex items-center gap-2 text-sm font-bold text-ink"><x-ui.icon name="mail" class="size-5 text-brand-text" /> Check your inbox</p>
            <p class="text-sm text-ink-muted">If <span class="font-semibold text-ink">{{ $email }}</span> belongs to an active account, a reset link is on its way. It expires in 60 minutes.</p>
        </div>
    @else
        <form wire:submit="sendResetLink" class="grid gap-4">
            <x-ui.input label="Email" type="email" wire:model="email" autocomplete="email" autofocus required />
            <x-ui.button type="submit" size="lg" class="w-full">
                <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                <span wire:loading wire:target="sendResetLink">Sending…</span>
            </x-ui.button>
        </form>
    @endif

    <a href="{{ route('login') }}" wire:navigate class="text-[13px] font-semibold text-brand-text hover:underline">← Back to sign in</a>
</div>
