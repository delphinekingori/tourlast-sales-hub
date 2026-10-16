<?php

namespace Tests\Feature\Travel\Flights;

use App\Actions\Travel\Flights\SyncFlights;
use App\Enums\Role;
use App\Livewire\Travel\Flights\Index;
use App\Livewire\Travel\Flights\Show;
use App\Models\FlightBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class FlightPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role, int}>
     */
    public static function access(): array
    {
        return [
            'travel salesperson' => [Role::TravelSalesperson, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'super admin' => [Role::SuperAdmin, 200],
            'sales manager' => [Role::SalesManager, 403],
            'hr' => [Role::Hr, 403],
            'accounts' => [Role::Accounts, 403],
            'salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('access')]
    public function test_who_can_open_the_flights_pages(Role $role, int $status): void
    {
        $booking = FlightBooking::factory()->create();
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)->get(route('travel.flights.index'))->assertStatus($status);
        $this->actingAs($user)->get(route('travel.flights.show', $booking))->assertStatus($status);
    }

    public function test_every_view_renders_with_synced_data(): void
    {
        app(SyncFlights::class)->handle('full');
        $user = User::factory()->withRole(Role::SalesAdmin)->create();

        foreach (array_keys(Index::Views) as $view) {
            $this->actingAs($user)->get(route('travel.flights.index', ['view' => $view]))
                ->assertOk()
                ->assertSee('Test data — the Flights Super Admin connection isn', false);
        }

        $this->actingAs($user)->get(route('travel.flights.show', FlightBooking::query()->whereNotNull('refund_status')->first()))
            ->assertOk()
            ->assertSee('Open in Flights Admin')
            ->assertSee('Cancellation &amp; refund', false);
    }

    public function test_filters_and_my_bookings(): void
    {
        $aisha = User::factory()->withRole(Role::TravelSalesperson)->create();
        FlightBooking::factory()->create(['booking_reference' => 'TLFMINE', 'salesperson_id' => $aisha->id, 'airline_code' => 'JM']);
        FlightBooking::factory()->create(['booking_reference' => 'TLFOTHER', 'airline_code' => 'KQ']);

        Livewire::actingAs($aisha)->test(Index::class)
            ->assertSee('TLFMINE')->assertSee('TLFOTHER')
            ->set('mine', true)->assertSee('TLFMINE')->assertDontSee('TLFOTHER')
            ->set('mine', false)->set('airline', 'KQ')->assertSee('TLFOTHER')->assertDontSee('TLFMINE')
            ->call('sortBy', 'total_amount')->assertOk()
            ->set('view', 'customers')->assertOk();
    }

    public function test_customer_contact_is_masked_except_for_the_seller_and_managers(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $colleague = User::factory()->withRole(Role::TravelSalesperson)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $booking = FlightBooking::factory()->create([
            'salesperson_id' => $seller->id,
            'customer_email' => 'jane.doe@example.com',
            'customer_phone' => '0712345678',
            'markup_amount' => 777,
        ]);

        $this->actingAs($colleague)->get(route('travel.flights.show', $booking))
            ->assertDontSee('jane.doe@example.com')->assertDontSee('0712345678')
            ->assertSee('j***@example.com')->assertSee('0712 *** 678')
            ->assertDontSee('Tourlast markup');

        $this->actingAs($seller)->get(route('travel.flights.show', $booking))->assertSee('jane.doe@example.com')->assertSee('0712345678');
        $this->actingAs($admin)->get(route('travel.flights.show', $booking))->assertSee('jane.doe@example.com')->assertSee('Tourlast markup');
    }

    public function test_stale_data_is_labelled(): void
    {
        FlightBooking::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())
            ->get(route('travel.flights.index'))
            ->assertSee('Flight data has not been synchronized yet');
    }

    public function test_the_hub_has_no_way_to_change_a_flight_booking(): void
    {
        // The only non-GET flights route is the Flights Super Admin push.
        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'flights') && array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($route) => $route->getName())
            ->values()
            ->all();
        $this->assertSame(['api.v1.integrations.flights.push'], $writeRoutes);

        // The pages expose no public action other than browsing.
        foreach ([Index::class => ['mount', 'updating', 'updatedView', 'sortBy', 'clearFilters', 'render'], Show::class => ['mount', 'render']] as $class => $allowed) {
            $actions = collect((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $method) => $method->class === $class && $method->getFileName() === (new ReflectionClass($class))->getFileName())
                ->map->getName()
                ->all();
            $this->assertEqualsCanonicalizing($allowed, $actions, "{$class} must stay read-only.");
        }

        // Calling an invented mutation fails and the booking is unchanged.
        $booking = FlightBooking::factory()->create(['booking_status' => 'confirmed']);
        $admin = User::factory()->withRole(Role::SuperAdmin)->create();

        try {
            Livewire::actingAs($admin)->test(Show::class, ['booking' => $booking])->call('cancel');
        } catch (\Throwable) {
            // Expected: there is no such action.
        }

        try {
            Livewire::actingAs($admin)->test(Show::class, ['booking' => $booking])->set('bookingId', 999);
        } catch (\Throwable) {
            // Expected: the id is locked.
        }

        $this->assertSame('confirmed', $booking->fresh()->booking_status);
    }

    public function test_ticket_numbers_show_in_the_table_and_are_searchable(): void
    {
        $booking = FlightBooking::factory()->create(['booking_reference' => 'TLF55501', 'passenger_count' => 2]);
        $booking->passengers()->createMany([
            ['name' => 'Ann Otieno', 'passenger_type' => 'adult', 'ticket_number' => '7062451234567'],
            ['name' => 'Ben Otieno', 'passenger_type' => 'adult', 'ticket_number' => '7062451234568'],
        ]);
        FlightBooking::factory()->create(['booking_reference' => 'TLF55502']);
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->actingAs($travel)->get(route('travel.flights.index'))
            ->assertOk()
            ->assertSee('Ticket no.')
            ->assertSee('7062451234567')
            ->assertSee('+1 more');

        $this->actingAs($travel)->get(route('travel.flights.index', ['q' => '7062451234568']))
            ->assertSee('TLF55501')
            ->assertDontSee('TLF55502');
    }

    public function test_summary_stats_refresh_when_bookings_arrive(): void
    {
        $user = User::factory()->withRole(Role::TravelSalesperson)->create();
        FlightBooking::factory()->create(['booked_at' => now(), 'total_amount' => 10000]);

        $before = Livewire::actingAs($user)->test(Index::class)->html();
        $this->assertSame('1', $this->stat($before, 'Bookings today'));
        $this->assertSame('1', $this->stat($before, 'Bookings this month'));
        $this->assertSame('0', $this->stat($before, 'Refunds pending'));
        $this->assertSame('USD 10,000', $this->stat($before, 'Booking revenue (month)'));
        $this->assertStringContainsString('wire:poll.visible.30s', $before);

        // A sync/push lands new rows; the next render (what the poll issues) recomputes every stat.
        FlightBooking::factory()->create(['booked_at' => now(), 'total_amount' => 20000, 'refund_status' => 'pending', 'refund_requested_at' => now()]);
        FlightBooking::factory()->create(['booked_at' => now()->subMonth(), 'total_amount' => 5000, 'refund_status' => 'completed', 'refund_completed_at' => now()]);

        $after = Livewire::actingAs($user)->test(Index::class)->html();
        $this->assertSame('2', $this->stat($after, 'Bookings today'));
        $this->assertSame('2', $this->stat($after, 'Bookings this month'));
        $this->assertSame('1', $this->stat($after, 'Refunds pending'));
        $this->assertSame('1', $this->stat($after, 'Refunds completed'));
        $this->assertSame('USD 30,000', $this->stat($after, 'Booking revenue (month)'));
    }

    public function test_the_table_paginates_eight_bookings_per_page(): void
    {
        foreach (range(1, 10) as $i) {
            FlightBooking::factory()->create([
                'booking_reference' => sprintf('PAGE%02d', $i),
                'booked_at' => now()->subMinutes(100 - $i),
            ]);
        }
        $user = User::factory()->withRole(Role::TravelSalesperson)->create();

        // Default sort is booked_at desc: page 1 holds PAGE10…PAGE03 only.
        $page1 = Livewire::actingAs($user)->test(Index::class);
        $page1->assertSee('10 bookings')
            ->assertSee('PAGE10')->assertSee('PAGE03')
            ->assertDontSee('PAGE01')->assertDontSee('PAGE02');

        $page1->call('gotoPage', 2)
            ->assertSee('PAGE02')->assertSee('PAGE01')
            ->assertDontSee('PAGE10');
    }

    /**
     * The <dd> value paired with a <dt> label in the summary row.
     */
    private function stat(string $html, string $label): string
    {
        preg_match('/<dt[^>]*>'.preg_quote($label, '/').'<\/dt>\s*<dd[^>]*>([^<]+)<\/dd>/', $html, $matches);

        return trim($matches[1] ?? '');
    }
}
