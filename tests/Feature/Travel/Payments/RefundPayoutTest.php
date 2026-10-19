<?php

namespace Tests\Feature\Travel\Payments;

use App\Actions\Travel\Payments\FlagStuckRefundPayouts;
use App\Actions\Travel\Payments\SendRefundPayout;
use App\Enums\Role;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\RefundStatus;
use App\Integrations\Mpesa\DarajaMpesaGateway;
use App\Integrations\Mpesa\MpesaException;
use App\Livewire\Travel\Cancellations\Index;
use App\Models\AuditEvent;
use App\Models\DarajaCallback;
use App\Models\PackageBooking;
use App\Models\TravelRefund;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RefundPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'travel.mpesa.driver' => 'sandbox',
            'travel.mpesa.callback_secret' => 'test-secret',
            'travel.mpesa.allowed_ips' => [],
            'travel.refunds.auto_payout' => true,
            'travel.refunds.stuck_minutes' => 30,
        ]);
    }

    private function accounts(): User
    {
        return User::factory()->withRole(Role::Accounts)->create();
    }

    private function approvedRefund(float $amount = 15000): TravelRefund
    {
        $booking = PackageBooking::factory()->paid()->create();

        return TravelRefund::query()->create([
            'package_booking_id' => $booking->id,
            'amount' => $amount,
            'reason' => 'Client cancelled',
            'status' => RefundStatus::Approved,
            'requested_by' => $booking->salesperson_id,
            'approved_by' => User::factory()->withRole(Role::SalesAdmin)->create()->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(TravelRefund $refund, int $code = 0, string $receipt = 'NLJ7RT61SV'): array
    {
        $result = [
            'ResultType' => 0,
            'ResultCode' => $code,
            'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'The balance is insufficient for the transaction.',
            'OriginatorConversationID' => $refund->originator_conversation_id,
            'ConversationID' => 'AG_20261019_1',
            'TransactionID' => $receipt,
        ];

        if ($code === 0) {
            $result['ResultParameters'] = ['ResultParameter' => [
                ['Key' => 'TransactionAmount', 'Value' => (float) $refund->amount],
                ['Key' => 'TransactionReceipt', 'Value' => $receipt],
                ['Key' => 'ReceiverPartyPublicName', 'Value' => '254712345678 - Jane Wanjiru'],
            ]];
        }

        return ['Result' => $result];
    }

    public function test_daraja_b2c_payout_sends_the_documented_payload(): void
    {
        config(['travel.mpesa' => [
            'driver' => 'daraja', 'environment' => 'sandbox', 'consumer_key' => 'key', 'consumer_secret' => 'secret',
            'shortcode' => '174379', 'callback_secret' => 'cb-secret', 'callback_base_url' => 'https://sales-hub.tourlast.com',
            'b2c' => ['shortcode' => '600979', 'initiator_name' => 'testapi', 'security_credential' => 'ENCRYPTED=='],
        ]]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/b2c/*' => Http::response([
                'ConversationID' => 'AG_1', 'OriginatorConversationID' => 'orig-1', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.',
            ]),
        ]);

        $result = (new DarajaMpesaGateway((array) config('travel.mpesa')))->b2cPayout('254712345678', 15000, 'orig-1', 'Refund TB-1');

        $this->assertSame('AG_1', $result->conversationId);
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/mpesa/b2c/v3/paymentrequest')) {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer tok')
                && $body['OriginatorConversationID'] === 'orig-1'
                && $body['InitiatorName'] === 'testapi'
                && $body['SecurityCredential'] === 'ENCRYPTED=='
                && $body['CommandID'] === 'BusinessPayment'
                && $body['Amount'] === 15000
                && $body['PartyA'] === '600979'
                && $body['PartyB'] === '254712345678'
                && $body['ResultURL'] === 'https://sales-hub.tourlast.com/api/daraja/cb-secret/b2c/result'
                && $body['QueueTimeOutURL'] === 'https://sales-hub.tourlast.com/api/daraja/cb-secret/b2c/timeout';
        });
    }

    public function test_daraja_b2c_encrypts_the_initiator_password_with_the_certificate(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = $key ? openssl_csr_new(['commonName' => 'safaricom-test'], $key) : false;
        $certificate = $csr ? openssl_csr_sign($csr, null, $key, 1) : false;

        if (! $certificate) {
            $this->markTestSkipped('OpenSSL cannot generate a test certificate here.');
        }

        openssl_x509_export($certificate, $pem);
        $path = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($path, $pem);

        $credential = (new DarajaMpesaGateway(['b2c' => ['initiator_password' => 'Safaricom999!', 'certificate_path' => $path]]))->securityCredential();
        unlink($path);

        openssl_private_decrypt(base64_decode($credential), $plain, $key, OPENSSL_PKCS1_PADDING);
        $this->assertSame('Safaricom999!', $plain);
    }

    public function test_daraja_b2c_without_credentials_refuses(): void
    {
        $this->expectException(MpesaException::class);

        (new DarajaMpesaGateway(['environment' => 'sandbox', 'b2c' => []]))->b2cPayout('254712345678', 100, 'orig', 'Refund');
    }

    public function test_accounts_sends_an_approved_refund_and_it_becomes_processing(): void
    {
        $refund = $this->approvedRefund();
        $accounts = $this->accounts();
        $refund->booking->client->update(['phone' => '0712 345 678']);

        $sent = app(SendRefundPayout::class)->handle($accounts, $refund);

        $this->assertSame(RefundStatus::Processing, $sent->status);
        $this->assertSame(PaymentMethod::Mpesa, $sent->method);
        $this->assertSame('254712345678', $sent->payout_phone);
        $this->assertNotEmpty($sent->originator_conversation_id);
        $this->assertNotEmpty($sent->conversation_id);
        $this->assertSame($accounts->id, $sent->processed_by);
        $this->assertTrue(AuditEvent::query()->where('action', 'refund.payout_sent')->exists());
        $this->assertEquals(0, (float) $refund->booking->fresh()->amount_refunded);
    }

    public function test_a_refund_cannot_be_sent_twice(): void
    {
        $refund = $this->approvedRefund();
        $accounts = $this->accounts();

        $first = app(SendRefundPayout::class)->handle($accounts, $refund);

        try {
            app(SendRefundPayout::class)->handle($accounts, $refund);
            $this->fail('The second send should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already being paid out', $exception->errors()['refund'][0]);
        }

        $this->assertSame($first->originator_conversation_id, $refund->fresh()->originator_conversation_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'refund.payout_sent')->count());
    }

    public function test_it_does_nothing_when_automatic_refunds_are_off(): void
    {
        config(['travel.refunds.auto_payout' => false]);
        $refund = $this->approvedRefund();

        try {
            app(SendRefundPayout::class)->handle($this->accounts(), $refund);
            $this->fail('Should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('switched off', $exception->errors()['refund'][0]);
        }

        $this->assertSame(RefundStatus::Approved, $refund->fresh()->status);

        Livewire::actingAs($this->accounts())->test(Index::class, ['tab' => 'refunds'])
            ->assertDontSee('Send refund via M-Pesa')
            ->assertSee('Pay out');
    }

    public function test_only_accounts_can_send(): void
    {
        $refund = $this->approvedRefund();

        foreach ([$refund->booking->salesperson, User::factory()->withRole(Role::SalesAdmin)->create()] as $user) {
            try {
                app(SendRefundPayout::class)->handle($user, $refund);
                $this->fail('Should be forbidden.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame(RefundStatus::Approved, $refund->fresh()->status);
    }

    public function test_it_refuses_requested_refunds_a_bad_phone_fractions_and_amounts_over_the_balance(): void
    {
        $accounts = $this->accounts();

        $requested = $this->approvedRefund();
        $requested->update(['status' => RefundStatus::Requested]);
        $this->assertRefused($accounts, $requested, 'Only an approved refund');

        $noPhone = $this->approvedRefund();
        $noPhone->booking->client->update(['phone' => '+44 20 7946 0958']);
        $this->assertRefused($accounts, $noPhone, 'no valid Safaricom phone');

        $fraction = $this->approvedRefund(1500.50);
        $this->assertRefused($accounts, $fraction, 'whole shillings');

        $tooMuch = $this->approvedRefund(95000);
        $this->assertRefused($accounts, $tooMuch, 'more than the booking can still refund');
    }

    private function assertRefused(User $actor, TravelRefund $refund, string $message): void
    {
        $status = $refund->status;

        try {
            app(SendRefundPayout::class)->handle($actor, $refund);
            $this->fail('Should be refused: '.$message);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['refund'][0]);
        }

        $this->assertSame($status, $refund->fresh()->status);
    }

    public function test_a_gateway_refusal_marks_the_refund_failed_so_it_can_be_retried(): void
    {
        config(['travel.mpesa' => [
            'driver' => 'daraja', 'environment' => 'sandbox', 'consumer_key' => 'key', 'consumer_secret' => 'secret', 'callback_secret' => 'test-secret',
            'b2c' => ['shortcode' => '600979', 'initiator_name' => 'testapi', 'security_credential' => 'X'],
        ]]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/b2c/*' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'Invalid initiator'], 400),
        ]);
        $refund = $this->approvedRefund();

        try {
            app(SendRefundPayout::class)->handle($this->accounts(), $refund);
            $this->fail('Should surface the refusal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Invalid initiator', $exception->errors()['refund'][0]);
        }

        $this->assertSame(RefundStatus::Failed, $refund->fresh()->status);
        $this->assertStringContainsString('Invalid initiator', (string) $refund->fresh()->failure_reason);
    }

    public function test_a_successful_result_callback_completes_the_refund(): void
    {
        Notification::fake();
        $refund = $this->approvedRefund();
        $accounts = $this->accounts();
        $refund = app(SendRefundPayout::class)->handle($accounts, $refund);

        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $refund->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame('NLJ7RT61SV', $refund->reference);
        $this->assertNotNull($refund->processed_at);
        $booking = $refund->booking->fresh();
        $this->assertEquals(15000, (float) $booking->amount_refunded);
        Notification::assertSentTo($accounts, SmartAlert::class, fn (SmartAlert $alert) => $alert->title === 'Refund paid');
        Notification::assertSentTo($booking->salesperson, SmartAlert::class, fn (SmartAlert $alert) => $alert->title === 'Refund paid');
        $this->assertSame($refund->id, DarajaCallback::query()->latest('id')->first()->travel_refund_id);
    }

    public function test_a_repeated_result_callback_changes_nothing(): void
    {
        $refund = app(SendRefundPayout::class)->handle($this->accounts(), $this->approvedRefund());

        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund))->assertOk();
        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund, receipt: 'OTHER00000'))->assertOk()->assertJsonPath('ResultDesc', 'Accepted');
        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund, code: 2001))->assertOk();

        $this->assertSame('NLJ7RT61SV', $refund->fresh()->reference);
        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertEquals(15000, (float) $refund->booking->fresh()->amount_refunded);
        $this->assertSame(3, DarajaCallback::query()->where('type', 'b2c_result')->count());
    }

    public function test_a_failed_result_marks_the_refund_failed_and_allows_a_retry(): void
    {
        Notification::fake();
        $accounts = $this->accounts();
        $refund = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());
        $firstId = $refund->originator_conversation_id;

        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund, code: 2001))->assertOk();

        $refund->refresh();
        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertStringContainsString('balance is insufficient', (string) $refund->failure_reason);
        $this->assertEquals(0, (float) $refund->booking->fresh()->amount_refunded);
        Notification::assertSentTo($accounts, SmartAlert::class, fn (SmartAlert $alert) => $alert->title === 'Refund failed');

        $retried = app(SendRefundPayout::class)->handle($accounts, $refund);
        $this->assertSame(RefundStatus::Processing, $retried->status);
        $this->assertNull($retried->failure_reason);
        $this->assertNotSame($firstId, $retried->originator_conversation_id);

        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($retried))->assertOk();
        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
    }

    public function test_a_late_success_after_a_failure_still_completes_the_refund(): void
    {
        $refund = app(SendRefundPayout::class)->handle($this->accounts(), $this->approvedRefund());

        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund, code: 2001))->assertOk();
        $this->postJson('/api/daraja/test-secret/b2c/result', $this->resultPayload($refund))->assertOk();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
    }

    public function test_a_timeout_keeps_the_refund_processing_and_tells_accounts(): void
    {
        Notification::fake();
        $accounts = $this->accounts();
        $refund = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());

        $this->postJson('/api/daraja/test-secret/b2c/timeout', ['Result' => [
            'OriginatorConversationID' => $refund->originator_conversation_id, 'ConversationID' => 'AG_20261019_1',
        ]])->assertOk()->assertJsonPath('ResultDesc', 'Accepted');

        $refund->refresh();
        $this->assertSame(RefundStatus::Processing, $refund->status);
        $this->assertStringContainsString('timed out', (string) $refund->failure_reason);
        Notification::assertSentTo($accounts, SmartAlert::class, fn (SmartAlert $alert) => $alert->title === 'Refund payout timed out');
    }

    public function test_unknown_payouts_and_wrong_secrets_are_handled_safely(): void
    {
        $this->postJson('/api/daraja/test-secret/b2c/result', ['Result' => ['OriginatorConversationID' => 'nope', 'ResultCode' => 0]])
            ->assertOk()->assertJsonPath('ResultDesc', 'Accepted');
        $this->assertStringContainsString('Unknown payout', (string) DarajaCallback::query()->first()->error);

        $this->postJson('/api/daraja/test-secret/b2c/result', ['garbage' => true])->assertOk();
        $this->postJson('/api/daraja/wrong/b2c/result', ['Result' => []])->assertNotFound();
        $this->postJson('/api/daraja/wrong/b2c/timeout', ['Result' => []])->assertNotFound();
        $this->assertSame(2, DarajaCallback::query()->count());
    }

    public function test_stuck_payouts_are_flagged_to_accounts_once(): void
    {
        Notification::fake();
        $accounts = $this->accounts();
        $stuck = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());
        $fresh = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());
        $stuck->forceFill(['payout_requested_at' => now()->subMinutes(45)])->save();

        $this->assertSame(1, app(FlagStuckRefundPayouts::class)->handle());
        $this->assertSame(0, app(FlagStuckRefundPayouts::class)->handle());

        $this->assertNotNull($stuck->fresh()->payout_flagged_at);
        $this->assertNull($fresh->fresh()->payout_flagged_at);
        Notification::assertSentToTimes($accounts, SmartAlert::class, 1);
    }

    public function test_the_refunds_screen_sends_via_mpesa_and_the_manual_path_still_works(): void
    {
        $refund = $this->approvedRefund();
        $manual = $this->approvedRefund(5000);
        $accounts = $this->accounts();

        Livewire::actingAs($accounts)->test(Index::class, ['tab' => 'refunds'])
            ->assertSee('Send refund via M-Pesa')
            ->call('sendViaMpesa', $refund->id)
            ->assertHasNoErrors()
            ->assertSee('Waiting for M-Pesa')
            ->call('sendViaMpesa', $refund->id)
            ->assertHasErrors('refund')
            ->call('openPayout', $manual->id)
            ->set('payout.reference', 'QWE123')
            ->call('savePayout')
            ->assertHasNoErrors();

        $this->assertSame(RefundStatus::Processing, $refund->fresh()->status);
        $this->assertSame(RefundStatus::Completed, $manual->fresh()->status);
        $this->assertSame('QWE123', $manual->fresh()->reference);
    }

    public function test_the_sandbox_simulator_completes_or_fails_a_sent_refund(): void
    {
        $accounts = $this->accounts();
        $paid = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());
        $failed = app(SendRefundPayout::class)->handle($accounts, $this->approvedRefund());

        $this->artisan('travel:mpesa-simulate-refund', ['refund' => $paid->id])->assertSuccessful();
        $this->artisan('travel:mpesa-simulate-refund', ['refund' => $failed->id, '--fail' => true])->assertSuccessful();
        $this->artisan('travel:mpesa-simulate-refund', ['refund' => $paid->id])->assertFailed();

        $this->assertSame(RefundStatus::Completed, $paid->fresh()->status);
        $this->assertStringStartsWith('TST', (string) $paid->fresh()->reference);
        $this->assertSame(RefundStatus::Failed, $failed->fresh()->status);
    }
}
