<?php

namespace Tests\Feature\Travel\Providers;

use App\Enums\Role;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Notifications\SmartAlert;
use App\Support\Travel\ContractCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ContractRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_check_passes_for_an_active_provider_with_a_contract_in_force(): void
    {
        $contract = ProviderContract::factory()->create();

        $this->assertSame([], ContractCheck::forPackage($contract, $contract->provider));
    }

    public function test_contract_check_lists_what_blocks_publishing(): void
    {
        $provider = TravelProvider::factory()->create(['status' => TravelProviderStatus::Suspended, 'phone' => null, 'email' => null, 'primary_contact_phone' => null]);

        $this->assertSame(['Provider is active', 'Provider contact details', 'Active provider contract'], ContractCheck::forPackage(null, $provider));

        $expired = ProviderContract::factory()->expired()->create(['cancellation_terms' => null, 'commission_rate' => null]);
        $this->assertSame(['Contract not expired', 'Cancellation terms', 'Commission rates'], ContractCheck::forPackage($expired, $expired->provider));

        $draft = ProviderContract::factory()->draft()->create();
        $this->assertSame(['Active provider contract'], ContractCheck::forPackage($draft, $draft->provider));

        $other = ProviderContract::factory()->create();
        $this->assertContains('Active provider contract', ContractCheck::forPackage($other, $draft->provider));
    }

    public function test_expiry_alerts_go_once_per_threshold_to_managers_and_the_owner(): void
    {
        Notification::fake();
        $contract = ProviderContract::factory()->endingIn(25)->create();
        $owner = $contract->provider->owner;
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();

        $this->artisan('travel:contract-alerts')->expectsOutputToContain('1 contract expiry alert sent')->assertSuccessful();
        $this->artisan('travel:contract-alerts')->expectsOutputToContain('0 contract expiry alerts sent');

        Notification::assertSentTo([$owner, $admin], SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'contract_expiring' && str_contains($alert->title, 'expires in 25 days'));
        Notification::assertNotSentTo([$manager, $hr], SmartAlert::class);
        $this->assertSame(30, $contract->fresh()->last_expiry_alert_days);

        $this->travel(12)->days();
        $this->artisan('travel:contract-alerts')->expectsOutputToContain('1 contract expiry alert sent');
        $this->assertSame(14, $contract->fresh()->last_expiry_alert_days);

        $this->travel(14)->days();
        $this->artisan('travel:contract-alerts')->expectsOutputToContain('1 contract expiry alert sent');
        $this->artisan('travel:contract-alerts')->expectsOutputToContain('0 contract expiry alerts sent');
        $this->assertSame(0, $contract->fresh()->last_expiry_alert_days);
        Notification::assertSentTo($owner, SmartAlert::class, fn (SmartAlert $alert) => str_contains($alert->title, 'has expired'));
    }

    public function test_drafts_and_open_ended_contracts_get_no_expiry_alerts(): void
    {
        Notification::fake();
        ProviderContract::factory()->draft()->endingIn(5)->create();
        ProviderContract::factory()->create(['ends_on' => null]);
        ProviderContract::factory()->create(['status' => ContractStatus::Active, 'ends_on' => today()->addDays(90)]);

        $this->artisan('travel:contract-alerts')->expectsOutputToContain('0 contract expiry alerts sent');
        Notification::assertNothingSent();
    }
}
