@php
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
@endphp

<div class="grid gap-6">
    <x-ui.page-header
        :eyebrow="$seesAll ? 'Incentives' : 'Me'"
        :title="$seesAll ? 'Partner Accounts' : 'My Accounts'"
        description="Each legal business on tourlast.com is one Account. All its properties share one set of points, based on verified rooms, units or services."
    />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap rounded-md border border-line bg-surface p-0.5">
            @foreach (['all' => 'All', 'verify' => 'Needs verification', 'review' => 'In 14-day review', 'expansion' => 'Expansion window', 'failed' => 'Failed review'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" wire:key="acc-tab-{{ $key }}"
                    @class(['flex items-center gap-2 rounded px-2.5 py-1 text-xs font-medium', 'bg-brand-soft text-brand-text' => $tab === $key, 'text-ink-subtle hover:text-ink' => $tab !== $key])>
                    {{ $label }} <span class="tabular text-xs opacity-70">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($seesAll)
                <select wire:model.live="owner" id="acc-owner" aria-label="Salesperson" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                    <option value="">Everyone</option>
                    @foreach ($owners as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            @endif
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search legal name" />
        </div>
    </div>

    <x-ui.table-card :paginator="$accounts">
        <table class="w-full min-w-[920px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Account</th>
                    @if ($seesAll)<th class="text-left">Onboarded by</th>@endif
                    <th class="text-left">Inventory</th>
                    <th class="text-left">Activation</th>
                    <th class="text-left">Checklist</th>
                    <th class="text-left">Status</th>
                    <th class="text-right">Points</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($accounts as $account)
                    @php
                        $progress = $account->checklistProgress();
                        $review = $account->reviewStatus();
                    @endphp
                    <tr wire:key="acc-{{ $account->id }}" class="hover:bg-surface-muted/60">
                        <td>
                            <a href="{{ route('accounts.show', $account) }}" wire:navigate class="grid leading-tight">
                                <span class="font-semibold text-ink hover:text-brand-text">{{ $account->legal_name }}</span>
                                <span class="text-[13px] text-ink-subtle">{{ $account->categoryLabel() }} · {{ $account->onboardings->count() }} {{ \Illuminate\Support\Str::plural('property', $account->onboardings->count()) }}</span>
                            </a>
                        </td>
                        @if ($seesAll)<td class="text-ink-muted">{{ $account->user?->name ?? 'Unattributed' }}</td>@endif
                        <td class="tabular text-ink-muted">{{ $account->activation_inventory ? $account->activation_inventory.' '.strtolower($account->basisLabel()) : 'Not recorded' }}</td>
                        <td class="text-ink-muted">
                            @if ($account->activation_date)
                                {{ $account->activation_date->format('j M Y') }}
                                @if ($days = $account->expansionDaysLeft())<span class="block text-xs text-ink-subtle">expansion: {{ $days }} days left</span>@endif
                            @else
                                Not live yet
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-16 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($progress['done'] / $progress['total'] * 100) }}%"></div></div>
                                <span class="tabular text-xs text-ink-muted">{{ $progress['done'] }}/{{ $progress['total'] }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @if ($account->isVerified())<x-ui.pill tone="success">Verified</x-ui.pill>@elseif ($account->activation_date)<x-ui.pill tone="warning">Needs verification</x-ui.pill>@endif
                                @if ($review === 'in_review')<x-ui.pill tone="brand">In review</x-ui.pill>@endif
                                @if ($review === 'failed')<x-ui.pill tone="danger">Failed review</x-ui.pill>@endif
                                @if ($account->review_warning_at && $review === 'in_review')<x-ui.pill tone="danger" :dot="false">Warning</x-ui.pill>@endif
                            </div>
                        </td>
                        <td class="tabular text-right font-bold text-ink">{{ $pts($account->livePoints()) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $seesAll ? 7 : 6 }}"><x-ui.empty-state icon="building" title="No Accounts here" description="Accounts appear when a provider you referred signs up on tourlast.com." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
