<?php

namespace Tests\Feature\Travel\Influencers;

use App\Actions\Travel\Influencers\SyncInfluencerCommission;
use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCommissionType;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Events\FlightBookingSynced;
use App\Models\AuditEvent;
use App\Models\FlightBooking;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\TravelRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfluencerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function bookingWith(InfluencerCode $code, array $attributes = []): PackageBooking
    {
        return PackageBooking::factory()->create(['influencer_code_id' => $code->id, 'amount_total' => 90000, ...$attributes]);
    }

    private function pay(PackageBooking $booking, float $amount): void
    {
        TravelPayment::factory()->create(['package_booking_id' => $booking->id, 'amount' => $amount]);
        app(RefreshBookingPayment::class)->handle($booking);
    }

    private function line(PackageBooking|FlightBooking $booking): ?InfluencerCommission
    {
        return InfluencerCommission::query()->where('bookable_type', $booking->getMorphClass())->where('bookable_id', $booking->id)->first();
    }

    public function test_percentage_commission_is_pending_then_payable_once_fully_paid(): void
    {
        $code = InfluencerCode::factory()->create(['commission_value' => 10]);
        $booking = $this->bookingWith($code);

        $this->pay($booking, 40000);
        $line = $this->line($booking);
        $this->assertSame(CommissionEntryStatus::Pending, $line->status);
        $this->assertEquals(9000, (float) $line->commission_amount);
        $this->assertEquals(90000, (float) $line->booking_amount);

        $this->pay($booking, 50000);
        $this->assertSame(CommissionEntryStatus::Payable, $line->fresh()->status);
    }

    public function test_fixed_commission_is_the_same_whatever_the_booking_amount(): void
    {
        $code = InfluencerCode::factory()->create(['commission_type' => InfluencerCommissionType::Fixed, 'commission_value' => 1500]);
        $booking = $this->bookingWith($code, ['amount_total' => 250000]);

        $this->pay($booking, 250000);

        $this->assertEquals(1500, (float) $this->line($booking)->commission_amount);
    }

    public function test_running_the_sync_twice_changes_nothing(): void
    {
        $code = InfluencerCode::factory()->create(['max_bookings' => 1]);
        $booking = $this->bookingWith($code);
        $this->pay($booking, 90000);
        $other = $this->bookingWith($code);

        $sync = app(SyncInfluencerCommission::class);
        $sync->handle($booking);
        $sync->handle($booking);
        $sync->handle($other);
        $sync->handle($other);

        $this->assertSame(1, InfluencerCommission::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'influencer.code_limit_reached')->count());
    }

    public function test_the_booking_limit_is_enforced_and_a_cancelled_line_frees_a_slot(): void
    {
        $code = InfluencerCode::factory()->create(['max_bookings' => 2]);
        $sync = app(SyncInfluencerCommission::class);

        $first = $this->bookingWith($code);
        $second = $this->bookingWith($code);
        $third = $this->bookingWith($code);
        foreach ([$first, $second, $third] as $booking) {
            $sync->handle($booking);
        }

        $this->assertNotNull($this->line($first));
        $this->assertNotNull($this->line($second));
        $this->assertNull($this->line($third));

        $first->forceFill(['status' => TravelBookingStatus::Cancelled, 'cancelled_at' => now()])->save();
        app(RefreshBookingPayment::class)->handle($first);
        $this->assertSame(CommissionEntryStatus::Cancelled, $this->line($first)->status);

        $sync->handle($third);
        $this->assertNotNull($this->line($third));
    }

    public function test_only_bookings_made_within_the_code_dates_earn(): void
    {
        $code = InfluencerCode::factory()->create(['starts_on' => today()->subDays(10), 'ends_on' => today()->subDays(2)]);
        $sync = app(SyncInfluencerCommission::class);

        $late = $this->bookingWith($code);
        $sync->handle($late);
        $this->assertNull($this->line($late));

        $inTime = $this->bookingWith($code);
        $inTime->forceFill(['created_at' => now()->subDays(5)])->save();
        $sync->handle($inTime);
        $this->assertNotNull($this->line($inTime));
    }

    public function test_cancellation_cancels_the_line_but_a_paid_line_stays_paid(): void
    {
        $code = InfluencerCode::factory()->create();
        $booking = $this->bookingWith($code);
        $this->pay($booking, 90000);

        $this->line($booking)->forceFill(['status' => CommissionEntryStatus::Paid, 'paid_at' => now()])->save();

        $booking->forceFill(['status' => TravelBookingStatus::Cancelled])->save();
        app(RefreshBookingPayment::class)->handle($booking);
        app(RefreshBookingPayment::class)->handle($booking);

        $this->assertSame(CommissionEntryStatus::Paid, $this->line($booking)->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'influencer.commission_paid_on_refund')->count());
    }

    public function test_a_partly_refunded_booking_earns_on_what_the_client_kept(): void
    {
        $code = InfluencerCode::factory()->create(['commission_value' => 10]);
        $booking = $this->bookingWith($code);
        $this->pay($booking, 90000);

        TravelRefund::query()->create([
            'package_booking_id' => $booking->id,
            'amount' => 30000,
            'reason' => 'One traveller dropped out',
            'status' => RefundStatus::Completed,
            'requested_by' => $booking->salesperson_id,
        ]);
        app(RefreshBookingPayment::class)->handle($booking);

        $line = $this->line($booking);
        $this->assertSame(CommissionEntryStatus::Payable, $line->status);
        $this->assertEquals(60000, (float) $line->booking_amount);
        $this->assertEquals(6000, (float) $line->commission_amount);
    }

    public function test_a_fully_refunded_booking_cancels_unpaid_commission(): void
    {
        $code = InfluencerCode::factory()->create();
        $booking = $this->bookingWith($code);
        $this->pay($booking, 90000);

        TravelRefund::query()->create([
            'package_booking_id' => $booking->id,
            'amount' => 90000,
            'reason' => 'Trip cancelled',
            'status' => RefundStatus::Completed,
            'requested_by' => $booking->salesperson_id,
        ]);
        app(RefreshBookingPayment::class)->handle($booking);

        $this->assertSame(CommissionEntryStatus::Cancelled, $this->line($booking)->status);
    }

    public function test_paid_flight_bookings_earn_through_the_sync_event(): void
    {
        $code = InfluencerCode::factory()->create(['applies_to' => InfluencerCodeScope::Flights, 'commission_value' => 2]);
        $flight = FlightBooking::factory()->create([
            'influencer_code_id' => $code->id,
            'total_amount' => 50000,
            'payment_status' => 'Ticketed',
            'booked_at' => now(),
        ]);

        FlightBookingSynced::dispatch($flight, true);

        $line = $this->line($flight);
        $this->assertSame(CommissionEntryStatus::Payable, $line->status);
        $this->assertEquals(1000, (float) $line->commission_amount);

        $flight->forceFill(['cancellation_status' => 'cancelled'])->save();
        FlightBookingSynced::dispatch($flight, false);
        $this->assertSame(CommissionEntryStatus::Cancelled, $line->fresh()->status);
    }

    public function test_unpaid_flight_bookings_are_pending(): void
    {
        $code = InfluencerCode::factory()->create(['applies_to' => InfluencerCodeScope::All]);
        $flight = FlightBooking::factory()->create(['influencer_code_id' => $code->id, 'payment_status' => 'awaiting_payment', 'booked_at' => now()]);

        FlightBookingSynced::dispatch($flight, true);

        $this->assertSame(CommissionEntryStatus::Pending, $this->line($flight)->status);
    }

    public function test_bookings_without_a_code_earn_nothing(): void
    {
        $booking = PackageBooking::factory()->create();
        $this->pay($booking, 90000);

        $this->assertSame(0, InfluencerCommission::query()->count());
    }
}
