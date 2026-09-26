<div class="grid gap-5">
    <div class="grid gap-2">
        <h2 class="text-xl font-bold tracking-tight text-ink">Sign in</h2>
        <p class="text-sm text-ink-muted">Welcome back. Use the email your invitation was sent to.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-success/30 bg-success-soft px-4 py-3 text-sm text-success">{{ session('status') }}</div>
    @endif

    <form wire:submit="login" class="grid gap-4">
        <x-ui.input label="Email" type="email" wire:model="email" autocomplete="email" autofocus required />

        <div class="grid gap-1.5">
            <x-ui.input label="Password" type="password" wire:model="password" autocomplete="current-password" required />
            <a href="{{ route('password.request') }}" wire:navigate class="justify-self-end text-[13px] font-semibold text-brand-text hover:underline">Forgot your password?</a>
        </div>

        <label class="flex items-center gap-2.5 text-sm text-ink-muted">
            <input type="checkbox" wire:model="remember" id="remember" class="size-4 rounded border-line-strong accent-[var(--tl-brand)]">
            Keep me signed in on this device
        </label>

        <x-ui.button type="submit" size="lg" class="w-full">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </x-ui.button>
    </form>

    <p class="text-[13px] text-ink-subtle">No account? Ask your Sales Admin or Sales Manager to invite you.</p>
</div>
