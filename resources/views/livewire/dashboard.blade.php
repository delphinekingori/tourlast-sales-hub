@php
    $canAssign = auth()->user()->can('manage-users');

    // What needs a person now. Hidden when the count is zero.
    $attention = array_values(array_filter([
        ['count' => $overview['stalled'], 'one' => 'signup stuck waiting for approval', 'many' => 'signups stuck waiting for approval', 'url' => $canSeePartnerRegister ? route('partners.index') : null, 'icon' => 'clock'],
        ['count' => $claimsWaiting, 'one' => 'claim waiting for your approval', 'many' => 'claims waiting for your approval', 'url' => route('claims.approvals'), 'icon' => 'clipboard'],
        ['count' => $claimsToPay, 'one' => 'transport request to pay out', 'many' => 'transport requests to pay out', 'url' => route('claims.approvals', ['tab' => 'disburse']), 'icon' => 'wallet'],
        ['count' => $canAssign ? $unattributed : 0, 'one' => 'signup without a referral code', 'many' => 'signups without a referral code', 'url' => route('onboardings.unattributed'), 'icon' => 'alert'],
        ['count' => $pendingInvitations, 'one' => 'invitation not accepted yet', 'many' => 'invitations not accepted yet', 'url' => route('team.index'), 'icon' => 'users'],
    ], fn (array $item): bool => $item['count'] > 0 && $item['url']));
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

    {{-- Headline numbers --}}
    @php($delta = $overview['delta'])
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-3 xl:grid-cols-6">
        <x-ui.kpi size="lg" label="Went live this month" :value="number_format($overview['month'])" :hint="$delta === 0 ? 'Same as last month' : ($delta > 0 ? '▲ '.$delta.' more' : '▼ '.abs($delta).' fewer').' than last month'" :tone="$delta < 0 ? 'text-warning' : 'text-ink'" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Partners live in total" :value="number_format($overview['live'])" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Not live yet" :value="number_format($awaiting)" hint="Signed up, in review or approved" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Stuck for too long" :value="number_format($overview['stalled'])" :hint="'Waiting more than '.config('hub.stalled_after_days').' days'" :tone="$overview['stalled'] ? 'text-warning' : 'text-ink'" :href="$canSeePartnerRegister ? route('partners.index') : null" />
        <x-ui.kpi size="lg" label="Open leads" :value="number_format($overview['openLeads'])" :hint="number_format($overview['newLeads']).' new this month'" />
        <x-ui.kpi size="lg" label="Without a referral code" :value="number_format($unattributed)" hint="Need assigning" :tone="$unattributed ? 'text-warning' : 'text-ink'" :href="$canAssign ? route('onboardings.unattributed') : null" />
    </dl>

    {{-- Trend and funnel --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Partners going live" description="Last 6 months, by the month they went live">
            <x-chart.columns :points="$overview['trend']" label="Partners that went live per month" empty="No partners went live in the last 6 months."
                :series="[['key' => 'onboarded', 'name' => 'Went live', 'slot' => 1]]" />
        </x-ui.card>

        <x-ui.card title="Where signups are now" description="Every onboarding by its current step">
            @php($funnelMax = max(1, max($overview['funnel'])))
            <ul class="grid gap-3">
                @foreach ($overview['funnel'] as $step => $count)
                    @php($pct = (int) round($count / $funnelMax * 100))
                    <li class="grid gap-1">
                        <div class="flex items-baseline justify-between gap-2 text-[13px]">
                            <span class="font-medium text-ink">{{ $step }}</span>
                            <span class="tabular font-bold text-ink">{{ number_format($count) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $step }}">
                            <div class="h-full rounded-full {{ $loop->last ? 'bg-success' : 'bg-brand' }}" style="width: {{ $pct }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-2">
        {{-- Top salespeople --}}
        <x-ui.card title="Top salespeople this month" description="By partners that went live" :padding="false">
            @if ($topSalespeople->isEmpty())
                <p class="px-4 py-3 text-[13px] text-ink-muted">No partner has gone live this month yet.</p>
            @else
                <ol class="divide-y divide-line">
                    @foreach ($topSalespeople as $row)
                        <li wire:key="top-{{ $row['user']->id }}">
                            @php($url = $canSeePerformance ? route('team.member', $row['user']) : null)
                            <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" wire:navigate @endif class="flex min-h-12 items-center gap-3 px-4 py-2 {{ $url ? 'hover:bg-surface-muted/50' : '' }}">
                                <span class="tabular w-5 text-xs font-medium text-ink-subtle">{{ $loop->iteration }}</span>
                                <x-ui.avatar :user="$row['user']" size="sm" />
                                <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink">{{ $row['user']->name }}</span>
                                <span class="tabular text-sm font-bold text-ink">{{ number_format($row['onboarded']) }}</span>
                            </{{ $url ? 'a' : 'div' }}>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>

        <div class="grid gap-4">
            @if ($money)
                <x-ui.card title="Money waiting" description="Claims and this month's pay (KES)">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]">
                        <div><dt class="text-xs text-ink-subtle">Claims waiting</dt><dd class="tabular text-lg font-bold text-ink">{{ number_format($money['claimsWaiting']) }}</dd><dd class="tabular text-xs text-ink-muted">KES {{ number_format($money['claimsAmount']) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Pay approved, not paid</dt><dd class="tabular text-lg font-bold {{ $money['statementsToPay'] > 0 ? 'text-warning' : 'text-ink' }}">KES {{ number_format($money['statementsToPay']) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Paid this month</dt><dd class="tabular text-lg font-bold text-success">KES {{ number_format($money['statementsPaid']) }}</dd></div>
                    </dl>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" :href="route('claims.approvals')" wire:navigate>Open claims</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" :href="route('payouts.index')" wire:navigate>Open payouts</x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            @if ($travel)
                <x-ui.card title="Travel this month" description="Flights are in USD and tours in KES, so they are shown apart">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]">
                        <div><dt class="text-xs text-ink-subtle">Flight bookings</dt><dd class="tabular text-lg font-bold text-ink">{{ number_format($travel['flightBookings']) }}</dd><dd class="tabular text-xs text-ink-muted">USD {{ number_format($travel['flightRevenue']) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Tour bookings</dt><dd class="tabular text-lg font-bold text-ink">{{ number_format($travel['tourBookings']) }}</dd><dd class="tabular text-xs text-ink-muted">KES {{ number_format($travel['tourRevenue']) }}</dd></div>
                    </dl>
                    <div class="mt-3"><x-ui.button size="sm" variant="secondary" :href="route('travel.dashboard')" wire:navigate>Open travel dashboard</x-ui.button></div>
                </x-ui.card>
            @endif
        </div>
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
