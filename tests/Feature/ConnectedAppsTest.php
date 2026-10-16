<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Admin\ConnectedApps;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectedAppsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admins_can_open_the_page(): void
    {
        foreach ([Role::SalesAdmin, Role::SalesManager, Role::Salesperson, Role::TravelSalesperson, Role::Hr, Role::Accounts] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('admin.connected-apps'))->assertForbidden();
        }

        $this->actingAs($this->superAdmin())->get(route('admin.connected-apps'))->assertOk();
    }

    public function test_the_check_reports_reachable_feeds_online(): void
    {
        $this->configureApiFeeds();
        Http::fake([
            'stays.test/*' => Http::response(['data' => [], 'next_page' => null]),
            'flights.test/*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        ]);
        Http::preventStrayRequests();

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('Online')
            ->assertSee('2 of 2')
            ->assertSee('stays.test')
            ->assertSee('flights.test');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'stays.test/api/sales-hub/referrals')
            && $request->hasHeader('Authorization', 'Bearer shared-token'));
    }

    public function test_the_check_reports_unreachable_feeds_offline(): void
    {
        $this->configureApiFeeds();
        Http::fake(['*' => Http::failedConnection()]);

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('Offline')
            ->assertSee('0 of 2');
    }

    public function test_the_check_flags_a_rejected_token(): void
    {
        $this->configureApiFeeds();
        Http::fake(['*' => Http::response(['message' => 'unauthenticated'], 401)]);

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('Token rejected')
            ->assertSee('HTTP 401');
    }

    public function test_a_feed_that_is_missing_is_flagged(): void
    {
        $this->configureApiFeeds();
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('Feed missing');
    }

    public function test_sandbox_and_push_modes_send_no_requests(): void
    {
        config(['tourlast.source' => 'sandbox', 'travel.flights.source' => 'push']);
        Http::fake();

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('Sample data')
            ->assertSee('Push mode');

        Http::assertNothingSent();
    }

    public function test_a_missing_token_is_flagged_without_calling_out(): void
    {
        config([
            'tourlast.source' => 'api',
            'tourlast.api.base_url' => 'http://stays.test',
            'tourlast.api.token' => '',
        ]);
        Http::fake();

        Livewire::actingAs($this->superAdmin())->test(ConnectedApps::class)
            ->call('check')
            ->assertSee('No token');

        Http::assertNothingSent();
    }

    private function superAdmin(): User
    {
        return User::factory()->withRole(Role::SuperAdmin)->create();
    }

    private function configureApiFeeds(): void
    {
        config([
            'tourlast.source' => 'api',
            'tourlast.api.base_url' => 'http://stays.test',
            'tourlast.api.path' => '/api/sales-hub/referrals',
            'tourlast.api.token' => 'shared-token',
            'travel.flights.source' => 'api',
            'travel.flights.api_url' => 'http://flights.test',
            'travel.flights.api_token' => 'shared-token',
        ]);
    }
}
