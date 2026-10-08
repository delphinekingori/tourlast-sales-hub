<?php

namespace Tests\Feature\Travel;

use App\Actions\SetTarget;
use App\Actions\Travel\RefreshBookingPayment;
use App\Actions\Travel\SetTravelTarget;
use App\Enums\Role;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TravelTargetMetric;
use App\Livewire\Travel\Targets;
use App\Models\AuditEvent;
use App\Models\FlightBooking;
use App\Models\InfluencerCode;
use App\Models\PackageBooking;
use App\Models\Target;
use App\Models\TravelPayment;
use App\Models\TravelRefund;
use App\Models\TravelTarget;
use App\Models\User;
use App\Support\Navigation;
use App\Support\Travel\InfluencerCodes;
use App\Support\Travel\TravelSalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TravelFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_travel_menu_starts_with_the_dashboard_and_ends_with_notifications(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();
        $items = collect(Navigation::for($travel))->flatMap(fn (array $section) => array_column($section['items'], 'label'));

        $this->assertNotContains('Home', $items);
        $this->assertSame('Travel dashboard', $items->first());
        $this->assertSame('Notifications', $items->last());
        $this->assertSame(1, $items->filter(fn (string $label) => $label === 'Notifications')->count());
    }

    public function test_travel_salesperson_lands_on_the_travel_dashboard(): void
    {
        $this->actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())
            ->get('/')
            ->assertRedirect(route('travel.dashboard'));
    }

    /**
     * @return array<string, array{Role, int}>
     */
    public static function dashboardAccess(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, 200],
            'sales admin' => [Role::SalesAdmin, 200],
            'travel salesperson' => [Role::TravelSalesperson, 200],
            'sales manager' => [Role::SalesManager, 403],
            'salesperson' => [Role::Salesperson, 403],
            'hr' => [Role::Hr, 403],
            'accounts' => [Role::Accounts, 403],
        ];
    }

    #[DataProvider('dashboardAccess')]
    public function test_only_travel_users_open_the_travel_dashboard(Role $role, int $status): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('travel.dashboard'))
            ->assertStatus($status);
    }

    public function test_sales_managers_do_not_see_travel_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create(['name' => 'Aisha Travel']);
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Property']);

        $this->actingAs($manager)->get(route('people.index'))->assertSee('John Property')->assertDontSee('Aisha Travel');
        $this->actingAs($manager)->get(route('team.index'))->assertDontSee('Aisha Travel');
        $this->actingAs($manager)->get(route('people.show', $travel))->assertNotFound();

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())
            ->get(route('people.index'))->assertSee('Aisha Travel');
    }

    public function test_sales_managers_cannot_suspend_travel_salespeople(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->assertFalse($manager->can('suspend', $travel));
        $this->assertTrue(User::factory()->withRole(Role::SalesAdmin)->create()->can('suspend', $travel));
    }

    public function test_sales_admin_can_invite_travel_salespeople_but_sales_managers_cannot(): void
    {
        $this->assertContains(Role::TravelSalesperson, Role::assignableBy(User::factory()->withRole(Role::SalesAdmin)->create()));
        $this->assertNotContains(Role::TravelSalesperson, Role::assignableBy(User::factory()->withRole(Role::SalesManager)->create()));
    }

    public function test_travel_targets_are_set_by_sales_admin_only(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();
        $month = CarbonImmutable::now()->startOfMonth();

        $this->actingAs($travel)->get(route('travel.targets.index'))->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())->get(route('travel.targets.index'))->assertForbidden();

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->actingAs($admin)->get(route('travel.targets.index'))->assertOk()->assertSee($travel->name);

        Livewire::actingAs($admin)->test(Targets::class)
            ->set("values.{$travel->id}.flight_bookings", '50')
            ->set("values.{$travel->id}.tour_revenue", '400000')
            ->call('save', $travel->id)
            ->assertHasNoErrors();

        $this->assertSame(50, TravelTarget::query()->where('user_id', $travel->id)->where('metric', 'flight_bookings')->value('target_value'));
        $this->assertSame(400000, TravelTarget::query()->where('user_id', $travel->id)->where('metric', 'tour_revenue')->value('target_value'));
        $this->assertTrue(AuditEvent::query()->where('action', 'travel_target.set')->exists());

        $this->expectException(HttpException::class);
        app(SetTravelTarget::class)->handle($travel, $travel, $month, TravelTargetMetric::FlightBookings, 999);
    }

    public function test_points_targets_and_travel_targets_do_not_mix(): void
    {
        $person = User::factory()->withRole(Role::Salesperson)->create();
        $month = CarbonImmutable::now()->startOfMonth()->addMonth(); // never locked

        TravelTarget::query()->create(['user_id' => $person->id, 'month' => $month, 'metric' => TravelTargetMetric::TourBookings, 'target_value' => 9]);
        app(SetTarget::class)->handle($person, $month, 18);

        $this->assertSame(18, Target::query()->where('user_id', $person->id)->whereDate('month', $month->toDateString())->value('target'));
        $this->assertSame(1, Target::query()->where('user_id', $person->id)->count());
        $this->assertSame(1, TravelTarget::query()->where('user_id', $person->id)->count());
    }

    public function test_travel_actuals_count_confirmed_bookings_and_uncancelled_flights(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create(['amount_total' => 50000]);
        PackageBooking::factory()->create(['salesperson_id' => $booking->salesperson_id]); // pending: not counted
        FlightBooking::factory()->create(['salesperson_id' => $booking->salesperson_id, 'booked_at' => now(), 'total_amount' => 20000]);
        FlightBooking::factory()->cancelled()->create(['salesperson_id' => $booking->salesperson_id, 'booked_at' => now()]);

        $actuals = TravelSalesMetrics::totals(now()->startOfMonth(), now()->endOfMonth(), $booking->salesperson_id);

        $this->assertSame(1.0, $actuals['tour_bookings']);
        $this->assertSame(50000.0, $actuals['tour_revenue']);
        $this->assertSame(1.0, $actuals['flight_bookings']);
        $this->assertSame(20000.0, $actuals['flight_revenue']);
    }

    public function test_booking_payment_status_comes_from_payments_and_refunds(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 90000]);
        $refresh = app(RefreshBookingPayment::class);

        TravelPayment::factory()->create(['package_booking_id' => $booking->id, 'amount' => 30000]);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $refresh->handle($booking)->payment_status);

        TravelPayment::factory()->manual()->create(['package_booking_id' => $booking->id, 'amount' => 60000]);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $refresh->handle($booking)->payment_status, 'Unconfirmed cash does not count.');

        TravelPayment::query()->whereNull('mpesa_receipt')->update(['confirmed_at' => now()]);
        $this->assertSame(BookingPaymentStatus::Paid, $refresh->handle($booking)->payment_status);
        $this->assertSame('90000.00', $booking->amount_paid);

        TravelRefund::query()->create(['package_booking_id' => $booking->id, 'amount' => 20000, 'reason' => 'One traveller cancelled', 'status' => RefundStatus::Completed, 'requested_by' => $booking->salesperson_id]);
        $this->assertSame(BookingPaymentStatus::PartiallyRefunded, $refresh->handle($booking)->payment_status);
    }

    public function test_influencer_codes_resolve_only_when_running_and_in_scope(): void
    {
        $code = InfluencerCode::factory()->create(['code' => 'amina10', 'applies_to' => InfluencerCodeScope::Packages]);

        $this->assertSame('AMINA10', $code->fresh()->code);
        $this->assertTrue($code->is(InfluencerCodes::resolve(' amina10 ', 'packages')));
        $this->assertNull(InfluencerCodes::resolve('AMINA10', 'flights'));
        $this->assertNull(InfluencerCodes::resolve('AMINA10', 'packages', today()->addYear()));

        $code->update(['status' => InfluencerCodeStatus::Paused]);
        $this->assertNull(InfluencerCodes::resolve('AMINA10', 'packages'));
    }

    public function test_slot_counts_ignore_expired_holds_and_cancelled_bookings(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create(['adults' => 3]);
        $departure = $booking->departure;
        PackageBooking::factory()->create(['package_departure_id' => $departure->id, 'adults' => 2]);
        PackageBooking::factory()->create(['package_departure_id' => $departure->id, 'adults' => 4, 'hold_expires_at' => now()->subMinute()]);
        PackageBooking::factory()->create(['package_departure_id' => $departure->id, 'adults' => 5, 'status' => TravelBookingStatus::Cancelled]);

        $fresh = $departure->fresh();
        $this->assertSame(3, $fresh->soldSlots());
        $this->assertSame(2, $fresh->reservedSlots());
        $this->assertSame(7, $fresh->availableSlots());

        $counted = $departure->newQuery()->withSlotCounts()->find($departure->id);
        $this->assertSame(3, $counted->soldSlots());
        $this->assertSame(2, $counted->reservedSlots());
    }

    public function test_audit_log_is_for_admins_only(): void
    {
        AuditEvent::query()->create(['action' => 'package.approved', 'summary' => 'Approved Masai Mara v1.0', 'created_at' => now()]);

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())
            ->get(route('admin.audit-log'))->assertOk()->assertSee('Approved Masai Mara v1.0');

        foreach ([Role::TravelSalesperson, Role::SalesManager, Role::Hr, Role::Accounts] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('admin.audit-log'))->assertForbidden();
        }
    }
}
