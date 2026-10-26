@php
    $hasAnyTarget = fn ($person): bool => collect($metrics)->contains(fn ($metric) => (int) ($values[$person->id][$metric->value] ?? 0) > 0);
    $withoutTargets = $people->reject($hasAnyTarget)->values();
    $teamTotal = fn ($metric, $source): float => (float) $people->sum(fn ($person) => (float) (data_get($source, $person->id.".".$metric->value) ?? 0));
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Travel Sales" title="Travel targets" description="Monthly flight and tour targets for each travel salesperson. Set by Sales Admin; progress updates from bookings as they come in." />

    <p class="text-sm text-ink-muted">
        @if ($people->isEmpty())
            No travel salespeople to show yet.
        @elseif ($withoutTargets->isNotEmpty())
            <span class="font-semibold text-warning">{{ $withoutTargets->count() }} of {{ $people->count() }} {{ \Illuminate\Support\Str::plural('salesperson', $people->count()) }} {{ $withoutTargets->count() === 1 ? 'has' : 'have' }} no target for {{ $monthDate->format('F Y') }}.</span>
        @else
            <span class="font-semibold text-success">Everyone has a target for {{ $monthDate->format('F Y') }}.</span>
        @endif
        Flights are in USD and tours in KES, so the two are never added together.
    </p>

    @if ($people->isNotEmpty())
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4" wire:loading.delay.class="opacity-60">
            @foreach ($metrics as $metric)
                @php
                    $teamActual = $teamTotal($metric, $actuals);
                    $teamTarget = $teamTotal($metric, $values);
                    $format = fn (float $amount): string => $metric->isMoney() ? $metric->currency().' '.number_format($amount) : number_format($amount);
                @endphp
                <x-ui.kpi size="lg" :label="$metric->label().' (team)'" :value="$format($teamActual)" :hint="$teamTarget > 0 ? 'of '.$format($teamTarget).' target' : 'No target set'" />
            @endforeach
        </dl>
    @endif

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <label for="travel-targets-month" class="text-sm font-medium text-ink-muted">Month</label>
        <select wire:model.live="month" id="travel-targets-month" aria-label="Month" class="h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-sm font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none sm:w-auto">
            @foreach ($monthOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @unless ($editable)
        <div class="flex items-center gap-3 rounded-xl border border-line bg-surface px-5 py-3 text-sm text-ink-muted">
            <x-ui.icon name="lock" class="size-5 text-brand-text" />
            Targets for {{ $monthDate->format('F Y') }} can no longer be changed.
        </div>
    @endunless

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($withoutTargets->isNotEmpty())
            <x-ui.card title="Needs attention" description="No target set for this month.">
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($withoutTargets as $person)
                        <li wire:key="travel-target-attn-{{ $person->id }}">
                            <a href="#travel-target-card-{{ $person->id }}" class="flex min-h-11 items-center gap-2.5 py-2">
                                <x-ui.avatar :user="$person" size="sm" />
                                <span class="truncate text-sm font-semibold text-ink hover:text-brand-text">{{ $person->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <div class="grid items-start gap-4 lg:grid-cols-2">
            @forelse ($people as $person)
                <section id="travel-target-card-{{ $person->id }}" wire:key="travel-target-{{ $person->id }}" class="grid scroll-mt-20 gap-4 rounded-xl border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex min-w-0 items-center gap-3">
                            <x-ui.avatar :user="$person" size="sm" />
                            <h2 class="truncate font-semibold text-ink">{{ $person->name }}</h2>
                        </span>
                        @if ($editable)
                            <x-ui.button size="sm" variant="secondary" class="min-h-10" wire:click="save({{ $person->id }})" wire:loading.attr="disabled">Save</x-ui.button>
                        @endif
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($metrics as $metric)
                            @php
                                $actual = (float) ($actuals[$person->id][$metric->value] ?? 0);
                                $target = (int) ($values[$person->id][$metric->value] ?? 0);
                                $pct = $target > 0 ? min(100, (int) round($actual / $target * 100)) : null;
                                $inputId = 'travel-target-'.$person->id.'-'.$metric->value;
                            @endphp
                            <div class="grid content-start gap-1.5">
                                <label for="{{ $inputId }}" class="text-xs font-medium text-ink-subtle">{{ $metric->label() }}</label>
                                <input id="{{ $inputId }}" type="number" min="0" step="1" inputmode="numeric"
                                    wire:model="values.{{ $person->id }}.{{ $metric->value }}"
                                    @disabled(! $editable)
                                    aria-label="{{ $metric->label() }} target for {{ $person->name }}"
                                    placeholder="No target"
                                    class="h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-sm tabular text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none disabled:bg-surface-muted" />
                                <p class="tabular text-xs text-ink-muted">{{ $metric->isMoney() ? $metric->currency().' '.number_format($actual) : number_format($actual) }} so far</p>
                                @if ($pct !== null)
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="{{ $metric->label() }} progress for {{ $person->name }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                        <span class="tabular text-xs text-ink-muted">{{ $pct }}%</span>
                                    </div>
                                @endif
                                @error("values.{$person->id}.{$metric->value}")<p class="text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="lg:col-span-2"><x-ui.empty-state title="No travel salespeople yet" description="Invite one from Users & Invites with the Travel Salesperson role." /></div>
            @endforelse
        </div>
    </div>
</div>
