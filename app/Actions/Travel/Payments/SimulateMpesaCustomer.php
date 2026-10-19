<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\RefundStatus;
use App\Integrations\Mpesa\MpesaGateway;
use App\Models\TravelPayment;
use App\Models\TravelRefund;
use Illuminate\Support\Str;

/**
 * Test mode only (MPESA_DRIVER=sandbox): plays the customer's side by posting
 * realistic Daraja callbacks through ReceiveDarajaCallback, the same handler
 * Safaricom's real callbacks use.
 */
class SimulateMpesaCustomer
{
    public function __construct(private MpesaGateway $gateway, private ReceiveDarajaCallback $receive) {}

    public static function allowed(): bool
    {
        return app(MpesaGateway::class)->isSimulated();
    }

    /**
     * The customer enters their PIN (paid) or cancels the prompt.
     */
    public function answerPrompt(TravelPayment $payment, bool $pays): void
    {
        abort_unless($this->gateway->isSimulated(), 403);
        abort_unless($payment->channel === PaymentChannel::Stk && $payment->status === PaymentStatus::Pending, 422);

        $callback = [
            'MerchantRequestID' => $payment->merchant_request_id,
            'CheckoutRequestID' => $payment->checkout_request_id,
            'ResultCode' => $pays ? 0 : ApplyStkResult::CancelledByCustomer,
            'ResultDesc' => $pays ? 'The service request is processed successfully.' : 'Request cancelled by user',
        ];

        if ($pays) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => (float) $payment->amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => self::receipt()],
                ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')],
                ['Name' => 'PhoneNumber', 'Value' => (int) $payment->phone],
            ]];
        }

        $this->receive->handle(ReceiveDarajaCallback::Stk, ['Body' => ['stkCallback' => $callback]], '127.0.0.1');
    }

    /**
     * Safaricom reports the outcome of a refund sent through the sandbox gateway.
     */
    public function answerPayout(TravelRefund $refund, bool $succeeds): void
    {
        abort_unless($this->gateway->isSimulated(), 403);
        abort_unless($refund->status === RefundStatus::Processing && filled($refund->originator_conversation_id), 422);

        $result = [
            'ResultType' => 0,
            'ResultCode' => $succeeds ? 0 : 2001,
            'ResultDesc' => $succeeds ? 'The service request is processed successfully.' : 'The initiator information is invalid.',
            'OriginatorConversationID' => $refund->originator_conversation_id,
            'ConversationID' => $refund->conversation_id ?: 'AG_SANDBOX_'.now()->format('YmdHis'),
            'TransactionID' => $succeeds ? self::receipt() : 'TST0000000',
        ];

        if ($succeeds) {
            $result['ResultParameters'] = ['ResultParameter' => [
                ['Key' => 'TransactionAmount', 'Value' => (float) $refund->amount],
                ['Key' => 'TransactionReceipt', 'Value' => $result['TransactionID']],
                ['Key' => 'ReceiverPartyPublicName', 'Value' => $refund->payout_phone.' - Test Customer'],
                ['Key' => 'TransactionCompletedDateTime', 'Value' => now('Africa/Nairobi')->format('d.m.Y H:i:s')],
            ]];
        }

        $this->receive->handle(ReceiveDarajaCallback::B2cResult, ['Result' => $result], '127.0.0.1');
    }

    /**
     * A customer pays the paybill directly with an account number.
     */
    public function payPaybill(string $accountNumber, float $amount, string $phone254 = '254708374149', string $name = 'Test Customer'): ?TravelPayment
    {
        abort_unless($this->gateway->isSimulated(), 403);

        $receipt = self::receipt();
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $this->receive->handle(ReceiveDarajaCallback::C2bConfirmation, [
            'TransactionType' => 'Pay Bill',
            'TransID' => $receipt,
            'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => number_format($amount, 2, '.', ''),
            'BusinessShortCode' => (string) config('travel.mpesa.shortcode'),
            'BillRefNumber' => $accountNumber,
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '',
            'ThirdPartyTransID' => '',
            'MSISDN' => $phone254,
            'FirstName' => $first,
            'MiddleName' => '',
            'LastName' => $last,
        ], '127.0.0.1');

        return TravelPayment::query()->where('mpesa_receipt', $receipt)->first();
    }

    /**
     * A receipt number shaped like M-Pesa's (10 characters), marked as a test.
     */
    public static function receipt(): string
    {
        return 'TST'.Str::upper(Str::random(7));
    }
}
