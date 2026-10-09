<?php

namespace Tests\Feature\Travel\Payments;

use App\Actions\Travel\Payments\ConfirmManualPayment;
use App\Actions\Travel\Payments\RecordManualPayment;
use App\Actions\Travel\Payments\RequestMpesaPayment;
use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Role;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\PaymentStatus;
use App\Livewire\Travel\Payments\BookingPayments;
use App\Livewire\Travel\Payments\Index;
use App\Models\AuditEvent;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['travel.mpesa.driver' => 'sandbox']);
    }

    private function manual(PackageBooking $booking, User $by, float $amount = 30000): TravelPayment
    {
        return app(RecordManualPayment::class)->handle($booking, [
            'method' => 'cash', 'amount' => $amount, 'reference' => '', 'paid_on' => today()->toDateString(), 'notes' => '',
        ], $by);
    }

    public function test_a_manual_payment_counts_only_once_accounts_confirms_it(): void
    {
        $booking = PackageBooking::factory()->create();
        $payment = $this->manual($booking, $booking->salesperson);

        app(RefreshBookingPayment::class)->handle($booking);
        $this->assertSame(BookingPaymentStatus::Unpaid, $booking->fresh()->payment_status);

        $accounts = User::factory()->withRole(Role::Accounts)->create();
        Livewire::actingAs($accounts)->test(Index::class)->set('tab', 'awaiting')->assertSee($booking->reference)->call('confirm', $payment->id);

        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame(30000.0, (float) $booking->fresh()->amount_paid);
        $this->assertTrue(AuditEvent::query()->where('action', 'payment.manual_confirmed')->exists());
    }

    public function test_salespeople_cannot_confirm_or_allocate(): void
    {
        $booking = PackageBooking::factory()->create();
        $payment = $this->manual($booking, $booking->salesperson);
        $unmatched = TravelPayment::factory()->create(['package_booking_id' => null, 'account_reference' => 'WRONG']);

        Livewire::actingAs($booking->salesperson)->test(BookingPayments::class, ['bookingId' => $booking->id])
            ->call('confirm', $payment->id)->assertForbidden();
        Livewire::actingAs($booking->salesperson)->test(Index::class)
            ->call('openAllocate', $unmatched->id)->assertForbidden();

        $this->assertNull($payment->fresh()->confirmed_at);
    }

    public function test_accounts_can_allocate_an_unmatched_payment(): void
    {
        $booking = PackageBooking::factory()->create();
        $unmatched = TravelPayment::factory()->create(['package_booking_id' => null, 'account_reference' => 'SAFARI', 'amount' => 90000]);
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        Livewire::actingAs($accounts)->test(Index::class)
            ->set('tab', 'unmatched')
            ->call('openAllocate', $unmatched->id)
            ->set('allocateBookingId', $booking->id)
            ->call('allocate')
            ->assertHasNoErrors();

        $this->assertSame($booking->id, $unmatched->fresh()->package_booking_id);
        $this->assertSame($accounts->id, $unmatched->fresh()->allocated_by);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->fresh()->payment_status);
    }

    public function test_mpesa_payments_cannot_be_changed_by_anyone(): void
    {
        $mpesa = TravelPayment::factory()->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $this->expectException(HttpException::class);
        app(ConfirmManualPayment::class)->reject($mpesa, 'Trying to remove an M-Pesa receipt', $accounts);
    }

    public function test_the_booking_panel_rejects_changing_an_mpesa_payment(): void
    {
        $mpesa = TravelPayment::factory()->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        Livewire::actingAs($accounts)->test(BookingPayments::class, ['bookingId' => $mpesa->package_booking_id])
            ->call('confirm', $mpesa->id)
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Completed, $mpesa->fresh()->status);
    }

    public function test_sales_managers_and_hr_cannot_open_payments(): void
    {
        foreach ([Role::SalesManager, Role::Hr] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('travel.payments.index'))->assertForbidden();
        }
    }

    public function test_salespeople_see_only_payments_on_their_bookings(): void
    {
        $mine = TravelPayment::factory()->create(['mpesa_receipt' => 'MINE000001']);
        TravelPayment::factory()->create(['mpesa_receipt' => 'OTHER00001']);

        $this->actingAs($mine->booking->salesperson)->get(route('travel.payments.index'))
            ->assertOk()
            ->assertSee('MINE000001')
            ->assertDontSee('OTHER00001')
            ->assertDontSee('Unmatched M-Pesa');

        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('travel.payments.index'))
            ->assertSee('MINE000001')->assertSee('OTHER00001')->assertSee('test mode');
    }

    public function test_only_the_bookings_people_can_request_mpesa_payment(): void
    {
        $booking = PackageBooking::factory()->create();
        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->expectException(HttpException::class);
        app(RequestMpesaPayment::class)->handle($booking, '0712345678', 100, $stranger);
    }

    public function test_a_prompt_cannot_exceed_the_balance_or_use_a_bad_number(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 1000]);

        foreach ([['0712345678', 1001], ['12345', 100], ['0712345678', '10.50']] as [$phone, $amount]) {
            try {
                app(RequestMpesaPayment::class)->handle($booking, $phone, $amount, $booking->salesperson);
                $this->fail('Expected validation to fail.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, TravelPayment::query()->count());
    }

    public function test_the_booking_panel_requests_and_simulates_a_payment(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 15000]);

        $component = Livewire::actingAs($booking->salesperson)->test(BookingPayments::class, ['bookingId' => $booking->id])
            ->call('openRequest')
            ->assertSet('amount', '15000')
            ->set('phone', '+254 712 345 678')
            ->call('requestPayment')
            ->assertHasNoErrors()
            ->assertSee('Simulate customer paying');

        $payment = TravelPayment::query()->sole();
        $this->assertSame('254712345678', $payment->phone);

        $component->call('simulate', $payment->id, true);

        $this->assertSame(PaymentStatus::Completed, $payment->fresh()->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->fresh()->payment_status);
    }
}
