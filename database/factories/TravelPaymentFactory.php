<?php

namespace Database\Factories;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A completed M-Pesa paybill payment.
 *
 * @extends Factory<TravelPayment>
 */
class TravelPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_booking_id' => PackageBooking::factory(),
            'method' => PaymentMethod::Mpesa,
            'channel' => PaymentChannel::Paybill,
            'status' => PaymentStatus::Completed,
            'amount' => 10000,
            'currency' => 'KES',
            'mpesa_receipt' => strtoupper(fake()->unique()->bothify('??#?#?##??')),
            'phone' => '2547'.fake()->numerify('########'),
            'payer_name' => fake()->name(),
            'account_reference' => fn (array $attributes) => $attributes['package_booking_id'] ? PackageBooking::find($attributes['package_booking_id'])?->reference : 'UNKNOWN',
            'paid_at' => now(),
        ];
    }

    public function manual(PaymentMethod $method = PaymentMethod::Cash): static
    {
        return $this->state(fn (): array => [
            'method' => $method,
            'channel' => PaymentChannel::Manual,
            'mpesa_receipt' => null,
            'confirmed_at' => null,
        ]);
    }
}
