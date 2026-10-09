<?php

namespace Tests\Feature\Travel\Payments;

use App\Actions\Travel\Payments\RequestMpesaPayment;
use App\Enums\Role;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\PaymentStatus;
use App\Models\DarajaCallback;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DarajaCallbacksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['travel.mpesa.driver' => 'sandbox', 'travel.mpesa.callback_secret' => 'test-secret', 'travel.mpesa.allowed_ips' => []]);
    }

    private function prompt(PackageBooking $booking, int $amount = 40000): TravelPayment
    {
        return app(RequestMpesaPayment::class)->handle($booking, '0712345678', $amount, $booking->salesperson);
    }

    /**
     * @return array<string, mixed>
     */
    private function stkPayload(TravelPayment $payment, int $code = 0, ?float $amount = null, string $receipt = 'QHX1234ABC'): array
    {
        $callback = [
            'MerchantRequestID' => $payment->merchant_request_id,
            'CheckoutRequestID' => $payment->checkout_request_id,
            'ResultCode' => $code,
            'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user',
        ];

        if ($code === 0) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount ?? (float) $payment->amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => 20261007143015],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }

    /**
     * @return array<string, mixed>
     */
    private function c2bPayload(string $account, float $amount, string $receipt = 'QHC9876XYZ'): array
    {
        return [
            'TransactionType' => 'Pay Bill', 'TransID' => $receipt, 'TransTime' => '20261007101500',
            'TransAmount' => number_format($amount, 2, '.', ''), 'BusinessShortCode' => '174379', 'BillRefNumber' => $account,
            'MSISDN' => '254712345678', 'FirstName' => 'Jane', 'MiddleName' => '', 'LastName' => 'Wanjiru',
        ];
    }

    public function test_a_paid_prompt_completes_the_payment_and_updates_the_booking(): void
    {
        Notification::fake();
        $booking = PackageBooking::factory()->create();
        $payment = $this->prompt($booking);

        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $this->postJson('/api/daraja/test-secret/stk', $this->stkPayload($payment))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame('QHX1234ABC', $payment->mpesa_receipt);
        $this->assertSame(40000.0, (float) $booking->fresh()->amount_paid);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $booking->fresh()->payment_status);
        Notification::assertSentTo($booking->salesperson, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'travel_payment_received');
    }

    public function test_a_repeated_callback_changes_nothing(): void
    {
        $booking = PackageBooking::factory()->create();
        $payment = $this->prompt($booking);

        $this->postJson('/api/daraja/test-secret/stk', $this->stkPayload($payment))->assertOk();
        $this->postJson('/api/daraja/test-secret/stk', $this->stkPayload($payment, amount: 99999))->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertSame(40000.0, (float) $payment->fresh()->amount);
        $this->assertSame(40000.0, (float) $booking->fresh()->amount_paid);
        $this->assertSame(2, DarajaCallback::query()->where('type', 'stk')->count());
    }

    public function test_a_cancelled_prompt_is_marked_cancelled(): void
    {
        $booking = PackageBooking::factory()->create();
        $payment = $this->prompt($booking);

        $this->postJson('/api/daraja/test-secret/stk', $this->stkPayload($payment, code: 1032))->assertOk();

        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertSame(BookingPaymentStatus::Unpaid, $booking->fresh()->payment_status);
    }

    public function test_a_receipt_already_used_is_ignored(): void
    {
        $booking = PackageBooking::factory()->create();
        TravelPayment::factory()->create(['package_booking_id' => $booking->id, 'mpesa_receipt' => 'QHX1234ABC']);
        $payment = $this->prompt($booking, 1000);

        $this->postJson('/api/daraja/test-secret/stk', $this->stkPayload($payment))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertStringContainsString('already recorded', (string) DarajaCallback::query()->latest('id')->first()->error);
    }

    public function test_an_unknown_checkout_request_is_logged_and_ignored(): void
    {
        $this->postJson('/api/daraja/test-secret/stk', ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_nope', 'ResultCode' => 0]]])
            ->assertOk()
            ->assertJsonPath('ResultDesc', 'Accepted');

        $this->assertStringContainsString('Unknown CheckoutRequestID', (string) DarajaCallback::query()->first()->error);
        $this->assertSame(0, TravelPayment::query()->count());
    }

    public function test_the_wrong_secret_gets_404_and_stores_nothing(): void
    {
        $this->postJson('/api/daraja/wrong/stk', ['Body' => []])->assertNotFound();
        $this->postJson('/api/daraja/wrong/c2b/confirmation', $this->c2bPayload('TB-2026-0001', 10))->assertNotFound();

        $this->assertSame(0, DarajaCallback::query()->count());
    }

    public function test_the_ip_allowlist_is_enforced_when_set(): void
    {
        config(['travel.mpesa.allowed_ips' => ['196.201.214.200']]);

        $this->postJson('/api/daraja/test-secret/c2b/validation', $this->c2bPayload('X', 10))->assertForbidden();
    }

    public function test_a_paybill_payment_with_a_booking_reference_is_matched(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 50000]);

        $this->postJson('/api/daraja/test-secret/c2b/validation', $this->c2bPayload(strtolower($booking->reference), 50000))
            ->assertOk()->assertJsonPath('ResultCode', 0);
        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload(' '.strtolower($booking->reference).' ', 50000))->assertOk();
        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload($booking->reference, 50000))->assertOk();

        $payment = TravelPayment::query()->sole();
        $this->assertSame($booking->id, $payment->package_booking_id);
        $this->assertSame('Jane Wanjiru', $payment->payer_name);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->fresh()->payment_status);
    }

    public function test_an_unmatched_paybill_payment_waits_for_accounts(): void
    {
        Notification::fake();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload('JOHN SAFARI', 7000))->assertOk();

        $payment = TravelPayment::query()->sole();
        $this->assertNull($payment->package_booking_id);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        Notification::assertSentTo($accounts, SmartAlert::class, fn (SmartAlert $alert) => $alert->type === 'payment_unmatched');
    }

    public function test_overpaying_and_underpaying_set_the_payment_status(): void
    {
        $booking = PackageBooking::factory()->create(['amount_total' => 20000]);

        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload($booking->reference, 5000, 'RCPT000001'))->assertOk();
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $booking->fresh()->payment_status);

        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload($booking->reference, 20000, 'RCPT000002'))->assertOk();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->fresh()->payment_status);
        $this->assertSame(25000.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_a_cancelled_booking_does_not_take_paybill_money(): void
    {
        $booking = PackageBooking::factory()->create(['status' => 'cancelled']);

        $this->postJson('/api/daraja/test-secret/c2b/confirmation', $this->c2bPayload($booking->reference, 1000))->assertOk();

        $this->assertNull(TravelPayment::query()->sole()->package_booking_id);
    }
}
