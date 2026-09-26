<div class="grid gap-5">
    <x-ui.page-header eyebrow="Home" :title="$greeting.', '.$user->firstName()" description="A quick view of partner onboarding across the team." />

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Onboarded this month" :value="$monthOnboarded" hint="Went live on tourlast.com" />
        <x-ui.stat label="Not live yet" :value="$awaiting" hint="Signed up, in review or approved" />
        <x-ui.stat label="Without a referral code" :value="$unattributed" hint="Need assigning to a salesperson" />
    </div>

    <div class="flex flex-wrap gap-2">
        @if ($canSeePerformance)
            <x-ui.button :href="route('team.performance')" icon="users" wire:navigate>Team Performance</x-ui.button>
        @endif
        @if ($canSeePartnerRegister)
            <x-ui.button :href="route('partners.index')" variant="secondary" icon="register" wire:navigate>Partner Register</x-ui.button>
        @endif
        @can('manage-users')
            @if ($unattributed)
                <x-ui.button :href="route('onboardings.unattributed')" variant="secondary" icon="alert" wire:navigate>Assign {{ $unattributed }} unattributed</x-ui.button>
            @endif
        @endcan
    </div>

    @if ($canSeeTeam)
        <x-ui.card title="Team">
            <x-slot:actions>
                @if ($pendingInvitations)
                    <x-ui.pill tone="warning">{{ $pendingInvitations }} pending {{ \Illuminate\Support\Str::plural('invitation', $pendingInvitations) }}</x-ui.pill>
                @endif
                <x-ui.button :href="route('team.index')" size="sm" variant="secondary" wire:navigate>Manage team</x-ui.button>
            </x-slot:actions>

            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($teamCounts as $label => $count)
                    <div class="grid gap-1 bg-surface px-4 py-3">
                        <dt class="text-xs font-semibold text-ink-subtle">{{ $label }}</dt>
                        <dd class="tabular text-xl font-bold tracking-tight text-ink">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>
    @endif
</div>
