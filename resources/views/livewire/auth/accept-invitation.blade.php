<div class="grid gap-5">
    @if ($status === $statuses::Pending)
        <div class="grid gap-2">
            <p class="text-xs font-bold tracking-[0.08em] text-brand-text uppercase">You're invited</p>
            <h2 class="text-xl font-bold tracking-tight text-ink">Welcome, {{ \Illuminate\Support\Str::before($invitation->name, ' ') }}</h2>
            <p class="text-sm text-ink-muted">{{ $invitation->inviter->name }} invited you to join as a <span class="font-semibold text-ink">{{ $invitation->role->label() }}</span>. Set a password to finish creating your account.</p>
        </div>

        <dl class="grid gap-3 rounded-xl border border-line bg-surface p-4 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Email</dt><dd class="font-semibold text-ink">{{ $invitation->email }}</dd></div>
            @if ($invitation->region)
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Region</dt><dd class="font-semibold text-ink">{{ $invitation->region }}</dd></div>
            @endif
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Link expires</dt><dd class="font-semibold text-ink">{{ $invitation->expires_at->format('j M Y') }}</dd></div>
        </dl>

        <form wire:submit="accept" class="grid gap-4">
            <x-ui.input label="Phone number" type="tel" wire:model="phone" autocomplete="tel" placeholder="+254 7…" required />
            <x-ui.input label="Password" type="password" wire:model="password" autocomplete="new-password" hint="At least 10 characters, with letters and numbers." required />
            <x-ui.input label="Confirm password" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
            <x-ui.button type="submit" size="lg" class="w-full">
                <span wire:loading.remove wire:target="accept">Create my account</span>
                <span wire:loading wire:target="accept">Creating your account…</span>
            </x-ui.button>
        </form>
    @else
        <div class="grid justify-items-start gap-4">
            <span class="grid size-12 place-items-center rounded-full bg-warning-soft text-warning"><x-ui.icon name="alert" class="size-6" /></span>
            <div class="grid gap-2">
                <h2 class="text-xl font-bold tracking-tight text-ink">
                    @switch($status)
                        @case($statuses::Accepted) This invitation has already been used @break
                        @case($statuses::Expired) This invitation has expired @break
                        @case($statuses::Revoked) This invitation was cancelled @break
                        @default This invitation link isn't valid
                    @endswitch
                </h2>
                <p class="text-sm text-ink-muted">
                    @if ($status === $statuses::Accepted)
                        Your account is already set up. Sign in with your email and password.
                    @else
                        Ask your Sales Admin or Sales Manager to send you a new invitation. Only the most recent link works.
                    @endif
                </p>
            </div>
            <x-ui.button :href="route('login')" variant="secondary">Go to sign in</x-ui.button>
        </div>
    @endif
</div>
