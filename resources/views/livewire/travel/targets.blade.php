<div class="grid gap-5">
    <x-ui.page-header eyebrow="Travel Sales" title="Travel targets" description="Monthly flight and tour targets for each travel salesperson. Set by Sales Admin; progress updates from bookings as they come in.">
        <x-slot:actions>
            <select wire:model.live="month" id="travel-targets-month" aria-label="Month" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($editable)
        <div class="flex items-center gap-3 rounded-xl border border-line bg-surface px-5 py-3 text-sm text-ink-muted">
            <x-ui.icon name="lock" class="size-5 text-brand-text" />
            Targets for {{ $monthDate->format('F Y') }} can no longer be changed.
        </div>
    @endunless

    <x-ui.table-card>
        <table class="w-full min-w-[960px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Travel salesperson</th>
                    @foreach ($metrics as $metric)
                        <th class="text-left">{{ $metric->label() }}</th>
                    @endforeach
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($people as $person)
                    <tr wire:key="travel-target-{{ $person->id }}">
                        <td>
                            <div class="flex items-center gap-3">
                                <x-ui.avatar :user="$person" size="sm" />
                                <span class="font-semibold text-ink">{{ $person->name }}</span>
                            </div>
                        </td>
                        @foreach ($metrics as $metric)
                            @php
                                $actual = (float) ($actuals[$person->id][$metric->value] ?? 0);
                                $target = (int) ($values[$person->id][$metric->value] ?? 0);
                                $pct = $target > 0 ? min(100, (int) round($actual / $target * 100)) : null;
                            @endphp
                            <td>
                                <div class="grid gap-1.5">
                                    <input type="number" min="0" step="1" inputmode="numeric"
                                        wire:model="values.{{ $person->id }}.{{ $metric->value }}"
                                        @disabled(! $editable)
                                        aria-label="{{ $metric->label() }} target for {{ $person->name }}"
                                        placeholder="No target"
                                        class="h-8 w-32 rounded-md border border-line-strong bg-surface px-2 text-[13px] tabular text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none disabled:bg-surface-muted" />
                                    <div class="flex items-center gap-2 text-xs text-ink-muted">
                                        <span class="tabular">{{ $metric->isMoney() ? 'KES '.number_format($actual) : number_format($actual) }} so far</span>
                                        @if ($pct !== null)
                                            <div class="h-1.5 w-14 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                            <span class="tabular">{{ $pct }}%</span>
                                        @endif
                                    </div>
                                    @error("values.{$person->id}.{$metric->value}")<p class="text-xs text-danger">{{ $message }}</p>@enderror
                                </div>
                            </td>
                        @endforeach
                        <td class="text-right">
                            @if ($editable)
                                <x-ui.button size="sm" variant="secondary" wire:click="save({{ $person->id }})" wire:loading.attr="disabled">Save</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($metrics) + 2 }}"><x-ui.empty-state title="No travel salespeople yet" description="Invite one from Users & Invites with the Travel Salesperson role." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
