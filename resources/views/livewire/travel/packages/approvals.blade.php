@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Package approvals" description="Every package needs Sales Admin approval and then Super Admin approval, by two different people. Nobody reviews a package they created." />

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card">
        @foreach ([
            ['Awaiting Sales Admin', $counts['first'], $counts['first'] ? 'text-warning' : 'text-ink'],
            ['Awaiting Super Admin', $counts['final'], $counts['final'] ? 'text-warning' : 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="flex flex-wrap gap-1 border-b border-line" role="tablist">
        @foreach (\App\Livewire\Travel\Approvals\Index::Tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class(['-mb-px border-b-2 px-3 py-2 text-[13px] font-medium', 'border-brand text-ink' => $tab === $key, 'border-transparent text-ink-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'pending')
        <x-ui.table-card>
            <table class="w-full min-w-[1200px] text-left text-[13px]">
                <thead>
                    <tr>
                        <th>Package</th><th>Provider</th><th>Created by</th><th>Created</th><th>Contract</th><th class="text-right">Price (adult)</th><th>Next travel date</th><th>Sales Admin</th><th>Super Admin</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($pending as $package)
                        @php
                            $version = $package->workingVersion;
                            $firstApproval = $version->approvals->filter(fn ($a) => $a->level === \App\Enums\Travel\ApprovalLevel::SalesAdmin && $a->decision === \App\Enums\Travel\ApprovalDecision::Approved && (! $version->submitted_at || $a->decided_at->gte($version->submitted_at)))->last();
                            $contractStatus = $package->contract?->effectiveStatus();
                            $canDecideRow = \App\Actions\Travel\Packages\ReviewPackage::canReview(auth()->user(), $package, $version);
                        @endphp
                        <tr wire:key="pend-{{ $package->id }}">
                            <td>
                                <a href="{{ route('travel.packages.show', $package) }}" wire:navigate class="grid leading-tight hover:underline">
                                    <span class="font-semibold text-ink">{{ $package->name }}</span>
                                    <span class="text-xs text-ink-subtle">{{ $package->reference }} · {{ $version->label() }}@if ($package->liveVersion) · change to live {{ $package->liveVersion->label() }}@endif</span>
                                </a>
                            </td>
                            <td class="text-ink-muted">{{ $package->provider?->name }}</td>
                            <td class="text-ink-muted">{{ $package->creator?->name }}</td>
                            <td class="whitespace-nowrap text-ink-subtle">{{ $package->created_at->format('j M Y') }}</td>
                            <td>
                                @if ($contractStatus)
                                    <x-ui.pill :tone="$contractStatus->tone()">{{ $contractStatus->label() }}</x-ui.pill>
                                @else
                                    <x-ui.pill tone="danger">No contract</x-ui.pill>
                                @endif
                            </td>
                            <td class="tabular text-right">{{ $version->adult_price !== null ? $version->currency.' '.number_format((float) $version->adult_price) : '—' }}</td>
                            <td class="whitespace-nowrap text-ink-muted">{{ $package->next_departure_on ? \Carbon\Carbon::parse($package->next_departure_on)->format('j M Y') : '—' }}</td>
                            <td>
                                @if ($firstApproval)
                                    <span class="grid leading-tight"><x-ui.pill tone="success">Approved</x-ui.pill><span class="text-xs text-ink-subtle">{{ $firstApproval->user->name }} · {{ $firstApproval->decided_at->format('j M') }}</span></span>
                                @else
                                    <x-ui.pill tone="warning">Pending</x-ui.pill>
                                @endif
                            </td>
                            <td>
                                <x-ui.pill :tone="$firstApproval ? 'warning' : 'neutral'">{{ $firstApproval ? 'Pending' : 'Waiting' }}</x-ui.pill>
                            </td>
                            <td class="text-right">
                                @if ($canDecideRow)
                                    <x-ui.button size="sm" wire:click="review({{ $package->id }})">Review</x-ui.button>
                                @else
                                    <span class="text-xs text-ink-subtle">{{ in_array(auth()->id(), [$package->created_by, $package->owner_id], true) ? 'Your package' : 'Not yours to review' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-ui.empty-state icon="shield" title="Nothing waiting for you" description="Packages submitted for approval appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    @else
        <x-ui.table-card>
            <table class="w-full min-w-[1000px] text-left text-[13px]">
                <thead><tr><th>Package</th><th>Provider</th><th>Created by</th><th>Version</th><th>Review</th><th>Decision</th><th>By</th><th>When</th><th>Reason</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($decisions as $decision)
                        <tr wire:key="dec-{{ $decision->id }}">
                            <td><a href="{{ route('travel.packages.show', $decision->package_id) }}" wire:navigate class="font-semibold text-ink hover:underline">{{ $decision->package?->name }}</a> <span class="text-xs text-ink-subtle">{{ $decision->package?->reference }}</span></td>
                            <td class="text-ink-muted">{{ $decision->package?->provider?->name }}</td>
                            <td class="text-ink-muted">{{ $decision->package?->creator?->name }}</td>
                            <td>{{ $decision->version?->label() }}</td>
                            <td class="text-ink-muted">{{ $decision->level->label() }}</td>
                            <td><x-ui.pill :tone="$decision->decision->tone()">{{ $decision->decision->label() }}</x-ui.pill></td>
                            <td>{{ $decision->user?->name }}</td>
                            <td class="whitespace-nowrap text-ink-subtle">{{ $decision->decided_at->format('j M Y H:i') }}</td>
                            <td class="max-w-80 truncate text-ink-muted" title="{{ $decision->reason }}">{{ $decision->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-ui.empty-state icon="shield" title="No decisions yet" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.slide-over wire:model.live="showReview" :title="$reviewing ? 'Review: '.$reviewing->name : 'Review'" :description="$reviewing?->workingVersion ? $reviewing->reference.' · '.$reviewing->workingVersion->label().' · '.$reviewing->workingVersion->status->label() : null">
        @if ($reviewing && $reviewing->workingVersion)
            @php
                $wv = $reviewing->workingVersion;
            @endphp
            <div class="grid gap-4 text-[13px]">
                @if ($wv->material_changes)
                    <div class="grid gap-1 rounded-md border border-danger/30 bg-danger-soft/40 px-3 py-2">
                        <p class="font-semibold text-ink">Changes to the live version {{ $reviewing->liveVersion?->label() }}</p>
                        @foreach ($wv->material_changes as $field => [$old, $new])
                            <p><span class="font-medium">{{ \App\Support\Travel\PackageContent::label($field) }}:</span> <span class="text-ink-muted line-through">{{ is_scalar($old) || $old === null ? ($old ?? '—') : json_encode($old) }}</span> → <span class="text-ink">{{ is_scalar($new) || $new === null ? ($new ?? '—') : json_encode($new) }}</span></p>
                        @endforeach
                    </div>
                @endif
                <div>
                    <p class="mb-1 text-xs font-semibold tracking-wide text-ink-subtle uppercase">Readiness</p>
                    <ul class="grid gap-0.5">
                        @foreach ($reviewReadiness as $item)
                            <li class="flex items-center gap-2"><span @class(['text-success' => $item['ok'], 'text-danger' => ! $item['ok']])>{{ $item['ok'] ? '✓' : '✗' }}</span> {{ $item['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
                <dl class="grid grid-cols-2 gap-2">
                    <div><dt class="text-xs text-ink-subtle">Provider</dt><dd class="text-ink">{{ $reviewing->provider?->name }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Contract</dt><dd class="text-ink">{{ $reviewing->contract ? $reviewing->contract->contract_number.' · '.$reviewing->contract->effectiveStatus()->label() : 'None' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Destination</dt><dd class="text-ink">{{ $wv->destination }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Duration</dt><dd class="text-ink">{{ $wv->days }} days, {{ $wv->nights }} nights</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Adult price</dt><dd class="tabular text-ink">{{ $wv->adult_price !== null ? $wv->currency.' '.number_format((float) $wv->adult_price) : '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Capacity</dt><dd class="text-ink">{{ $wv->default_capacity ?? $wv->max_travelers ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Created by</dt><dd class="text-ink">{{ $reviewing->creator?->name }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Submitted</dt><dd class="text-ink">{{ $wv->submitted_at?->format('j M Y H:i') }}</dd></div>
                </dl>
                @if ($wv->short_description)<p class="text-ink">{{ $wv->short_description }}</p>@endif
                <div class="grid gap-2 sm:grid-cols-2">
                    <div><p class="text-xs font-semibold text-ink-subtle uppercase">Inclusions</p><p class="text-ink">{{ implode(', ', $wv->inclusions ?? []) ?: '—' }}</p></div>
                    <div><p class="text-xs font-semibold text-ink-subtle uppercase">Exclusions</p><p class="text-ink">{{ implode(', ', $wv->exclusions ?? []) ?: '—' }}</p></div>
                </div>
                <div><p class="text-xs font-semibold text-ink-subtle uppercase">Cancellation policy</p><p class="whitespace-pre-line text-ink">{{ $wv->cancellation_policy ?: '—' }}</p></div>
                <div>
                    <p class="text-xs font-semibold text-ink-subtle uppercase">Itinerary</p>
                    <ol class="grid gap-0.5">
                        @foreach ($wv->itineraryDays as $day)
                            <li><span class="font-medium">Day {{ $day->day_number }}:</span> {{ $day->title }}</li>
                        @endforeach
                    </ol>
                </div>
                @if ($wv->approvals->isNotEmpty())
                    <div>
                        <p class="text-xs font-semibold text-ink-subtle uppercase">Earlier decisions</p>
                        @foreach ($wv->approvals as $approval)
                            <p class="text-ink-muted">{{ $approval->decided_at->format('j M') }} · {{ $approval->level->label() }} · {{ $approval->decision->label() }} by {{ $approval->user->name }}{{ $approval->reason ? ': '.$approval->reason : '' }}</p>
                        @endforeach
                    </div>
                @endif
                <a href="{{ route('travel.packages.show', $reviewing) }}" wire:navigate class="text-brand-text hover:underline">Open the full package</a>

                @if ($canDecide)
                    <div class="grid gap-1 border-t border-line pt-3">
                        <label for="review-reason" class="text-xs font-medium text-ink-muted">Reason (required to reject or request changes)</label>
                        <textarea id="review-reason" wire:model="reason" rows="3" class="{{ $textarea }}" placeholder="Missing cancellation policy."></textarea>
                        @error('reason') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        @error('package') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    <p class="rounded-md bg-surface-muted px-3 py-2 text-ink-muted">You cannot review this package: you created it, already approved it at the first level, or it is waiting for the other level (a Super Admin only gives the final approval).</p>
                @endif
            </div>
        @endif
        @if ($canDecide)
            <x-slot:footer>
                <x-ui.button variant="danger-ghost" wire:click="reject">Reject</x-ui.button>
                <x-ui.button variant="secondary" wire:click="requestChanges">Request changes</x-ui.button>
                <x-ui.button wire:click="approve">Approve</x-ui.button>
            </x-slot:footer>
        @endif
    </x-ui.slide-over>
</div>
