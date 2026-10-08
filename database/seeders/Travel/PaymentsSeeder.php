<?php

namespace Database\Seeders\Travel;

use App\Actions\Travel\Payments\SimulateMpesaCustomer;
use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Role;
use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo package payments (test mode): full and partial M-Pesa payments from
 * the paybill and from prompts, one prompt still waiting for the customer,
 * one cash payment awaiting Accounts and two paybill payments that matched
 * no booking.
 */
class PaymentsSeeder extends Seeder
{
    public function run(RefreshBookingPayment $refresh): void
    {
        $bookings = PackageBooking::query()
            ->whereNotIn('status', [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow])
            ->with('client')
            ->orderBy('id')
            ->get();

        $accounts = User::query()->role(Role::Accounts->value)->first();

        foreach ($bookings->values() as $index => $booking) {
            $total = (float) $booking->amount_total;
            $phone = '2547'.str_pad((string) (10000000 + $booking->id * 7919 % 89999999), 8, '0', STR_PAD_LEFT);
            $paidAt = $booking->created_at->copy()->addHours(2 + $index);

            match ($index % 6) {
                0 => $this->mpesa($booking, PaymentChannel::Paybill, $total, $phone, $paidAt),
                1 => $this->mpesa($booking, PaymentChannel::Stk, round($total * 0.5), $phone, $paidAt),
                2 => [
                    $this->mpesa($booking, PaymentChannel::Stk, round($total * 0.3), $phone, $paidAt),
                    $this->mpesa($booking, PaymentChannel::Paybill, $total - round($total * 0.3), $phone, $paidAt->copy()->addDays(3)),
                ],
                3 => $index === 3 ? $this->pendingPrompt($booking, $phone) : null,
                4 => $index === 4 ? $this->cashAwaiting($booking) : $this->cashConfirmed($booking, $accounts, $total),
                default => null,
            };

            $refresh->handle($booking);
        }

        foreach ([['TOURLAST', 15000, 'Peter Mwangi'], ['MARA TRIP', 42000, 'Grace Atieno']] as [$account, $amount, $name]) {
            TravelPayment::query()->firstOrCreate(['account_reference' => $account, 'package_booking_id' => null], [
                'method' => PaymentMethod::Mpesa,
                'channel' => PaymentChannel::Paybill,
                'status' => PaymentStatus::Completed,
                'amount' => $amount,
                'currency' => 'KES',
                'mpesa_receipt' => SimulateMpesaCustomer::receipt(),
                'phone' => '254722'.random_int(100000, 999999),
                'payer_name' => $name,
                'paid_at' => now()->subDays(random_int(1, 5)),
            ]);
        }
    }

    private function mpesa(PackageBooking $booking, PaymentChannel $channel, float $amount, string $phone, mixed $paidAt): void
    {
        if ($amount < 1) {
            return;
        }

        TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::Mpesa,
            'channel' => $channel,
            'status' => PaymentStatus::Completed,
            'amount' => $amount,
            'currency' => $booking->currency ?: 'KES',
            'mpesa_receipt' => SimulateMpesaCustomer::receipt(),
            'checkout_request_id' => $channel === PaymentChannel::Stk ? 'ws_CO_SANDBOX_'.Str::upper(Str::random(12)) : null,
            'merchant_request_id' => $channel === PaymentChannel::Stk ? 'SANDBOX-'.Str::upper(Str::random(8)) : null,
            'phone' => $phone,
            'payer_name' => $booking->client?->name,
            'account_reference' => $booking->reference,
            'result_code' => 0,
            'paid_at' => $paidAt,
            'recorded_by' => $channel === PaymentChannel::Stk ? $booking->salesperson_id : null,
        ]);
    }

    private function pendingPrompt(PackageBooking $booking, string $phone): void
    {
        TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::Mpesa,
            'channel' => PaymentChannel::Stk,
            'status' => PaymentStatus::Pending,
            'amount' => (int) ceil((float) $booking->amount_total),
            'currency' => $booking->currency ?: 'KES',
            'checkout_request_id' => 'ws_CO_SANDBOX_'.Str::upper(Str::random(12)),
            'merchant_request_id' => 'SANDBOX-'.Str::upper(Str::random(8)),
            'phone' => $phone,
            'account_reference' => $booking->reference,
            'recorded_by' => $booking->salesperson_id,
        ]);
    }

    private function cashAwaiting(PackageBooking $booking): void
    {
        TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::Cash,
            'channel' => PaymentChannel::Manual,
            'status' => PaymentStatus::Completed,
            'amount' => round((float) $booking->amount_total * 0.4),
            'currency' => $booking->currency ?: 'KES',
            'account_reference' => $booking->reference,
            'paid_at' => now()->subDay(),
            'recorded_by' => $booking->salesperson_id,
            'notes' => 'Deposit paid in cash at the Westlands office.',
        ]);
    }

    private function cashConfirmed(PackageBooking $booking, ?User $accounts, float $total): void
    {
        TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::Bank,
            'channel' => PaymentChannel::Manual,
            'status' => PaymentStatus::Completed,
            'amount' => $total,
            'currency' => $booking->currency ?: 'KES',
            'reference' => 'EQ'.random_int(10000000, 99999999),
            'account_reference' => $booking->reference,
            'paid_at' => now()->subDays(2),
            'recorded_by' => $booking->salesperson_id,
            'confirmed_by' => $accounts?->id,
            'confirmed_at' => $accounts ? now()->subDay() : null,
        ]);
    }
}
