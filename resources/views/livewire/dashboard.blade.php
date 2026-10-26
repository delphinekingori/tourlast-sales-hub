@php
    $canAssign = auth()->user()->can('manage-users');

    // What needs a person now. Hidden when the count is zero.
    $attention = array_values(array_filter([
        ['count' => $claimsWaiting, 'one' => 'claim waiting for your approval', 'many' => 'claims waiting for your approval', 'url' => route('claims.approvals'), 'icon' => 'clipboard'],
        ['count' => $claimsToPay, 'one' => 'transport request to pay out', 'many' => 'transport requests to pay out', 'url' => route('claims.approvals', ['tab' => 'disburse']), 'icon' => 'wallet'],
        ['count' => $canAssign ? $unattributed : 0, 'one' => 'signup without a referral code', 'many' => 'signups without a referral code', 'url' => route('onboardings.unattributed'), 'icon' => 'alert'],
        ['count' => $pendingInvitations, 'one' => 'invitation not accepted yet', 'many' => 'invitations not accepted yet', 'url' => route('team.index'), 'icon' => 'users'],
    ], fn (array $item): bool => $item['count'] > 0));
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Home" :title="$greeting.', '.$user->firstName()" description="A quick view of partner onboarding across the team." />

    {{-- Needs you now --}}
    <section aria-labelledby="needs-you" class="grid gap-2">
        <h2 id="needs-you" class="text-[13px] font-semibold text-ink">Needs you now</h2>
        @if ($attention === [])
            <p class="flex items-center gap-2 rounded-xl border border-line bg-surface px-4 py-3 text-[13px] text-ink-muted shadow-card">
                <x-ui.icon name="check-circle" class="size-4 text-success" /> You are all caught up. Nothing is waiting on you.
            </p>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach ($attention as $item)
                    <li>
                        <a href="{{ $item['url'] }}" wire:navigate class="inline-flex min-h-10 items-center gap-2 rounded-full border border-warning/40 bg-warning-soft/50 px-3.5 py-1.5 text-[13px] text-ink hover:bg-warning-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                            <x-ui.icon :name="$item['icon']" class="size-4 text-warning" />
                            <span><strong class="tabular font-bold">{{ number_format($item['count']) }}</strong> {{ $item['count'] === 1 ? $item['one'] : $item['many'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3">
        <x-ui.kpi size="lg" label="Onboarded this month" :value="number_format($monthOnboarded)" hint="Went live on tourlast.com" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Not live yet" :value="number_format($awaiting)" hint="Signed up, in review or approved" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Without a referral code" :value="number_format($unattributed)" hint="Need assigning to a salesperson" :tone="$unattributed ? 'text-warning' : 'text-ink'" :href="$canAssign ? route('onboardings.unattributed') : null" />
    </dl>

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

            <details class="group rounded-xl border border-line bg-surface">
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-4 py-2 text-[13px] font-medium text-ink select-none">
                    More numbers: people on the team
                    <svg class="size-4 text-ink-subtle transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-b-xl border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($teamCounts as $label => $count)
                        <x-ui.kpi :label="$label" :value="number_format($count)" :href="route('team.index')" />
                    @endforeach
                </dl>
            </details>
        </x-ui.card>
    @endif
</div>
