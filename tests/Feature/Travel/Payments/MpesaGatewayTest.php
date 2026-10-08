<?php

namespace Tests\Feature\Travel\Payments;

use App\Actions\Travel\Payments\RequestMpesaPayment;
use App\Enums\Travel\PaymentStatus;
use App\Integrations\Mpesa\DarajaMpesaGateway;
use App\Integrations\Mpesa\MpesaGateway;
use App\Integrations\Mpesa\MpesaPhone;
use App\Integrations\Mpesa\SandboxMpesaGateway;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MpesaGatewayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'driver' => 'daraja', 'environment' => 'sandbox', 'consumer_key' => 'key', 'consumer_secret' => 'secret',
            'shortcode' => '174379', 'shortcode_type' => 'paybill', 'passkey' => 'passkey', 'callback_secret' => 'cb-secret',
            'callback_base_url' => 'https://sales.tourlast.com', 'allowed_ips' => [], 'stk_timeout_minutes' => 3,
        ];
    }

    public function test_the_driver_setting_picks_the_gateway(): void
    {
        config(['travel.mpesa.driver' => 'sandbox']);
        $this->assertInstanceOf(SandboxMpesaGateway::class, app(MpesaGateway::class));

        config(['travel.mpesa' => $this->config()]);
        $this->assertInstanceOf(DarajaMpesaGateway::class, app(MpesaGateway::class));
    }

    public function test_daraja_stk_push_sends_the_documented_payload(): void
    {
        config(['travel.mpesa' => $this->config()]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0', 'CustomerMessage' => 'Success',
            ]),
        ]);

        $gateway = app(MpesaGateway::class);
        $result = $gateway->stkPush('254712345678', 1500, 'TB-2026-0042', 'Tourlast trip');

        $this->assertSame('ws_CO_1', $result->checkoutRequestId);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'stkpush')) {
                return $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret'));
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer tok')
                && $data['BusinessShortCode'] === '174379'
                && $data['Password'] === base64_encode('174379passkey'.$data['Timestamp'])
                && preg_match('/^\d{14}$/', $data['Timestamp']) === 1
                && $data['TransactionType'] === 'CustomerPayBillOnline'
                && $data['Amount'] === 1500
                && $data['PartyA'] === '254712345678'
                && $data['PartyB'] === '174379'
                && $data['AccountReference'] === 'TB-2026-0042'
                && $data['CallBackURL'] === 'https://sales.tourlast.com/api/daraja/cb-secret/stk';
        });
    }

    public function test_the_access_token_is_cached(): void
    {
        config(['travel.mpesa' => $this->config()]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*' => Http::response(['ResultCode' => '0', 'ResultDesc' => 'ok']),
        ]);

        $gateway = app(MpesaGateway::class);
        $gateway->stkQuery('ws_CO_1');
        $gateway->stkQuery('ws_CO_2');

        Http::assertSentCount(3);
    }

    public function test_a_refused_prompt_is_shown_to_staff_and_nothing_is_saved(): void
    {
        config(['travel.mpesa' => $this->config()]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*' => Http::response(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber'], 400),
        ]);
        $booking = PackageBooking::factory()->create();

        try {
            app(RequestMpesaPayment::class)->handle($booking, '0712345678', 100, $booking->salesperson);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Invalid PhoneNumber', $exception->errors()['phone'][0]);
        }

        $this->assertSame(0, TravelPayment::query()->count());
    }

    public function test_the_reconcile_command_settles_timed_out_prompts(): void
    {
        config(['travel.mpesa.driver' => 'sandbox', 'travel.mpesa.stk_timeout_minutes' => 3]);
        $booking = PackageBooking::factory()->create();
        $old = app(RequestMpesaPayment::class)->handle($booking, '0712345678', 100, $booking->salesperson);
        $old->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
        $fresh = app(RequestMpesaPayment::class)->handle($booking, '0712345678', 100, $booking->salesperson);

        $this->artisan('travel:mpesa-reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, $old->fresh()->status);
        $this->assertSame(1037, $old->fresh()->result_code);
        $this->assertSame(PaymentStatus::Pending, $fresh->fresh()->status);
    }

    public function test_prompts_with_no_answer_after_an_hour_fail(): void
    {
        config(['travel.mpesa' => $this->config()]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500),
        ]);
        $payment = TravelPayment::factory()->create(['channel' => 'stk', 'status' => 'pending', 'mpesa_receipt' => null, 'checkout_request_id' => 'ws_CO_9']);
        $payment->forceFill(['created_at' => now()->subMinutes(61)])->saveQuietly();

        $this->artisan('travel:mpesa-reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('No response from M-Pesa', $payment->fresh()->result_description);
    }

    public function test_the_simulate_command_posts_a_paybill_payment(): void
    {
        config(['travel.mpesa.driver' => 'sandbox']);
        $booking = PackageBooking::factory()->create();

        $this->artisan('travel:mpesa-simulate', ['account' => $booking->reference, 'amount' => 5000])->assertSuccessful();

        $this->assertSame(5000.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('254712345678', MpesaPhone::normalise('0712 345 678'));
        $this->assertSame('254712345678', MpesaPhone::normalise('+254712345678'));
        $this->assertSame('254112345678', MpesaPhone::normalise('0112345678'));
        $this->assertSame('254712345678', MpesaPhone::normalise('712345678'));
        $this->assertNull(MpesaPhone::normalise('0212345678'));
        $this->assertNull(MpesaPhone::normalise('12345'));
    }
}
