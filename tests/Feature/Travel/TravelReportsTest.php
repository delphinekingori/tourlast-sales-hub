<?php

namespace Tests\Feature\Travel;

use App\Enums\Role;
use App\Livewire\Travel\Reports;
use App\Models\FlightBooking;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Travel\TravelSalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TravelReportsTest extends TestCase
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
            'accounts' => [Role::Accounts, 200],
            'sales manager' => [Role::SalesManager, 403],
            'hr' => [Role::Hr, 403],
            'salesperson' => [Role::Salesperson, 403],
        ];
    }

    #[DataProvider('access')]
    public function test_who_can_open_travel_reports(Role $role, int $status): void
    {
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)->get(route('travel.reports.index'))->assertStatus($status);
        $this->actingAs($user)->get(route('travel.reports.export'))->assertStatus($status);
    }

    public function test_salesperson_report_covers_only_their_own_sales(): void
    {
        $mine = PackageBooking::factory()->confirmed()->create(['amount_total' => 61000]);
        $theirs = PackageBooking::factory()->confirmed()->create(['amount_total' => 99000]);
        FlightBooking::factory()->create(['salesperson_id' => $mine->salesperson_id, 'booked_at' => now(), 'origin' => 'NBO', 'destination' => 'MBA']);
        FlightBooking::factory()->create(['salesperson_id' => $theirs->salesperson_id, 'booked_at' => now(), 'origin' => 'NBO', 'destination' => 'DXB']);

        $this->actingAs($mine->salesperson)->get(route('travel.reports.index', ['tab' => 'tours']))
            ->assertOk()->assertSee('61,000')->assertDontSee('99,000');

        $this->actingAs($mine->salesperson)->get(route('travel.reports.index', ['tab' => 'flights']))
            ->assertSee('NBO → MBA')->assertDontSee('NBO → DXB')->assertDontSee('Markup');

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())
            ->get(route('travel.reports.index', ['tab' => 'flights']))
            ->assertSee('NBO → MBA')->assertSee('NBO → DXB')->assertSee('Markup');
    }

    public function test_the_sales_trend_buckets_by_day_week_or_month_and_keeps_empty_buckets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00'));
        $booking = PackageBooking::factory()->confirmed()->create(['amount_total' => 40000, 'created_at' => '2026-10-03 09:00']);
        FlightBooking::factory()->create(['salesperson_id' => $booking->salesperson_id, 'booked_at' => '2026-10-03 10:00', 'total_amount' => 15000]);
        FlightBooking::factory()->create(['salesperson_id' => $booking->salesperson_id, 'booked_at' => '2026-08-20 10:00', 'total_amount' => 5000]);

        $days = TravelSalesMetrics::trend(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertCount(31, $days);
        $this->assertSame(['label' => '3 Oct', 'starts' => '2026-10-03', 'flight_bookings' => 1, 'flight_revenue' => 15000.0, 'tour_bookings' => 1, 'tour_revenue' => 40000.0], $days[2]);
        $this->assertSame(0.0, $days[0]['flight_revenue']);

        $weeks = TravelSalesMetrics::trend(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame('2026-07-27', $weeks[0]['starts']);
        $this->assertSame(60000.0, array_sum(array_column($weeks, 'flight_revenue')) + array_sum(array_column($weeks, 'tour_revenue')));

        $months = TravelSalesMetrics::trend(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), $booking->salesperson_id);
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], array_column($months, 'label'));
        $this->assertSame(5000.0, $months[7]['flight_revenue']);
        $this->assertSame(40000.0, $months[9]['tour_revenue']);

        $this->assertSame([], array_filter(TravelSalesMetrics::trend(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $booking->salesperson_id + 999), fn ($bucket) => $bucket['flight_bookings'] || $bucket['tour_bookings']));
    }

    public function test_charts_render_on_the_reports_and_dashboard(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create(['amount_total' => 61000]);
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->actingAs($admin)->get(route('travel.reports.index', ['tab' => 'tours']))
            ->assertOk()->assertSee('Tour revenue')->assertSee('Show as table')->assertSee('bg-series-2', false);

        $this->actingAs($booking->salesperson)->get(route('travel.dashboard'))
            ->assertOk()->assertSee('My travel sales, last 6 months')->assertSee('KES 61,000');
    }

    public function test_every_tab_renders_and_the_export_downloads(): void
    {
        PackageBooking::factory()->paid()->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        foreach (array_keys(Reports::Tabs) as $tab) {
            $this->actingAs($admin)->get(route('travel.reports.index', ['tab' => $tab, 'period' => 'year']))->assertOk();
        }

        $this->actingAs($admin)->get(route('travel.reports.export', ['period' => 'year']))
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
