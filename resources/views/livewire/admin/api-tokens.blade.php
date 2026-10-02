<div class="grid gap-5">
    <x-ui.page-header title="API tokens" description="Tokens let apps and other systems use the Sales Hub API. Each token acts as one person and is limited to the scopes you choose; it can never do more than that person can in the Hub.">
        <x-slot:actions>
            <x-ui.button :href="url('/api/v1/meta')" variant="secondary" icon="link" target="_blank">API base</x-ui.button>
            <x-ui.button icon="plus" wire:click="openCreate">Create token</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="owner" aria-label="Person" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
            <option value="">Everyone's tokens</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
        <span class="text-xs text-ink-subtle">Base URL: <span class="font-mono">{{ url('/api/v1') }}</span> · Reference: docs/API.md</span>
    </div>

    <x-ui.table-card :paginator="$tokens">
        <table class="w-full min-w-[980px] text-sm">
            <thead class="text-left uppercase">
                <tr>
                    <th class="text-left">Token</th>
                    <th class="text-left">Acts as</th>
                    <th class="text-left">Scopes</th>
                    <th class="text-left">Last used</th>
                    <th class="text-left">Expires</th>
                    <th class="text-left">Issued</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($tokens as $token)
                    <tr wire:key="tok-{{ $token->id }}">
                        <td class="font-medium text-ink">{{ $token->name }}</td>
                        <td>
                            @if ($token->tokenable)
                                <span class="grid leading-tight"><span class="text-ink">{{ $token->tokenable->name }}</span><span class="text-xs text-ink-subtle">{{ $token->tokenable->role()?->label() }}</span></span>
                            @else
                                <span class="text-ink-subtle">Deleted user</span>
                            @endif
                        </td>
                        <td class="max-w-96">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($token->scopes() as $scope)
                                    <span class="rounded bg-surface-muted px-1.5 py-px font-mono text-[11px] text-ink-muted">{{ $scope }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="text-ink-muted">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            @if ($token->isExpired())
                                <x-ui.pill tone="danger">Expired</x-ui.pill>
                            @else
                                <span class="text-ink-muted">{{ $token->expires_at?->format('j M Y') ?? 'Never' }}</span>
                            @endif
                        </td>
                        <td class="text-xs text-ink-muted">{{ $token->created_at->format('j M Y') }}{{ $token->issuer ? ' · by '.$token->issuer->name : '' }}</td>
                        <td class="text-right"><x-ui.button size="sm" variant="danger-ghost" wire:click="revoke({{ $token->id }})" wire:confirm="Revoke {{ $token->name }}? Anything using it stops working immediately.">Revoke</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="lock" title="No API tokens yet" description="Create one for tourlast.com, a reporting tool or an app." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showCreate" title="Create API token" description="The token acts as the person you choose, limited to the scopes you tick.">
        @if ($plainToken)
            <div class="grid gap-3" x-data="copyText(@js($plainToken))">
                <div class="grid gap-1 rounded-md border border-success/30 bg-success-soft px-3 py-2">
                    <span class="text-[13px] font-semibold text-ink">Copy this token now</span>
                    <span class="text-xs text-ink-muted">For security it is shown only once. Store it in the other system's secret settings.</span>
                </div>
                <p class="rounded-md border border-line bg-surface-muted px-3 py-2 font-mono text-xs break-all text-ink select-all">{{ $plainToken }}</p>
                <x-ui.button icon="copy" x-on:click="copy" class="justify-self-start"><span x-show="!copied">Copy token</span><span x-show="copied" x-cloak>Copied</span></x-ui.button>
                <p class="text-xs text-ink-subtle">Send it as <span class="font-mono">Authorization: Bearer &lt;token&gt;</span> to {{ url('/api/v1') }}.</p>
            </div>
        @else
            <form id="token-form" wire:submit="create" class="grid gap-4">
                <x-ui.select label="Acts as" wire:model="form.user_id" id="token-user" hint="For a system (e.g. tourlast.com), create a dedicated service account first and issue the token to it.">
                    <option value="">Choose…</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Token name" wire:model="form.name" id="token-name" placeholder="e.g. tourlast.com production" />
                <div class="grid gap-2">
                    <span class="text-xs font-medium text-ink-muted">Presets</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Livewire\Admin\ApiTokens::Presets as $key => $preset)
                            <x-ui.button size="sm" variant="secondary" wire:click="applyPreset('{{ $key }}')">{{ $preset['label'] }}</x-ui.button>
                        @endforeach
                    </div>
                </div>
                <fieldset class="grid gap-1.5">
                    <legend class="mb-1 text-xs font-medium text-ink-muted">Scopes</legend>
                    @foreach ($scopes as $scope)
                        <label wire:key="scope-{{ $scope->value }}" class="flex items-start gap-2 text-[13px]">
                            <input type="checkbox" wire:model="form.scopes" value="{{ $scope->value }}" class="mt-0.5 size-4 accent-[var(--tl-brand)]">
                            <span class="grid leading-tight"><span class="font-mono text-xs text-ink">{{ $scope->value }}</span><span class="text-xs text-ink-subtle">{{ $scope->label() }}</span></span>
                        </label>
                    @endforeach
                    @error('form.scopes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </fieldset>
                <x-ui.select label="Expires" wire:model="form.expires" id="token-expires">
                    <option value="30">In 30 days</option>
                    <option value="90">In 90 days</option>
                    <option value="365">In 1 year</option>
                    <option value="never">Never (system integrations only)</option>
                </x-ui.select>
            </form>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ $plainToken ? 'Done' : 'Cancel' }}</x-ui.button>
            @unless ($plainToken)
                <x-ui.button type="submit" form="token-form">Create token</x-ui.button>
            @endunless
        </x-slot:footer>
    </x-ui.slide-over>
</div>
