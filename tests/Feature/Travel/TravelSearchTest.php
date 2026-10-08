<?php

namespace Tests\Feature\Travel;

use App\Enums\Role;
use App\Livewire\Travel\Search;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\ProviderContract;
use App\Models\TravelClient;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\TravelSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TravelSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_search_returns_its_contracts_packages_and_bookings_count(): void
    {
        $provider = TravelProvider::factory()->create(['name' => 'ABC Safaris']);
        $contract = ProviderContract::factory()->create(['travel_provider_id' => $provider->id, 'contract_number' => 'TL-2026-042']);
        $package = Package::factory()->published()->create(['name' => 'Masai Mara 3-Day Safari', 'travel_provider_id' => $provider->id, 'provider_contract_id' => $contract->id]);
        PackageBooking::factory()->confirmed()->create(['package_departure_id' => PackageDeparture::factory()->create(['package_id' => $package->id])->id]);

        $groups = collect((new TravelSearch($provider->owner))->run('ABC Safaris'))->keyBy('key');

        $this->assertSame('ABC Safaris', $groups['providers']['items'][0]['title']);
        $this->assertStringContainsString('1 booking', $groups['providers']['items'][0]['meta']);
        $this->assertStringContainsString('TL-2026-042', $groups['contracts']['items'][0]['title']);
        $this->assertSame('Masai Mara 3-Day Safari', $groups['packages']['items'][0]['title']);

        $this->actingAs($provider->owner)->get(route('travel.search', ['q' => 'ABC Safaris']))
            ->assertOk()->assertSee('Providers (1)')->assertSee('TL-2026-042')->assertSee('Masai Mara 3-Day Safari');
    }

    public function test_salesperson_cannot_find_another_salespersons_bookings_clients_or_influencers(): void
    {
        $theirs = PackageBooking::factory()->create();
        $theirs->client->update(['name' => 'Zawadi Hidden']);
        $code = InfluencerCode::factory()->create(['code' => 'HIDDEN10', 'influencer_id' => Influencer::factory()->create(['name' => 'Hidden Influencer'])->id]);

        $other = User::factory()->withRole(Role::TravelSalesperson)->create();
        $search = new TravelSearch($other);

        $this->assertSame([], $search->run($theirs->reference));
        $this->assertSame([], $search->run('Zawadi Hidden'));
        $this->assertSame([], $search->run('HIDDEN10'));

        $mine = new TravelSearch($theirs->salesperson);
        $this->assertSame(['bookings'], array_column($mine->run($theirs->reference), 'key'));
        $this->assertContains('influencers', array_column((new TravelSearch($code->influencer->owner))->run('HIDDEN10'), 'key'));

        $admin = new TravelSearch(User::factory()->withRole(Role::SalesAdmin)->create());
        $this->assertContains('clients', array_column($admin->run('Zawadi Hidden'), 'key'));
    }

    public function test_accounts_only_get_bookings_clients_and_payments(): void
    {
        TravelProvider::factory()->create(['name' => 'Zebra Plains Tours']);
        $booking = PackageBooking::factory()->create();
        $booking->client->update(['name' => 'Zebra Plains Guest']);

        $keys = array_column((new TravelSearch(User::factory()->withRole(Role::Accounts)->create()))->run('Zebra Plains'), 'key');

        // The provider matches too, but Accounts never see providers.
        $this->assertSame(['bookings', 'clients'], $keys);
    }

    public function test_short_terms_return_nothing(): void
    {
        TravelClient::factory()->create(['name' => 'A']);

        Livewire::actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())
            ->test(Search::class)->set('q', 'A')->assertSee('Type at least two characters');
    }

    /**
     * @return array<string, array{Role, int}>
     */
    public static function access(): array
    {
        return [
            'travel salesperson' => [Role::TravelSalesperson, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'accounts' => [Role::Accounts, 200],
            'sales manager' => [Role::SalesManager, 403],
            'hr' => [Role::Hr, 403],
            'salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('access')]
    public function test_who_can_search(Role $role, int $status): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('travel.search', ['q' => 'safari']))
            ->assertStatus($status);
    }
}
