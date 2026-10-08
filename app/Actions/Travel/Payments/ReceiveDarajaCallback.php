<?php

namespace App\Actions\Travel\Payments;

use App\Models\DarajaCallback;
use App\Models\TravelPayment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for every message from Safaricom (and the sandbox
 * simulator). The raw payload is stored first, then processed; Safaricom
 * always gets the "Accepted" answer it expects, even for duplicates, so it
 * does not keep retrying. Anything that could not be processed stays in the
 * callbacks log with its error for Accounts to reconcile.
 */
class ReceiveDarajaCallback
{
    public const Stk = 'stk';

    public const C2bValidation = 'c2b_validation';

    public const C2bConfirmation = 'c2b_confirmation';

    /**
     * C2B validation policy: accept every paybill payment, even when the
     * account number matches no booking, so a customer's money is never
     * bounced; unmatched payments wait under Payments → Unmatched instead.
     */
    public const AcceptAllPaybillPayments = true;

    public function __construct(
        private ApplyStkResult $applyStkResult,
        private RecordPaybillPayment $recordPaybillPayment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ResultCode: int|string, ResultDesc: string}
     */
    public function handle(string $type, array $payload, ?string $ip = null): array
    {
        $callback = DarajaCallback::query()->create([
            'type' => $type,
            'payload' => $payload,
            'ip_address' => $ip,
        ]);

        try {
            [$paymentId, $error] = match ($type) {
                self::Stk => $this->stk($payload),
                self::C2bConfirmation => $this->confirmation($payload),
                default => [null, null],
            };

            $callback->forceFill([
                'travel_payment_id' => $paymentId,
                'processed_at' => now(),
                'error' => $error ? mb_substr($error, 0, 255) : null,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Daraja callback could not be processed', ['callback' => $callback->id, 'error' => $exception->getMessage()]);
            $callback->forceFill(['error' => mb_substr('Not processed: '.$exception->getMessage(), 0, 255)])->save();
        }

        if ($type === self::C2bValidation && ! self::AcceptAllPaybillPayments) {
            return ['ResultCode' => 'C2B00012', 'ResultDesc' => 'Rejected'];
        }

        return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: ?string}
     */
    private function stk(array $payload): array
    {
        $body = $payload['Body']['stkCallback'] ?? null;

        if (! is_array($body) || blank($body['CheckoutRequestID'] ?? null)) {
            return [null, 'Not an STK callback (no CheckoutRequestID).'];
        }

        $payment = TravelPayment::query()->where('checkout_request_id', (string) $body['CheckoutRequestID'])->first();

        if (! $payment) {
            return [null, 'Unknown CheckoutRequestID '.$body['CheckoutRequestID'].'; ignored.'];
        }

        $meta = collect($body['CallbackMetadata']['Item'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['Name']))
            ->mapWithKeys(fn (array $item) => [$item['Name'] => $item['Value'] ?? null]);

        $error = $this->applyStkResult->handle($payment, (int) ($body['ResultCode'] ?? -1), $body['ResultDesc'] ?? null, [
            'amount' => $meta->get('Amount'),
            'receipt' => $meta->get('MpesaReceiptNumber'),
            'date' => $meta->get('TransactionDate'),
            'phone' => $meta->get('PhoneNumber'),
        ]);

        return [$payment->id, $error];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: ?string}
     */
    private function confirmation(array $payload): array
    {
        $payment = $this->recordPaybillPayment->handle($payload);

        return $payment ? [$payment->id, null] : [null, 'Not a paybill confirmation (missing TransID or TransAmount).'];
    }
}
