<?php

namespace App\Actions;

use App\Models\PaymentDetail;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;

class SavePaymentDetail
{
    /**
     * Save how a salesperson is paid. HR and Finance are alerted on every
     * change, because redirecting pay is the change most worth noticing.
     *
     * @param  array{method: string, mpesa_phone?: ?string, mpesa_name?: ?string, bank_name?: ?string, bank_branch?: ?string, account_number?: ?string, account_name?: ?string}  $details
     */
    public function handle(User $user, array $details): PaymentDetail
    {
        $existing = $user->paymentDetail;
        $data = ['method' => $details['method']];

        if ($details['method'] === 'mpesa') {
            $data += [
                'mpesa_phone' => PaymentDetail::normalisePhone((string) $details['mpesa_phone']),
                'mpesa_name' => mb_strtoupper(trim((string) $details['mpesa_name'])),
            ];
        } else {
            $data += [
                'bank_name' => trim((string) $details['bank_name']),
                'bank_branch' => filled($details['bank_branch'] ?? null) ? trim($details['bank_branch']) : null,
                'account_number' => preg_replace('/\s+/', '', (string) $details['account_number']),
                'account_name' => mb_strtoupper(trim((string) $details['account_name'])),
            ];
        }

        $changedFields = $existing
            ? array_keys(array_filter($data, fn (mixed $value, string $field): bool => (string) $existing->{$field} !== (string) $value, ARRAY_FILTER_USE_BOTH))
            : array_keys($data);

        $detail = PaymentDetail::updateOrCreate(['user_id' => $user->id], $data);

        // Field names only: account numbers and phone numbers never go in the log.
        Audit::record(
            $detail,
            'payment_details.changed',
            ($existing ? "{$user->name} changed their payout details" : "{$user->name} added payout details").' ('.PaymentDetail::Methods[$detail->method].'; changed: '.str_replace('_', ' ', implode(', ', $changedFields)).')',
        );

        Alerts::sendToPaymentReviewers(
            'payment_details_changed',
            'Payment details changed',
            ($existing ? "{$user->name} changed their payout details" : "{$user->name} added payout details").' ('.PaymentDetail::Methods[$detail->method].', paid to '.$detail->payeeName().').',
            route('payment-details.index'),
        );

        return $detail;
    }
}
