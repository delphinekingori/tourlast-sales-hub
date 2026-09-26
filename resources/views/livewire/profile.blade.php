<div class="grid gap-5">
    <x-ui.page-header eyebrow="Account" title="My profile" description="Your photo and details are shown to colleagues in the People directory." />

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-6">
            <x-ui.avatar :user="$user" size="xl" presence />
            <div class="grid gap-1">
                <p class="text-xl font-bold tracking-tight text-ink">{{ $user->name }}</p>
                <p class="text-sm text-ink-muted">{{ $user->job_title ?? 'Add your position below' }} · {{ $user->role()?->label() }}{{ $user->region ? ' · '.$user->region : '' }}</p>
                <p class="text-[13px] text-ink-subtle">{{ $user->email }} · joined {{ $user->created_at->format('F Y') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <label for="profile-photo" class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-line-strong bg-surface px-3 text-[13px] font-semibold text-ink hover:bg-surface-muted">
                        <x-ui.icon name="user" class="size-4" /> {{ $user->avatar_path ? 'Change photo' : 'Upload photo' }}
                    </label>
                    <input type="file" id="profile-photo" wire:model="photo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                    @if ($user->avatar_path)
                        <x-ui.button size="sm" variant="ghost" wire:click="removePhoto">Remove</x-ui.button>
                    @endif
                    <span wire:loading wire:target="photo" class="text-[13px] text-brand-text">Uploading…</span>
                </div>
                @error('photo')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                <p class="text-xs text-ink-subtle">JPG, PNG or WebP, up to 3 MB. A clear head-and-shoulders photo works best.</p>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <x-ui.card title="Primary details">
            <form wire:submit="saveDetails" class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Full name" wire:model="name" autocomplete="name" />
                    <x-ui.input label="Phone" type="tel" wire:model="phone" autocomplete="tel" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Position" wire:model="job_title" id="field-job_title" placeholder="e.g. Business Development Executive" />
                    <div class="grid gap-1.5">
                        <span class="text-xs font-medium text-ink-muted">Role in the Hub</span>
                        <span class="flex h-10 items-center rounded-lg border border-line bg-surface-muted px-3 text-sm text-ink-muted">{{ $user->role()?->label() }}</span>
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <label for="field-bio" class="text-xs font-medium text-ink-muted">About you</label>
                    <textarea id="field-bio" wire:model="bio" rows="3" maxlength="500" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none" placeholder="e.g. Covering Nairobi hotels and serviced apartments. Speaks English, Swahili and Kikuyu."></textarea>
                    @error('bio')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Emergency contact name" wire:model="emergency_contact_name" />
                    <x-ui.input label="Emergency contact phone" type="tel" wire:model="emergency_contact_phone" />
                </div>
                <p class="text-[13px] text-ink-subtle">To change your email, role or region, ask a Sales Admin.</p>
                <div><x-ui.button type="submit">Save profile</x-ui.button></div>
            </form>
        </x-ui.card>

        <div class="grid content-start gap-4">
            <x-ui.card title="Change password">
                <form wire:submit="changePassword" class="grid gap-4">
                    <x-ui.input label="Current password" type="password" wire:model="current_password" autocomplete="current-password" />
                    <x-ui.input label="New password" type="password" wire:model="password" autocomplete="new-password" hint="At least 10 characters, with letters and numbers." />
                    <x-ui.input label="Confirm new password" type="password" wire:model="password_confirmation" autocomplete="new-password" />
                    <div><x-ui.button type="submit">Change password</x-ui.button></div>
                </form>
            </x-ui.card>

            @if ($user->referralCode)
                <x-ui.card title="Referral code">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="font-mono text-lg text-brand-text">{{ $user->referralCode->code }}</span>
                        <span class="text-[13px] text-ink-subtle">Issued {{ $user->referralCode->created_at->format('j M Y') }} · permanent</span>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
