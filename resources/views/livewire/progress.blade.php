@php
    $target = $metrics['target'];
    $onboarded = $metrics['onboarded'];
    $points = $metrics['points'];
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    $ratio = $target ? min(1, $points / max(1, $target)) : 0;
    $circumference = 2 * M_PI * 42;
    $delta = $points - $metrics['previousPoints'];
    $remaining = $target ? max(0, $target - $points) : null;
    $daysLeft = (int) now()->diffInDays($range->to, false);
    $leadStatuses = \App\Enums\LeadStatus::cases();
    $pipelineMax = max(1, (int) collect($pipeline)->max());
@endphp

<div class="grid gap-5">
    <x-ui.page-header
        :title="$isOwn ? $greeting.', '.$subject->firstName() : $subject->name"
        :description="$isOwn
            ? now()->format('l, j F Y').' · your day, your pipeline and your referrals.'
            : ($subject->role()?->label().($subject->region ? ' · '.$subject->region : '').' · referral code '.$referralCode?->code)"
    >
        <x-slot:actions>
            @unless ($isOwn)
                <x-ui.button :href="route('team.performance')" variant="secondary" wire:navigate>← Team Performance</x-ui.button>
            @endunless
            @if ($isOwn)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-schedule')">Schedule</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Today --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Follow-ups due', $today['follow_ups'], 'clock', 'text-ink'],
            ['Meetings & visits', $today['meetings'], 'users', 'text-brand-text'],
            ['Overdue', $today['overdue'], 'alert', $today['overdue'] ? 'text-danger' : 'text-ink'],
            ['Onboardings in progress', $today['onboardings'], 'register', 'text-ink'],
        ] as [$label, $value, $icon, $tone])
            <div class="flex items-center gap-3 bg-surface px-4 py-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-surface-muted text-ink-muted"><x-ui.icon :name="$icon" class="size-4" /></span>
                <div class="grid leading-tight">
                    <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd class="tabular text-xl font-bold {{ $tone }}">{{ $value }}</dd>
                </div>
            </div>
        @endforeach
    </dl>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        {{-- Today's schedule --}}
        <x-ui.card :title="$isOwn ? 'Today\'s schedule' : 'Schedule today'" :description="now()->format('D j M')" :padding="false">
            <x-slot:actions>
                <x-ui.button :href="route('calendar.index', $isOwn ? [] : ['rep' => $subject->id])" variant="ghost" size="sm" icon="calendar" wire:navigate>Calendar</x-ui.button>
            </x-slot:actions>
            @forelse ($schedule as $item)
                <div wire:key="sch-{{ $item->id }}" @class(['grid grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0', 'opacity-60' => $item->isDone()])>
                    <span @class(['tabular text-[13px] font-bold', 'text-danger' => $item->isOverdue(), 'text-ink' => ! $item->isOverdue()])>
                        {{ $item->isOverdue() ? $item->due_at->format('j M') : ($item->has_time ? $item->due_at->format('H:i') : 'Anytime') }}
                    </span>
                    <span class="flex min-w-0 items-center gap-2.5">
                        <span @class(['grid size-7 shrink-0 place-items-center rounded-full', 'bg-brand-soft text-brand-text' => $item->isMeeting(), 'bg-surface-muted text-ink-muted' => ! $item->isMeeting()])><x-ui.icon :name="$item->type->icon()" class="size-3.5" /></span>
                        <span class="grid min-w-0 leading-tight">
                            <span @class(['truncate text-[13px] font-semibold text-ink', 'line-through' => $item->isDone()])>{{ $item->lead->business_name }}</span>
                            <span class="truncate text-xs text-ink-subtle">{{ $item->type->label() }} — {{ $item->task }}{{ $item->contact_name ? ' · '.$item->contact_name : '' }}{{ $item->contact_role ? ', '.$item->contact_role : '' }}</span>
                        </span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        @if ($item->isOverdue())<x-ui.pill tone="danger">Overdue</x-ui.pill>@endif
                        @if ($item->isDone())
                            <x-ui.pill tone="success">Done</x-ui.pill>
                        @elseif ($isOwn)
                            <x-ui.button size="sm" variant="secondary" icon="check" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }}, complete: true })">Done</x-ui.button>
                        @endif
                    </span>
                </div>
            @empty
                <x-ui.empty-state icon="calendar" title="Nothing scheduled today" :description="$isOwn ? 'Book a call, meeting or site visit with a property.' : null" />
                @if ($isOwn)
                    <div class="-mt-4 pb-6 text-center"><x-ui.button size="sm" icon="plus" x-on:click="$dispatch('open-schedule')">Schedule</x-ui.button></div>
                @endif
            @endforelse
        </x-ui.card>

        {{-- Sales pipeline --}}
        <x-ui.card :title="$isOwn ? 'My sales pipeline' : 'Sales pipeline'" :padding="false">
            <x-slot:actions>
                <x-ui.button :href="route('leads.index')" variant="ghost" size="sm" wire:navigate>Leads</x-ui.button>
            </x-slot:actions>
            <dl class="grid gap-2 p-4">
                @foreach ($leadStatuses as $status)
                    @php
                        $count = (int) ($pipeline[$status->value] ?? 0);
                    @endphp
                    <div class="grid grid-cols-[96px_1fr_32px] items-center gap-2 text-[13px]">
                        <dt><x-ui.pill :tone="$status->tone()">{{ $status->label() }}</x-ui.pill></dt>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-muted"><div @class(['h-full rounded-full', 'bg-success' => $status === \App\Enums\LeadStatus::Onboarded, 'bg-danger/60' => $status === \App\Enums\LeadStatus::Lost, 'bg-brand' => ! in_array($status, [\App\Enums\LeadStatus::Onboarded, \App\Enums\LeadStatus::Lost], true)]) style="width: {{ round($count / $pipelineMax * 100) }}%"></div></div>
                        <dd class="tabular text-right font-medium text-ink">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
        <h2 class="text-sm font-semibold text-ink">{{ $isOwn ? 'My performance' : 'Performance' }}</h2>
        <x-ui.segmented wire:model.live="period" :options="\App\Support\Period::options()" />
    </div>

    <div class="grid gap-5 transition-opacity" wire:loading.delay.class="pointer-events-none opacity-50" wire:target="period">
        {{-- KPIs --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <section class="col-span-2 flex items-center gap-4 rounded-xl border border-line bg-surface px-4 py-3 shadow-card md:col-span-1">
                <svg viewBox="0 0 100 100" class="size-14 shrink-0" role="img" aria-label="{{ $target ? round($ratio * 100).'% of target' : 'No target set' }}">
                    <circle cx="50" cy="50" r="42" fill="none" stroke="var(--tl-surface-muted)" stroke-width="11" />
                    @if ($target)
                        <circle cx="50" cy="50" r="42" fill="none" stroke="var(--tl-brand)" stroke-width="11" stroke-linecap="round"
                            stroke-dasharray="{{ round($ratio * $circumference, 2) }} {{ round($circumference, 2) }}" transform="rotate(-90 50 50)" />
                    @endif
                    <text x="50" y="58" text-anchor="middle" font-size="22" font-weight="700" fill="var(--tl-ink)">{{ $target ? round($ratio * 100).'%' : '—' }}</text>
                </svg>
                <div class="grid min-w-0 gap-0.5">
                    <p class="truncate text-xs font-medium text-ink-subtle">{{ $range->key === 'month' ? 'Monthly target' : 'Target · '.strtolower(\App\Support\Period::options()[$range->key]) }}</p>
                    @if ($target)
                        <p class="tabular text-lg leading-tight font-bold text-ink">{{ $pts($points) }} <span class="text-xs font-medium text-ink-subtle">/ {{ $target }} pts</span></p>
                        <p class="text-xs text-ink-muted">@if ($remaining == 0) Target reached. @else {{ $pts($remaining) }} points to go{{ $daysLeft >= 0 ? ' · '.$daysLeft.'d left' : '' }} @endif</p>
                    @else
                        <p class="text-sm font-semibold text-ink">No target set</p>
                    @endif
                    @if ($isOwn && $range->key === 'month' && count($targetMonthOptions))
                        <button type="button" wire:click="openTarget" class="justify-self-start text-xs font-medium text-brand-text hover:underline" title="{{ $targetLocked ? 'Locked since '.$lockDate->format('j M') : 'You can change it until '.$lockDate->format('j M') }}">
                            {{ $currentTarget ? 'Change target' : 'Set target' }}
                        </button>
                    @endif
                </div>
            </section>

            <x-ui.stat label="Points this period" :value="$pts($points)" :hint="$pts($metrics['approvedPoints']).' approved'">
                <span @class(['text-xs font-medium', 'text-success' => $delta > 0, 'text-danger' => $delta < 0, 'text-ink-subtle' => $delta == 0])>
                    {{ $delta > 0 ? '▲ '.$pts($delta) : ($delta < 0 ? '▼ '.$pts(abs($delta)) : 'Same') }} vs previous
                </span>
            </x-ui.stat>

            <x-ui.stat label="Partners gone live" :value="$onboarded" :hint="$range->label()" />

            <x-ui.stat label="Not live yet" :value="$metrics['awaiting']" :hint="$metrics['approvedNotLive'].' approved, going live'">
                @if ($metrics['stalled'])
                    <span class="text-xs font-medium text-danger">{{ $metrics['stalled'] }} waiting {{ config('hub.stalled_after_days') }}+ days</span>
                @endif
            </x-ui.stat>

            @if ($expected !== null)
                <a href="{{ $isOwn ? route('earnings.mine') : route('earnings.member', $subject) }}" wire:navigate class="grid min-w-0 content-start gap-1 rounded-xl border border-brand/25 bg-brand-soft/50 px-4 py-3 shadow-card transition-colors hover:border-brand/60">
                    <p class="truncate text-xs font-medium text-brand-text">Expected pay · {{ now()->format('F') }}</p>
                    <p class="tabular truncate text-2xl leading-tight font-bold tracking-tight text-ink">KES {{ number_format($expected) }}</p>
                    <p class="text-xs text-ink-muted">Open My Earnings →</p>
                </a>
            @else
                <x-ui.stat label="Link clicks" :value="$metrics['clicks']" :hint="$metrics['conversion'] !== null ? $metrics['conversion'].'% became live partners' : 'Share your link to start counting'" />
            @endif
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="grid min-w-0 gap-4">
                {{-- Referral centre --}}
                @if ($referralCode)
                    @php
                        $shareUrl = $referralCode->shareUrl();
                        $message = "List your property on Tourlast: {$shareUrl}";
                        $funnel = [
                            ['Link visits', $metrics['clicks']],
                            ['Applications', $metrics['submitted']],
                            ['Approved', $approvedInPeriod],
                            ['Live', $metrics['onboarded']],
                            ['Active', $metrics['active']],
                        ];
                        $funnelTop = max(1, collect($funnel)->max(fn ($s) => $s[1]));
                    @endphp
                    <x-ui.card :title="$isOwn ? 'My referrals' : 'Referrals'" :description="'Every signup through it is credited automatically · '.$range->label()">
                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="grid min-w-0 content-start gap-3">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="font-mono text-xl font-bold tracking-wide text-brand-text">{{ $referralCode->code }}</span>
                                    <x-ui.pill tone="success">Active</x-ui.pill>
                                </div>
                                <div class="flex min-w-0 items-center gap-2 rounded-md border border-line bg-surface-muted/60 px-3 py-2">
                                    <x-ui.icon name="link" class="size-4 text-ink-subtle" />
                                    <span class="truncate font-mono text-[13px] text-ink-muted" title="{{ $shareUrl }}">{{ $shareUrl }}</span>
                                </div>
                                @if ($isOwn)
                                    <div class="flex flex-wrap gap-2" x-data="copyText(@js($shareUrl))">
                                        <x-ui.button x-on:click="copy" icon="copy">
                                            <span x-show="!copied">Copy link</span>
                                            <span x-show="copied" x-cloak>Copied</span>
                                        </x-ui.button>
                                        <x-ui.button variant="secondary" icon="chat" :href="'https://wa.me/?text='.rawurlencode($message)" target="_blank" rel="noopener">WhatsApp</x-ui.button>
                                        <x-ui.button variant="secondary" icon="mail" :href="'mailto:?subject='.rawurlencode('List your property on Tourlast').'&body='.rawurlencode($message)">Email</x-ui.button>
                                    </div>
                                @endif
                                <div class="grid grid-cols-3 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-5">
                                    @foreach ($funnel as [$label, $value])
                                        <div class="grid min-w-0 gap-0.5 bg-surface px-2.5 py-2 last:col-span-2 sm:last:col-span-1">
                                            <span class="text-[11px] leading-tight font-medium text-ink-subtle">{{ $label }}</span>
                                            <span class="tabular text-lg leading-tight font-bold text-ink">{{ $value }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="grid gap-1.5" aria-label="Conversion funnel">
                                    @foreach ($funnel as [$label, $value])
                                        <div class="grid grid-cols-[88px_1fr_28px] items-center gap-2 text-xs">
                                            <span class="text-ink-muted">{{ $label }}</span>
                                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($value / $funnelTop * 100) }}%"></div></div>
                                            <span class="tabular text-right font-medium text-ink">{{ $value }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @if ($isOwn)
                                <div class="grid content-start justify-items-center gap-2 border-t border-line pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-4" x-data="qrCode(@js($shareUrl), @js($referralCode->code.'.png'))">
                                    <div class="rounded-md border border-line bg-white p-1.5"><canvas x-ref="canvas" class="size-[140px]!" aria-label="QR code for {{ $referralCode->code }}"></canvas></div>
                                    <x-ui.button variant="ghost" size="sm" icon="qr" x-on:click="download">Download QR</x-ui.button>
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                @endif

                {{-- Partner acquisition --}}
                <x-ui.card title="Partner acquisition" description="Points by month, last 6 months, against your own target">
                    @php
                        $max = max(1, collect($trend)->max(fn ($m) => max($m['points'], $m['target'] ?? 0)));
                        $max = (int) (ceil($max / 2) * 2);
                        $chartW = 640; $chartH = 180; $left = 30; $bottom = 22; $top = 12;
                        $plotH = $chartH - $bottom - $top;
                        $slot = ($chartW - $left) / max(1, count($trend));
                        $barW = min(44, $slot * 0.5);
                        $y = fn ($v) => $top + $plotH - ($v / $max) * $plotH;
                    @endphp
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" class="h-auto max-h-56 w-full" role="img" aria-label="Points per month: {{ collect($trend)->map(fn ($m) => $m['label'].' '.$m['points'])->implode(', ') }}">
                        @foreach ([0, $max / 2, $max] as $tick)
                            <line x1="{{ $left }}" x2="{{ $chartW }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--tl-line)" @if ($tick) stroke-dasharray="3 4" @endif />
                            <text x="{{ $left - 8 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" font-size="11" fill="var(--tl-ink-subtle)">{{ (int) $tick }}</text>
                        @endforeach
                        @foreach ($trend as $i => $month)
                            @php
                                $cx = $left + $slot * $i + $slot / 2;
                                $isCurrent = $loop->last;
                            @endphp
                            @if ($month['points'] > 0)
                                <rect x="{{ $cx - $barW / 2 }}" y="{{ $y($month['points']) }}" width="{{ $barW }}" height="{{ $y(0) - $y($month['points']) }}" rx="3"
                                    fill="{{ $isCurrent ? 'var(--tl-brand)' : 'var(--tl-sky)' }}" />
                                <text x="{{ $cx }}" y="{{ ($y(0) - $y($month['points'])) >= 22 ? $y($month['points']) + 15 : $y($month['points']) - 5 }}" text-anchor="middle" font-size="11" font-weight="700" fill="{{ ($y(0) - $y($month['points'])) >= 22 && $isCurrent ? 'var(--tl-brand-ink)' : 'var(--tl-ink)' }}">{{ $pts($month['points']) }}</text>
                            @endif
                            @if ($month['target'])
                                <line x1="{{ $cx - $barW / 2 - 6 }}" x2="{{ $cx + $barW / 2 + 6 }}" y1="{{ $y($month['target']) }}" y2="{{ $y($month['target']) }}" stroke="var(--tl-ink)" stroke-width="1.5" stroke-dasharray="4 3" />
                            @endif
                            <text x="{{ $cx }}" y="{{ $chartH - 5 }}" text-anchor="middle" font-size="11" font-weight="{{ $isCurrent ? 700 : 400 }}" fill="{{ $isCurrent ? 'var(--tl-ink)' : 'var(--tl-ink-subtle)' }}">{{ $month['label'] }}</text>
                        @endforeach
                    </svg>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-ink-subtle">
                        <span class="flex items-center gap-1.5"><span class="inline-block w-4 border-t-2 border-dashed border-ink"></span> Target</span>
                        @foreach ($metrics['byType'] as $type => $count)
                            <x-ui.pill tone="brand" :dot="false">{{ config('hub.property_types.'.$type) ?? \Illuminate\Support\Str::headline((string) ($type ?: 'Other')) }} · {{ $count }}</x-ui.pill>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>

            <div class="grid min-w-0 gap-4">
                <x-ui.card title="Needs follow-up" description="Signups waiting too long, and approved partners not live yet" :padding="false">
                    @forelse ($needsAttention as $onboarding)
                        <div wire:key="attention-{{ $onboarding->id }}" class="flex items-center justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0">
                            <div class="grid min-w-0 leading-tight">
                                <span class="truncate text-[13px] font-medium text-ink">{{ $onboarding->property_name }}</span>
                                <span class="truncate text-xs text-ink-subtle">
                                    {{ $onboarding->propertyTypeLabel() }}{{ $onboarding->location ? ' · '.$onboarding->location : '' }} ·
                                    @if ($onboarding->isStalled()) submitted {{ $onboarding->submitted_at->diffForHumans() }} @else approved {{ $onboarding->approved_at?->diffForHumans() }} @endif
                                </span>
                            </div>
                            @if ($onboarding->isStalled())
                                <x-ui.pill tone="danger">Stalled</x-ui.pill>
                            @else
                                <x-ui.pill tone="brand">Approved</x-ui.pill>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state icon="check-circle" title="Nothing waiting on you" description="Stalled signups and approved partners that haven't gone live will show here." />
                    @endforelse
                </x-ui.card>
    
                <x-ui.card title="Recent activity" :padding="false">
                    @forelse ($recentActivities as $activity)
                        <a wire:key="ra-{{ $activity->id }}" href="{{ route('leads.show', $activity->lead) }}" wire:navigate class="flex items-center gap-3 border-b border-line px-4 py-2 last:border-b-0 hover:bg-surface-muted/50">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$activity->type->icon()" class="size-3.5" /></span>
                            <span class="grid min-w-0 flex-1 leading-tight">
                                <span class="truncate text-[13px] text-ink"><span class="font-medium">{{ $activity->type->label() }}</span> · {{ $activity->lead->business_name }}</span>
                                <span class="truncate text-xs text-ink-subtle">{{ $activity->notes ? \Illuminate\Support\Str::limit($activity->notes, 80) : 'No notes' }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-ink-subtle">{{ $activity->happened_at->diffForHumans(short: true) }}</span>
                        </a>
                    @empty
                        <x-ui.empty-state icon="activity" title="No activity yet" description="Log calls, visits and messages on your leads to build a history." />
                    @endforelse
                </x-ui.card>
            </div>
        </div>
    </div>

    {{-- Set target --}}
    @if ($isOwn)
        <x-ui.slide-over wire:model="showTarget" title="My monthly target" description="Your points goal for the month. 18 points unlocks the KES 7,500 retainer and 30 points the KES 15,000 retainer. You can change it until the {{ $lockDate->format('jS') }} of the month.">
            <form id="target-form" wire:submit="saveTarget" class="grid gap-4">
                <x-ui.select label="Month" wire:model.live="targetMonth" id="target-month">
                    @foreach ($targetMonthOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Target (points)" type="number" min="1" max="500" wire:model="targetValue" id="target-value" />
                <div class="flex flex-wrap gap-2">
                    @foreach ([18 => 'Retainer 7.5k', 30 => 'Retainer 15k', 36 => 'Bonus 7.5k', 46 => 'Bonus 10k'] as $suggested => $why)
                        <x-ui.button size="sm" variant="secondary" wire:click="$set('targetValue', {{ $suggested }})">{{ $suggested }} · {{ $why }}</x-ui.button>
                    @endforeach
                </div>
                <p class="text-xs text-ink-subtle">Last month you earned <span class="font-medium text-ink">{{ $pts($trend[count($trend) - 2]['points'] ?? 0) }} points</span>. {{ $targetLocked ? 'This month is locked since '.$lockDate->format('j M').'.' : 'You can change this month until '.$lockDate->format('j M').'.' }}</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="target-form">Save target</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
