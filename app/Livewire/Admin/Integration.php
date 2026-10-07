<?php

namespace App\Livewire\Admin;

use App\Actions\RestoreOnboarding;
use App\Actions\SyncOnboardings;
use App\Enums\OnboardingStatus;
use App\Enums\Permission;
use App\Models\Onboarding;
use App\Models\OnboardingStatusChange;
use App\Models\ReferralCode;
use App\Models\SandboxProvider;
use App\Models\SyncRun;
use App\Support\SampleData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Connection status for tourlast.com, the sync log, and (while the source is
 * "sandbox") a simulator for trying the whole flow with sample signups.
 */
#[Title('Integration')]
class Integration extends Component
{
    public bool $showSample = false;

    /** @var array{ref_code: string, property_name: string, property_type: string, location: string, contact_name: string, contact_phone: string, contact_email: string} */
    public array $sample = [
        'ref_code' => '', 'property_name' => '', 'property_type' => 'hotel', 'location' => '',
        'contact_name' => '', 'contact_phone' => '', 'contact_email' => '',
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageIntegration->value), 403);
    }

    public function syncNow(bool $full = false): void
    {
        $run = app(SyncOnboardings::class)->handle($full ? 'full' : 'incremental');

        if (! $run->succeeded()) {
            $this->dispatch('toast', message: 'Sync failed. See the log below.', tone: 'danger');

            return;
        }

        $counts = "{$run->records_created} new, {$run->records_updated} updated";

        if ($run->records_deleted > 0) {
            $counts .= ", {$run->records_deleted} deleted";
        }

        $this->dispatch('toast', message: "Sync finished: {$counts}.");
    }

    /**
     * Put a property back that the source app lists again, with its lead and
     * registry record.
     */
    public function restore(int $onboardingId): void
    {
        abort_unless(Auth::user()->can(Permission::ManageIntegration->value), 403);

        $onboarding = Onboarding::onlyTrashed()->findOrFail($onboardingId);
        app(RestoreOnboarding::class)->handle($onboarding);

        $this->dispatch('toast', message: "{$onboarding->property_name} is back in the Hub.");
    }

    public function openSample(): void
    {
        $this->ensureSandbox();
        $this->resetValidation();
        $this->sample = [
            'ref_code' => (string) ReferralCode::query()->where('is_active', true)->value('code'),
            'property_name' => '', 'property_type' => 'hotel', 'location' => '',
            'contact_name' => '', 'contact_phone' => '', 'contact_email' => '',
        ];
        $this->showSample = true;
    }

    public function createSample(): void
    {
        $this->ensureSandbox();

        $this->validate([
            'sample.ref_code' => ['nullable', 'string', 'max:40'],
            'sample.property_name' => ['required', 'string', 'max:190'],
            'sample.property_type' => ['required', Rule::in(array_keys(config('hub.property_types')))],
            'sample.location' => ['nullable', 'string', 'max:190'],
            'sample.contact_name' => ['nullable', 'string', 'max:190'],
            'sample.contact_phone' => ['nullable', 'string', 'max:40'],
            'sample.contact_email' => ['nullable', 'email', 'max:190'],
        ], [], ['sample.property_name' => 'property name']);

        SandboxProvider::create([
            ...$this->sample,
            'ref_code' => $this->sample['ref_code'] ?: null,
            'property_id' => 'SBX-'.Str::upper(Str::random(6)),
            'status' => OnboardingStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        app(SyncOnboardings::class)->handle();
        $this->showSample = false;
        $this->dispatch('toast', message: 'Sample signup created and synced.');
    }

    public function advance(int $providerId, string $status): void
    {
        $this->ensureSandbox();

        $status = OnboardingStatus::from($status);
        $provider = SandboxProvider::findOrFail($providerId);
        $timestamp = match ($status) {
            OnboardingStatus::Approved => 'approved_at',
            OnboardingStatus::Active => 'active_at',
            OnboardingStatus::Inactive => 'inactive_at',
            OnboardingStatus::Rejected => 'rejected_at',
            default => null,
        };

        $provider->status = $status->value;

        if ($timestamp) {
            $provider->{$timestamp} = now();
        }

        $provider->save();
        app(SyncOnboardings::class)->handle();

        $this->dispatch('toast', message: "{$provider->property_name} is now {$status->label()}.");
    }

    public function recordBooking(int $providerId): void
    {
        $this->ensureSandbox();
        $provider = SandboxProvider::findOrFail($providerId);
        $provider->update(['first_booking_at' => now()]);
        app(SyncOnboardings::class)->handle();

        $this->dispatch('toast', message: "First booking recorded for {$provider->property_name}.");
    }

    public function render(): View
    {
        $source = config('tourlast.source');
        $isSandbox = $source === 'sandbox' && SampleData::allowed();

        return view('livewire.admin.integration', [
            'source' => $source,
            'isSandbox' => $isSandbox,
            'webhookEnabled' => filled(config('tourlast.webhook_secret')),
            'webhookUrl' => route('webhooks.tourlast'),
            'lastSuccess' => SyncRun::query()->where('status', 'succeeded')->latest('started_at')->first(),
            'runs' => SyncRun::query()->latest('started_at')->limit(12)->get(),
            'recentChanges' => OnboardingStatusChange::query()->with('onboarding.user')->latest('id')->limit(10)->get(),
            'deleted' => Onboarding::onlyTrashed()->with('user:id,name')->latest('submitted_at')->limit(20)->get(),
            'sandboxProviders' => $isSandbox ? SandboxProvider::query()->latest()->limit(15)->get() : collect(),
            'referralCodes' => ReferralCode::query()->with('user')->where('is_active', true)->orderBy('code')->get(),
            'statuses' => OnboardingStatus::cases(),
        ]);
    }

    private function ensureSandbox(): void
    {
        abort_unless(SampleData::allowed(), 403, 'The simulator only works in local development.');
        abort_unless(config('tourlast.source') === 'sandbox', 403, 'The simulator only works while TOURLAST_SOURCE=sandbox.');
        abort_unless(Auth::user()->can(Permission::ManageIntegration->value), 403);
    }
}
