<div class="grid gap-5">
    <x-ui.page-header eyebrow="Admin" title="Incentives" description="Incentives are paid only to salespeople with an agreement in force. Ending an agreement also ends 90-day expansion points for that person's Accounts.">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="openAgreement">New agreement</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($withoutAgreement->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-warning/30 bg-warning-soft px-4 py-2.5 text-[13px]">
            <x-ui.icon name="alert" class="size-5 text-warning" />
            <span class="text-ink">No agreement today:</span>
            @foreach ($withoutAgreement as $person)
                <x-ui.pill tone="warning" :dot="false">{{ $person->name }}</x-ui.pill>
            @endforeach
        </div>
    @endif

    <x-ui.table-card>
        <table class="w-full min-w-[640px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr><th class="text-left">Salesperson</th><th class="text-left">Starts</th><th class="text-left">Ends</th><th class="text-left">Status</th><th class="text-left">Notes</th><th class="text-left"></th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($agreements as $item)
                    <tr wire:key="agr-{{ $item->id }}">
                        <td class="font-semibold text-ink">{{ $item->user->name }}</td>
                        <td class="text-ink-muted">{{ $item->starts_on->format('j M Y') }}</td>
                        <td class="text-ink-muted">{{ $item->ends_on?->format('j M Y') ?? 'Open-ended' }}</td>
                        <td>
                            @if ($item->covers(now()))<x-ui.pill tone="success">In force</x-ui.pill>@elseif ($item->starts_on->isFuture())<x-ui.pill tone="brand">Starts later</x-ui.pill>@else<x-ui.pill>Ended</x-ui.pill>@endif
                        </td>
                        <td class="text-ink-muted">{{ $item->notes ?? '—' }}</td>
                        <td class="text-right"><x-ui.button size="sm" variant="ghost" wire:click="openAgreement({{ $item->id }})">Edit</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="clipboard" title="No agreements yet" description="Add one for each salesperson on the incentive scheme." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.card :title="$policyModel->name" :description="'In force since '.$policyModel->effective_from->format('j M Y').'. A new version never changes months that are already paid.'">
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="grid content-start gap-2">
                <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Stay points (rooms/units)</h3>
                @foreach ($rules['stay_bands'] as [$min, $max, $points])
                    <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">{{ $min }}{{ $max ? '–'.$max : '+' }}</span><span class="font-bold text-ink">{{ $points }}</span></div>
                @endforeach
                <h3 class="mt-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Experience points (services)</h3>
                @foreach ($rules['experience_bands'] as [$min, $max, $points])
                    <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">{{ $min }}{{ $max ? '–'.$max : '+' }}</span><span class="font-bold text-ink">{{ $points }}</span></div>
                @endforeach
            </div>
            <div class="grid content-start gap-2">
                <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Performance Retainer</h3>
                <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">Under {{ $rules['retainer'][0][0] }} points</span><span class="font-bold text-ink">0</span></div>
                @foreach ($rules['retainer'] as [$threshold, $amount])
                    <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">{{ $threshold }}+ points</span><span class="font-bold text-ink">KES {{ number_format($amount) }}</span></div>
                @endforeach
                <h3 class="mt-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Weekly bonus</h3>
                <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">{{ $rules['weekly_bonus']['threshold'] }}+ points in a bonus week</span><span class="font-bold text-ink">KES {{ number_format($rules['weekly_bonus']['amount']) }}</span></div>
                <p class="text-xs text-ink-subtle">Weeks: {{ collect($rules['bonus_weeks'])->map(fn ($w) => $w[0].'–'.($w[1] >= 28 ? 'end' : $w[1]))->implode(', ') }}</p>
                <h3 class="mt-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Allowances</h3>
                <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">Airtime per month</span><span class="font-bold text-ink">KES {{ number_format($rules['airtime_cap']) }}</span></div>
                <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">Transport</span><span class="font-bold text-ink">Approved claims</span></div>
            </div>
            <div class="grid content-start gap-2">
                <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Monthly Performance Bonus</h3>
                <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">Above 0 points</span><span class="font-bold text-ink">KES {{ number_format($rules['monthly_bonus']['above_zero']) }}</span></div>
                @foreach ($rules['monthly_bonus']['bands'] as [$threshold, $amount])
                    <div class="flex justify-between border-b border-line py-1.5 text-sm"><span class="text-ink-muted">{{ $threshold }}+ points</span><span class="font-bold text-ink">KES {{ number_format($amount) }}</span></div>
                @endforeach
                <h3 class="mt-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Exceptional-Performance</h3>
                <p class="text-sm text-ink-muted">KES {{ $rules['exceptional']['per_point'] }} per point above {{ $rules['exceptional']['above'] }}, capped at KES {{ number_format($rules['exceptional']['cap']) }}.</p>
                <h3 class="mt-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Windows</h3>
                <p class="text-sm text-ink-muted">Quality review {{ $rules['review_days'] }} days · expansion {{ $rules['expansion_days'] }} days · paid by day {{ $rules['payment_day'] }}.</p>
            </div>
        </div>
    </x-ui.card>

    <x-ui.slide-over wire:model="showAgreement" :title="$editingId ? 'Edit agreement' : 'New agreement'" description="Incentives are earned only within these dates.">
        <form id="agreement-form" wire:submit="saveAgreement" class="grid gap-4">
            <x-ui.select label="Salesperson" wire:model="agreement.user_id" id="agreement-user">
                <option value="">Choose…</option>
                @foreach ($sellers as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Starts on" type="date" wire:model="agreement.starts_on" id="agreement-start" />
            <x-ui.input label="Ends on" type="date" wire:model="agreement.ends_on" id="agreement-end" hint="Leave empty for an open-ended agreement." />
            <x-ui.input label="Notes" wire:model="agreement.notes" id="agreement-notes" placeholder="e.g. Signed 1 Sep 2026, Schedule 1 attached" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="agreement-form">Save agreement</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>
</div>
