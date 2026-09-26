@php
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    $review = $account->reviewStatus();
    $progress = $account->checklistProgress();
@endphp

<div class="grid gap-6">
    <div class="grid gap-3">
        <a href="{{ route('accounts.index') }}" wire:navigate class="text-[13px] font-semibold text-brand-text hover:underline">← Accounts</a>
        <x-ui.page-header
            :title="$account->legal_name"
            :description="$account->categoryLabel().' Account · onboarded by '.($account->user?->name ?? 'nobody yet').($account->activation_date ? ' · live since '.$account->activation_date->format('j M Y') : ' · not live yet')"
        >
            <x-slot:actions>
                @if ($account->isVerified())<x-ui.pill tone="success">Verified</x-ui.pill>@elseif ($account->activation_date)<x-ui.pill tone="warning">Needs verification</x-ui.pill>@endif
                @if ($review === 'in_review')<x-ui.pill tone="brand">In 14-day review until {{ $account->reviewEndsAt()->format('j M') }}</x-ui.pill>@endif
                @if ($review === 'failed')<x-ui.pill tone="danger">Failed review</x-ui.pill>@endif
                @if ($canVerify)
                    @if (! $account->isVerified() && $account->activation_date)
                        <x-ui.button size="sm" icon="check" wire:click="openVerify" :disabled="$progress['done'] < $progress['total']">Verify &amp; approve points</x-ui.button>
                    @endif
                    @if ($account->activation_date && ! $account->hasFailedReview())
                        <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="openInventory">Record inventory change</x-ui.button>
                    @endif
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    @if ($account->review_warning_at && $review === 'in_review')
        <div class="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-5 py-4 text-sm">
            <x-ui.icon name="alert" class="mt-0.5 size-5 text-danger" />
            <div class="grid gap-1">
                <p class="font-bold text-ink">Review warning · {{ $account->review_warning_at->format('j M') }}</p>
                <p class="text-ink-muted">{{ $account->review_warning }} If the Account can't be kept live and booking-ready, it fails the review and its points are cancelled.</p>
            </div>
        </div>
    @endif
    @if ($review === 'failed')
        <div class="rounded-xl border border-danger/30 bg-danger-soft px-5 py-4 text-sm text-ink">
            <p class="font-bold">Failed the 14-day review on {{ $account->review_failed_at->format('j M Y') }}</p>
            <p class="text-ink-muted">{{ $account->review_failed_reason }}. Its points were cancelled; anything already paid is recovered on the next statement.</p>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Points (not cancelled)" :value="$pts($account->livePoints())" :hint="'Max '.$pts($policy->maxBasePoints($account->category)).' base + one 0.5 award'" />
        <x-ui.stat label="Verified at activation" :value="$account->activation_inventory ?? '—'" :hint="strtolower($account->basisLabel()).($account->inventory_note ? ' · '.$account->inventory_note : '')" />
        <x-ui.stat label="Live now" :value="$account->currentInventory() ?? '—'" hint="Latest recorded count" />
        <x-ui.stat label="Expansion window" :value="$account->expansionDaysLeft() !== null ? $account->expansionDaysLeft().' days' : ($account->activation_date ? 'Closed' : '—')" :hint="$account->expansionEndsAt() ? 'Ends '.$account->expansionEndsAt()->format('j M Y') : 'Starts on the Activation Date'" />
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <x-ui.card title="Qualification checklist" :description="$progress['done'].' of '.$progress['total'].' complete · Schedule 1, paragraph 10'" :padding="false">
            @foreach ($checklist as $item)
                @php $row = $items->get($item->value); @endphp
                <div wire:key="ck-{{ $item->value }}" class="flex items-start gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                    @if ($canVerify && ! $item->isAutomatic() && ! $account->isVerified())
                        <button type="button" wire:click="toggleItem('{{ $item->value }}')" aria-label="Mark {{ $item->label() }}"
                            @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded border', 'border-success bg-success text-white' => $row?->completed_at, 'border-line-strong bg-surface' => ! $row?->completed_at])>
                            @if ($row?->completed_at)<x-ui.icon name="check" class="size-3.5" />@endif
                        </button>
                    @else
                        <span @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded border', 'border-success bg-success text-white' => $row?->completed_at, 'border-line-strong' => ! $row?->completed_at])>
                            @if ($row?->completed_at)<x-ui.icon name="check" class="size-3.5" />@endif
                        </span>
                    @endif
                    <div class="grid min-w-0 flex-1 gap-0.5 leading-tight">
                        <span class="text-sm text-ink">{{ $item->label() }}</span>
                        <span class="text-xs text-ink-subtle">
                            @if ($item->isAutomatic()) From tourlast.com data @elseif ($row?->completed_at) Confirmed by {{ $row->completer?->name }} · {{ $row->completed_at->format('j M') }} @else Confirmed by Sales Admin @endif
                            @if ($row?->evidence_path) · <a href="{{ route('downloads.evidence', $row) }}" class="font-semibold text-brand-text hover:underline">{{ $row->evidence_name }}</a> @endif
                        </span>
                    </div>
                    @if (($isOwner || $canVerify) && $item->wantsEvidence() && ! $account->isVerified())
                        <x-ui.button size="sm" variant="ghost" wire:click="$set('evidenceItem', '{{ $item->value }}')">{{ $row?->evidence_path ? 'Replace' : 'Add evidence' }}</x-ui.button>
                    @endif
                </div>
            @endforeach
            @if ($evidenceItem)
                <form wire:submit="uploadEvidence" class="grid gap-3 border-t border-line bg-surface-muted/60 px-5 py-4">
                    <p class="text-xs font-medium text-ink-muted">Evidence for: {{ \App\Incentives\ChecklistItem::from($evidenceItem)->label() }}</p>
                    <input type="file" wire:model="evidenceFile" id="evidence-file" class="text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-brand-text">
                    @error('evidenceFile')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    <div class="flex gap-2">
                        <x-ui.button type="submit" size="sm">Upload</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="$set('evidenceItem', null)">Cancel</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>

        <div class="grid content-start gap-4">
            <x-ui.card title="Points history" :padding="false">
                @forelse ($account->pointEntries as $entry)
                    <div wire:key="entry-{{ $entry->id }}" class="grid grid-cols-[1fr_auto_auto] items-center gap-3 border-b border-line px-4 py-2 last:border-b-0">
                        <div class="grid min-w-0 leading-tight">
                            <span @class(['text-sm font-semibold', 'text-ink' => $entry->status !== 'cancelled', 'text-ink-subtle line-through' => $entry->status === 'cancelled'])>{{ $entry->typeLabel() }}</span>
                            <span class="truncate text-xs text-ink-subtle">{{ $entry->earned_on->format('j M Y') }} · week {{ $entry->bonus_week }} · {{ $entry->status === 'cancelled' ? $entry->reason : $entry->reason }}</span>
                        </div>
                        <x-ui.pill :tone="$entry->statusTone()">{{ $entry->statusLabel() }}</x-ui.pill>
                        <span class="tabular w-10 text-right font-bold text-ink">+{{ $pts($entry->points) }}</span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-subtle">No points yet. Base points are created on the Activation Date once the rooms, units or services are known.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Properties" :padding="false">
                @foreach ($account->onboardings as $onboarding)
                    <div wire:key="prop-{{ $onboarding->id }}" class="flex items-center justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0">
                        <div class="grid leading-tight">
                            <span class="text-sm font-semibold text-ink">{{ $onboarding->property_name }}</span>
                            <span class="text-xs text-ink-subtle">{{ $onboarding->propertyTypeLabel() }}{{ $onboarding->inventory_count ? ' · '.$onboarding->inventory_count.' per tourlast.com' : '' }}{{ $onboarding->ref_code ? ' · '.$onboarding->ref_code : '' }}</span>
                        </div>
                        <x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill>
                    </div>
                @endforeach
            </x-ui.card>

            <x-ui.card title="Inventory changes" description="Growth within 90 days of activation earns expansion points" :padding="false">
                @forelse ($account->inventorySnapshots as $snapshot)
                    <div wire:key="snap-{{ $snapshot->id }}" class="flex items-center justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0">
                        <div class="grid leading-tight">
                            <span class="text-sm font-semibold text-ink">{{ $snapshot->count }} {{ strtolower($account->basisLabel()) }} live</span>
                            <span class="text-xs text-ink-subtle">{{ $snapshot->live_on->format('j M Y') }} · {{ $snapshot->source === 'sync' ? 'from tourlast.com' : 'recorded by '.$snapshot->verifier?->name }}{{ $snapshot->note ? ' · '.$snapshot->note : '' }}</span>
                        </div>
                        @if ($snapshot->verified_at)
                            <x-ui.pill tone="success">Verified</x-ui.pill>
                        @elseif ($canVerify)
                            <x-ui.button size="sm" variant="secondary" wire:click="verifySnapshot({{ $snapshot->id }})">Verify</x-ui.button>
                        @else
                            <x-ui.pill tone="warning">Unverified</x-ui.pill>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-subtle">No changes since activation.</p>
                @endforelse
            </x-ui.card>

            @if ($canVerify)
                <x-ui.card title="Admin actions">
                    <div class="flex flex-wrap gap-2">
                        @if ($review === 'in_review')
                            <x-ui.button size="sm" variant="danger-ghost" wire:click="$set('showFail', true)">Fail 14-day review</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="ghost" wire:click="$set('showMerge', true)">Merge into another Account</x-ui.button>
                    </div>
                    <p class="mt-2 text-xs text-ink-subtle">Merge when properties were split into separate Accounts but belong to one legal business (paragraph 3.3).</p>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($canVerify)
        <x-ui.slide-over wire:model="showVerify" title="Verify Account" description="Record the verified size at activation. Approving turns provisional points into approved points.">
            <form id="verify-form" wire:submit="verify" class="grid gap-4">
                <x-ui.select label="Provider category" wire:model.live="verifyCategory" id="verify-category">
                    @foreach (\App\Models\PartnerAccount::Categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Measured by" wire:model.live="verifyBasis" id="verify-basis">
                    @foreach (\App\Models\PartnerAccount::Bases as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Verified count at activation" type="number" min="1" wire:model.live.debounce.300ms="verifyInventory" id="verify-inventory" hint="All rooms, units or services under this one legal Account, across every branch." />
                @if ((int) $verifyInventory > 0)
                    @php $preview = (new \App\Incentives\Policy($policy->rules))->basePoints($verifyCategory, (int) $verifyInventory); @endphp
                    <p class="rounded-lg bg-brand-soft px-3 py-2 text-sm text-brand-text">{{ $verifyInventory }} {{ strtolower(\App\Models\PartnerAccount::Bases[$verifyBasis] ?? '') }} → <b>{{ $pts($preview) }} points</b></p>
                @endif
                <x-ui.input label="Note" wire:model="verifyNote" id="verify-note" hint="Required when classified by outlets, packages or products." />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="verify-form">Verify and approve points</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        <x-ui.slide-over wire:model="showInventory" title="Record inventory change" description="Only genuine, configured, verified and live inventory counts. Previously credited inventory is never paid again.">
            <form id="inventory-form" wire:submit="recordInventory" class="grid gap-4">
                <x-ui.input label="Total now live" type="number" min="1" wire:model="inventoryCount" id="inventory-count" :hint="'Whole Account, in '.strtolower($account->basisLabel())" />
                <x-ui.input label="Went live on" type="date" wire:model="inventoryLiveOn" id="inventory-live" hint="Expansion points land in this date's month and bonus week." />
                <x-ui.input label="What was added and how it was verified" wire:model="inventoryNote" id="inventory-note" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="inventory-form">Record</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        <x-ui.slide-over wire:model="showFail" title="Fail the 14-day review" description="The Account's points are cancelled and pay is recalculated. Anything already paid is recovered on the next statement.">
            <form id="fail-form" wire:submit="failReview" class="grid gap-4">
                <x-ui.input label="Reason" wire:model="failReason" id="fail-reason" placeholder="e.g. Partner requested closure on 18 Sep" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="fail-form" variant="danger">Fail review</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>

        <x-ui.slide-over wire:model="showMerge" title="Merge into another Account" description="This Account's properties move to the chosen Account and its points are cancelled. The combined Account is recalculated and needs verifying again.">
            <form id="merge-form" wire:submit="merge" class="grid gap-4">
                <x-ui.select label="Merge into" wire:model="mergeTarget" id="merge-target">
                    <option value="">Choose an Account…</option>
                    @foreach ($mergeOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->legal_name }}</option>
                    @endforeach
                </x-ui.select>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="merge-form">Merge</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
