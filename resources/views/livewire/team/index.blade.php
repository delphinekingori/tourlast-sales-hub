<div class="grid gap-5">
    <x-ui.page-header
        eyebrow="Admin"
        title="Users & Invites"
        description="Accounts are invitation-only. Invited people get a single-use link by email from sales@tourlast.com."
    >
        <x-slot:actions>
            <x-ui.button wire:click="openInvite" icon="plus">Invite someone</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-md border border-line bg-surface p-0.5" role="tablist">
            @foreach (['people' => 'People', 'invitations' => 'Invitations'] as $key => $label)
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'flex items-center gap-2 rounded px-2.5 py-1 text-xs font-medium transition-colors',
                        'bg-brand-soft text-brand-text' => $tab === $key,
                        'text-ink-subtle hover:text-ink' => $tab !== $key,
                    ])
                >
                    {{ $label }}
                    @if ($key === 'invitations' && $pendingCount)
                        <span class="rounded-full bg-warning-soft px-1.5 text-[11px] font-bold text-warning">{{ $pendingCount }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
        @if ($tab === 'people')
            <select wire:model.live="status" aria-label="Account status" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All accounts</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="terminated">Fired</option>
            </select>
        @endif
        <label class="relative w-full sm:w-72">
            <span class="sr-only">Search</span>
            <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-subtle" />
            <input
                type="search"
                id="team-search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search name, email or region"
                class="h-9 w-full rounded-md border border-line-strong bg-surface pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"
            >
        </label>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
            @if ($tab === 'people')
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="text-left text-ink-subtle uppercase">
                        <tr>
                            <th class="text-left">Person</th>
                            <th class="text-left">Role</th>
                            <th class="text-left">Region</th>
                            <th class="text-left">Referral code</th>
                            <th class="text-left">Online</th>
                            <th class="text-left">Status</th>
                            <th class="text-left"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($people as $person)
                            <tr wire:key="user-{{ $person->id }}" @class(['opacity-60' => ! $person->is_active])>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :user="$person" presence />
                                        <div class="grid min-w-0 leading-tight">
                                            <span class="font-semibold text-ink">{{ $person->name }} @if ($person->is(auth()->user()))<span class="font-normal text-ink-subtle">(you)</span>@endif</span>
                                            <span class="truncate text-[13px] text-ink-subtle">{{ $person->email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-ink-muted">{{ $person->role()?->label() ?? '—' }}</td>
                                <td class="text-ink-muted">{{ $person->region ?? '—' }}</td>
                                <td>
                                    @if ($person->referralCode)
                                        <span class="font-mono text-[13px] text-brand-text">{{ $person->referralCode->code }}</span>
                                    @else
                                        <span class="text-ink-subtle">—</span>
                                    @endif
                                </td>
                                <td><x-ui.presence :user="$person" /></td>
                                <td>
                                    @php
                                        $accountStatus = $person->accountStatus();
                                    @endphp
                                    <x-ui.pill :tone="$accountStatus->tone()">{{ $accountStatus->label() }}{{ $accountStatus === \App\Enums\AccountStatus::Suspended && $person->suspended_until ? ' until '.$person->suspended_until->format('j M') : '' }}</x-ui.pill>
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($canManageUsers && ! $person->is(auth()->user()) && (! $person->hasRole('super-admin') || auth()->user()->hasRole('super-admin')))
                                            <x-ui.button variant="ghost" size="sm" wire:click="openEdit({{ $person->id }})">Edit</x-ui.button>
                                        @endif
                                        @if (auth()->user()->can('suspend', $person) || auth()->user()->can('terminate', $person) || auth()->user()->can('reinstate', $person) || auth()->user()->can('delete', $person))
                                            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                                <x-ui.button variant="ghost" size="sm" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-label="Account actions for {{ $person->name }}">Account ▾</x-ui.button>
                                                <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-30 mt-1 grid w-48 gap-0.5 rounded-lg border border-line bg-surface p-1 text-left shadow-overlay" role="menu">
                                                    @can('reinstate', $person)
                                                        <button type="button" role="menuitem" wire:click="openAccountAction({{ $person->id }}, 'reinstate')" x-on:click="open = false" class="rounded px-2.5 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Reinstate</button>
                                                    @endcan
                                                    @can('suspend', $person)
                                                        <button type="button" role="menuitem" wire:click="openAccountAction({{ $person->id }}, 'suspend')" x-on:click="open = false" class="rounded px-2.5 py-1.5 text-left text-[13px] text-ink hover:bg-surface-muted">Suspend…</button>
                                                    @endcan
                                                    @can('terminate', $person)
                                                        <button type="button" role="menuitem" wire:click="openAccountAction({{ $person->id }}, 'terminate')" x-on:click="open = false" class="rounded px-2.5 py-1.5 text-left text-[13px] text-danger hover:bg-danger-soft">Fire…</button>
                                                    @endcan
                                                    @can('delete', $person)
                                                        <div class="my-0.5 border-t border-line"></div>
                                                        <button type="button" role="menuitem" wire:click="openAccountAction({{ $person->id }}, 'delete')" x-on:click="open = false" class="rounded px-2.5 py-1.5 text-left text-[13px] text-danger hover:bg-danger-soft">Delete account…</button>
                                                    @endcan
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state icon="users" title="No one matches that search" description="Try a different name, email or region." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="text-left text-ink-subtle uppercase">
                        <tr>
                            <th class="text-left">Invited person</th>
                            <th class="text-left">Role</th>
                            <th class="text-left">Invited by</th>
                            <th class="text-left">Sent</th>
                            <th class="text-left">Expires</th>
                            <th class="text-left">Status</th>
                            <th class="text-left"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($invitations as $invitation)
                            @php
                                $status = $invitation->status();
                            @endphp
                            <tr wire:key="invitation-{{ $invitation->id }}">
                                <td>
                                    <div class="grid leading-tight">
                                        <span class="font-semibold text-ink">{{ $invitation->name }}</span>
                                        <span class="text-[13px] text-ink-subtle">{{ $invitation->email }}</span>
                                    </div>
                                </td>
                                <td class="text-ink-muted">{{ $invitation->role->label() }}{{ $invitation->region ? ' · '.$invitation->region : '' }}</td>
                                <td class="text-ink-muted">{{ $invitation->inviter->name }}</td>
                                <td class="text-ink-muted">{{ ($invitation->last_sent_at ?? $invitation->created_at)->format('j M Y') }}</td>
                                <td class="text-ink-muted">{{ $status === \App\Enums\InvitationStatus::Accepted ? '—' : $invitation->expires_at->format('j M Y') }}</td>
                                <td><x-ui.pill :tone="$status->tone()">{{ $status->label() }}</x-ui.pill></td>
                                <td>
                                    @if ($status !== \App\Enums\InvitationStatus::Accepted && in_array($invitation->role, $assignableRoles, true))
                                        <div class="flex justify-end gap-1">
                                            <x-ui.button variant="ghost" size="sm" icon="refresh" wire:click="resendInvite({{ $invitation->id }})">
                                                {{ $status === \App\Enums\InvitationStatus::Pending ? 'Resend' : 'Send new link' }}
                                            </x-ui.button>
                                            @if ($status === \App\Enums\InvitationStatus::Pending)
                                                <x-ui.button variant="danger-ghost" size="sm" wire:click="revokeInvite({{ $invitation->id }})" wire:confirm="Cancel the invitation for {{ $invitation->email }}? The link will stop working.">Cancel</x-ui.button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ui.empty-state icon="mail" title="No invitations yet" description="Invite your first salesperson to get started." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>

        @php
            $paginator = $tab === 'people' ? $people : $invitations;
        @endphp
        @if ($paginator->hasPages())
            <div class="border-t border-line px-4 py-2.5">{{ $paginator->links() }}</div>
        @endif
    </div>

    {{-- Invite --}}
    <x-ui.slide-over wire:model="showInvite" title="Invite someone" description="They'll get an email with a link that works once and expires in {{ config('hub.invitation_expiry_days') }} days.">
        <form id="invite-form" wire:submit="sendInvite" class="grid gap-4">
            <x-ui.input label="Full name" wire:model="invite.name" id="invite-name" autocomplete="off" />
            <x-ui.input label="Work email" type="email" wire:model="invite.email" id="invite-email" autocomplete="off" />
            <x-ui.input label="Phone" type="tel" wire:model="invite.phone" id="invite-phone" placeholder="+254 7…" hint="Optional. They can add or change it when they accept." />

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-xs font-medium text-ink-muted">Role</legend>
                @foreach ($assignableRoles as $role)
                    <label wire:key="invite-role-{{ $role->value }}" class="flex cursor-pointer items-start gap-3 rounded-lg border border-line p-3 transition-colors has-checked:border-brand has-checked:bg-brand-soft/60">
                        <input type="radio" wire:model="invite.role" value="{{ $role->value }}" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                        <span class="grid gap-0.5 leading-tight">
                            <span class="text-sm font-semibold text-ink">{{ $role->label() }}</span>
                            <span class="text-[13px] text-ink-subtle">{{ $role->description() }}</span>
                        </span>
                    </label>
                @endforeach
                @error('invite.role')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
            </fieldset>

            <x-ui.input label="Region" wire:model="invite.region" id="invite-region" placeholder="e.g. Nairobi, Coast" hint="Optional. Used to group people in reports." />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="invite-form" icon="mail">
                <span wire:loading.remove wire:target="sendInvite">Send invitation</span>
                <span wire:loading wire:target="sendInvite">Sending…</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>

    {{-- Edit --}}
    <x-ui.slide-over wire:model="showEdit" :title="$editingUser ? 'Edit '.$editingUser->name : 'Edit person'" :description="$editingUser?->email">
        <form id="edit-form" wire:submit="saveEdit" class="grid gap-4">
            <x-ui.select label="Role" wire:model="edit.role" id="edit-role">
                @foreach ($assignableRoles as $role)
                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input label="Region" wire:model="edit.region" id="edit-region" />

            @can('manage-ref-codes')
                @if ($editingUser?->role()?->earnsReferrals())
                    <x-ui.input label="Referral code" wire:model="edit.ref_code" id="edit-ref-code" placeholder="TL-NAME-1234" hint="The Hub is the source of truth: source apps pull this list. Leave empty to keep the current code." />
                @endif
            @endcan

            <p class="text-xs text-ink-subtle">To suspend, fire, reinstate or delete this account, use <span class="font-medium text-ink">Account</span> on their row.</p>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="edit-form">Save changes</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>

    {{-- Suspend / fire / reinstate / delete --}}
    @if ($accountUser)
        @php
            $titles = ['suspend' => 'Suspend '.$accountUser->name, 'terminate' => 'Fire '.$accountUser->name, 'reinstate' => 'Reinstate '.$accountUser->name, 'delete' => 'Delete '.$accountUser->name.'\'s account'];
            $descriptions = [
                'suspend' => 'They are signed out immediately and cannot sign in until reinstated. Nothing is removed.',
                'terminate' => 'Ends their access for good. Their leads, history, points and pay records are all kept for Tourlast\'s records. Only a Sales Admin can reinstate them.',
                'reinstate' => 'They can sign in again with their existing password.',
                'delete' => 'Permanently removes the account. Only possible for accounts with no business history; anyone with history must be fired instead.',
            ];
        @endphp
        <x-ui.slide-over wire:model="showAccount" :title="$titles[$accountAction] ?? 'Account'" :description="$descriptions[$accountAction] ?? null">
            <form id="account-form" wire:submit="saveAccountAction" class="grid gap-4">
                <div class="flex items-center gap-3 rounded-md border border-line bg-surface-muted/60 px-3 py-2">
                    <x-ui.avatar :user="$accountUser" size="sm" />
                    <div class="grid leading-tight">
                        <span class="text-[13px] font-semibold text-ink">{{ $accountUser->name }}</span>
                        <span class="text-xs text-ink-subtle">{{ $accountUser->role()?->label() }} · {{ $accountUser->accountStatus()->label() }}</span>
                    </div>
                </div>

                @if (in_array($accountAction, ['suspend', 'terminate'], true))
                    <x-ui.select label="Reason" wire:model.live="account.reason" id="account-reason">
                        <option value="">Choose…</option>
                        @foreach ($accountAction === 'suspend' ? \App\Enums\AccountStatus::suspensionReasons() : \App\Enums\AccountStatus::terminationReasons() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($accountAction === 'suspend')
                        <x-ui.input label="Suspended until" type="date" wire:model="account.until" id="account-until" min="{{ now()->addDay()->toDateString() }}" hint="Optional. They are reinstated automatically that morning; leave empty to reinstate by hand." />
                    @endif
                @endif

                @if ($accountAction !== 'delete')
                    <div class="grid gap-1">
                        <label for="account-notes" class="text-xs font-medium text-ink-muted">Notes {{ ($account['reason'] ?? '') === 'other' ? '' : '(optional)' }}</label>
                        <textarea id="account-notes" wire:model="account.notes" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
                        @error('account.notes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                @endif

                @if ($handover && ($handover['leads'] || $handover['properties']))
                    <div class="grid gap-1 rounded-md border border-warning/40 bg-warning-soft/50 px-3 py-2 text-[13px] text-ink">
                        <span class="font-semibold">Hand over their work</span>
                        <span class="text-xs text-ink-muted">{{ $accountUser->name }} owns {{ $handover['leads'] }} open {{ \Illuminate\Support\Str::plural('lead', $handover['leads']) }} and represents {{ $handover['properties'] }} registry {{ \Illuminate\Support\Str::plural('property', $handover['properties']) }}. Transfer them so nothing is left without an owner.</span>
                        @can(\App\Enums\Permission::TransferOwnership->value)
                            <a href="{{ route('leads.index', ['owner' => $accountUser->id, 'status' => 'open']) }}" wire:navigate class="text-xs font-semibold text-brand-text hover:underline">Transfer their leads →</a>
                        @endcan
                    </div>
                @endif

                @if ($accountAction === 'delete')
                    @if ($deleteBlockers !== [])
                        <div class="grid gap-1 rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-[13px] text-danger">
                            <span class="font-semibold">This account can't be deleted</span>
                            <span class="text-xs">It has history Tourlast must keep: {{ implode(', ', $deleteBlockers) }}. Fire them instead; their records stay intact and they can no longer sign in.</span>
                        </div>
                    @else
                        <p class="text-[13px] text-ink-muted">This account has no leads, onboardings, points, pay or audit records. Deleting it cannot be undone.</p>
                    @endif
                @endif

                @if (in_array($accountAction, ['terminate', 'delete'], true) && ! ($accountAction === 'delete' && $deleteBlockers !== []))
                    <label class="flex items-start gap-2 text-[13px] text-ink">
                        <input type="checkbox" wire:model="account.confirm" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                        <span>{{ $accountAction === 'terminate' ? 'I confirm '.$accountUser->name.' is leaving Tourlast.' : 'I understand this permanently deletes the account.' }}</span>
                    </label>
                @endif
                @error('account.confirm')<p class="-mt-2 text-xs text-danger">{{ $message }}</p>@enderror

                @if ($accountUser->statusChanges->isNotEmpty())
                    <div class="grid gap-1 border-t border-line pt-3">
                        <p class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Account history</p>
                        @foreach ($accountUser->statusChanges->take(5) as $change)
                            <p class="text-xs text-ink-muted"><span class="font-medium text-ink">{{ $change->to_status->label() }}</span> · {{ $change->created_at->format('j M Y') }}{{ $change->reasonLabel() ? ' · '.$change->reasonLabel() : '' }}{{ $change->changer ? ' · by '.$change->changer->name : ' · automatically' }}</p>
                        @endforeach
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                @unless ($accountAction === 'delete' && $deleteBlockers !== [])
                    <x-ui.button type="submit" form="account-form" :variant="in_array($accountAction, ['terminate', 'delete'], true) ? 'danger' : 'primary'">
                        {{ ['suspend' => 'Suspend', 'terminate' => 'Fire', 'reinstate' => 'Reinstate', 'delete' => 'Delete permanently'][$accountAction] }}
                    </x-ui.button>
                @endunless
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>