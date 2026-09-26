<div class="grid gap-5" wire:poll.60s>
    <a href="{{ route('people.index') }}" wire:navigate class="text-[13px] font-semibold text-brand-text hover:underline">← People</a>

    <x-ui.card>
        <div class="flex flex-wrap items-start gap-6">
            <x-ui.avatar :user="$person" size="xl" presence />
            <div class="grid min-w-0 flex-1 gap-1.5">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-bold tracking-tight text-ink">{{ $person->name }}</h1>
                    <x-ui.presence :user="$person" />
                    @if ($person->accountStatus() !== \App\Enums\AccountStatus::Active)
                        <x-ui.pill :tone="$person->accountStatus()->tone()">{{ $person->accountStatus()->label() }}{{ $person->suspended_until ? ' until '.$person->suspended_until->format('j M Y') : '' }}</x-ui.pill>
                    @endif
                </div>
                <p class="text-sm text-ink-muted">{{ $person->job_title ?? 'Position not set' }} · {{ $person->role()?->label() }}{{ $person->region ? ' · '.$person->region : '' }}</p>
                @if ($person->bio)
                    <p class="mt-1 max-w-2xl text-sm text-ink">{{ $person->bio }}</p>
                @endif
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($canSeePerformance)
                        <x-ui.button size="sm" variant="secondary" icon="chart" :href="route('team.member', $person)" wire:navigate>Progress</x-ui.button>
                    @endif
                    @if ($canSeeEarnings)
                        <x-ui.button size="sm" variant="secondary" icon="target" :href="route('earnings.member', $person)" wire:navigate>Earnings</x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Contact">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Email</dt><dd class="text-ink select-all">{{ $person->email }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Phone</dt><dd class="text-ink select-all">{{ $person->phone ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Emergency contact</dt><dd class="text-right text-ink">{{ $person->emergency_contact_name ? $person->emergency_contact_name.' · '.$person->emergency_contact_phone : '—' }}</dd></div>
            </dl>
        </x-ui.card>
        <x-ui.card title="In the Hub">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Role</dt><dd class="text-ink">{{ $person->role()?->label() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Joined</dt><dd class="text-ink">{{ $person->created_at->format('j M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Last sign-in</dt><dd class="text-ink">{{ $person->last_login_at?->diffForHumans() ?? 'Never' }}</dd></div>
                @if ($person->referralCode)
                    <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Referral code</dt><dd class="font-mono text-brand-text">{{ $person->referralCode->code }}</dd></div>
                @endif
            </dl>
        </x-ui.card>
    </div>
    @if ($person->statusChanges->isNotEmpty())
        <x-ui.card title="Account history" :padding="false">
            @foreach ($person->statusChanges as $change)
                <div wire:key="usc-{{ $change->id }}" class="grid gap-0.5 border-b border-line px-4 py-2.5 text-[13px] last:border-b-0">
                    <span class="text-ink"><span class="text-ink-muted">{{ $change->from_status->label() }}</span> → <span class="font-semibold">{{ $change->to_status->label() }}</span>{{ $change->suspended_until ? ' until '.$change->suspended_until->format('j M Y') : '' }}</span>
                    <span class="text-xs text-ink-muted">{{ $change->created_at->format('j M Y, H:i') }}{{ $change->reasonLabel() ? ' · '.$change->reasonLabel() : '' }} · {{ $change->changer ? 'by '.$change->changer->name : 'automatically' }}</span>
                    @if ($change->notes)<span class="text-xs text-ink-subtle">“{{ $change->notes }}”</span>@endif
                </div>
            @endforeach
        </x-ui.card>
    @endif
</div>