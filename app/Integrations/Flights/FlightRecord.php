<?php

namespace App\Integrations\Flights;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * One booking as Tourlast Flights Super Admin sends it to the Hub (pulled
 * from GET {FLIGHTS_API_URL}/bookings or pushed to
 * POST /api/v1/integrations/flights/bookings). This is the contract the
 * Flights developer implements. Keys are snake_case; only external_id,
 * booked_at and booking_status are required, everything else may be omitted
 * or null. Dates are ISO 8601 with an offset (stored in the app's timezone). Amounts are decimal
 * numbers in `currency`. Statuses are sent as Flights uses them; the Hub
 * keeps them verbatim (lower-cased) and never invents its own.
 *
 *     {
 *       "external_id": "FL-104233",            // required, stable Flights booking id
 *       "booking_reference": "TLF10423",
 *       "pnr": "X7K2QP",
 *       "customer": {"name": "Jane Doe", "email": "jane@example.com", "phone": "0712345678"},
 *       "airline": {"code": "KQ", "name": "Kenya Airways"},
 *       "origin": "NBO", "destination": "MBA",
 *       "trip_type": "one_way",                 // one_way | return | multi_city
 *       "cabin": "economy",
 *       "departure_at": "2026-10-20T07:15:00+03:00",
 *       "arrival_at": "2026-10-20T08:15:00+03:00",
 *       "return_at": null,
 *       "passengers": [{"name": "Jane Doe", "type": "adult", "ticket_number": "7062345678901"}],
 *       "segments": [{"flight_number": "KQ602", "airline": "KQ", "origin": "NBO", "destination": "MBA",
 *                     "departure_at": "...", "arrival_at": "...", "cabin": "economy"}],
 *       "currency": "KES", "fare_amount": 9500, "total_amount": 11200, "markup_amount": 700,
 *       "booking_status": "confirmed",          // required
 *       "payment_status": "paid",
 *       "cancellation": {"status": "cancelled", "cancelled_at": "...", "reason": "Customer request"},
 *       "refund": {"status": "pending", "amount": 8000, "method": "mpesa",
 *                  "requested_at": "...", "completed_at": null},
 *       "booked_at": "2026-10-07T10:03:00+03:00",   // required
 *       "agent_reference": "aisha@tourlast.com",    // Hub user email of the selling travel salesperson
 *       "promo_code": "AMINA10",                    // influencer code used, if any
 *       "updated_at": "2026-10-07T10:05:00+03:00"   // used to ignore out-of-order updates
 *     }
 *
 * Flat keys are accepted too: customer_name, customer_email, customer_phone,
 * airline_code, airline_name, cancellation_status, cancelled_at,
 * cancellation_reason, refund_status, refund_amount, refund_method,
 * refund_requested_at, refund_completed_at.
 */
final class FlightRecord
{
    /**
     * @param  list<array{name: string, type: string, ticket: ?string}>  $passengers
     * @param  list<array{flightNumber: ?string, airline: ?string, origin: ?string, destination: ?string, departAt: ?CarbonImmutable, arriveAt: ?CarbonImmutable, cabin: ?string}>  $segments
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $bookingReference,
        public readonly ?string $pnr,
        public readonly ?string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ?string $airlineCode,
        public readonly ?string $airlineName,
        public readonly ?string $origin,
        public readonly ?string $destination,
        public readonly ?string $tripType,
        public readonly ?string $cabin,
        public readonly ?CarbonImmutable $departureAt,
        public readonly ?CarbonImmutable $arrivalAt,
        public readonly ?CarbonImmutable $returnAt,
        public readonly array $passengers,
        public readonly array $segments,
        public readonly string $currency,
        public readonly ?float $fare,
        public readonly ?float $total,
        public readonly ?float $markup,
        public readonly string $bookingStatus,
        public readonly ?string $paymentStatus,
        public readonly ?string $cancellationStatus,
        public readonly ?CarbonImmutable $cancelledAt,
        public readonly ?string $cancellationReason,
        public readonly ?string $refundStatus,
        public readonly ?float $refundAmount,
        public readonly ?string $refundMethod,
        public readonly ?CarbonImmutable $refundRequestedAt,
        public readonly ?CarbonImmutable $refundCompletedAt,
        public readonly CarbonImmutable $bookedAt,
        public readonly ?string $agentReference,
        public readonly ?string $promoCode,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when a required key is missing or a date is unreadable
     */
    public static function fromArray(array $data): self
    {
        $externalId = trim((string) ($data['external_id'] ?? ''));

        if ($externalId === '') {
            throw new InvalidArgumentException('external_id is required');
        }

        $bookingStatus = self::status($data['booking_status'] ?? null);

        if ($bookingStatus === null) {
            throw new InvalidArgumentException("booking_status is required ({$externalId})");
        }

        $bookedAt = self::date($data['booked_at'] ?? null, 'booked_at');

        if ($bookedAt === null) {
            throw new InvalidArgumentException("booked_at is required ({$externalId})");
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $airline = is_array($data['airline'] ?? null) ? $data['airline'] : [];
        $cancellation = is_array($data['cancellation'] ?? null) ? $data['cancellation'] : [];
        $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];

        $passengers = [];

        foreach ((array) ($data['passengers'] ?? []) as $passenger) {
            if (! is_array($passenger) || blank($passenger['name'] ?? null)) {
                continue;
            }

            $passengers[] = [
                'name' => mb_substr(trim((string) $passenger['name']), 0, 255),
                'type' => self::status($passenger['type'] ?? null) ?? 'adult',
                'ticket' => self::text($passenger['ticket_number'] ?? null),
            ];
        }

        $segments = [];

        foreach ((array) ($data['segments'] ?? []) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $segments[] = [
                'flightNumber' => self::code($segment['flight_number'] ?? null),
                'airline' => self::code($segment['airline'] ?? $segment['airline_code'] ?? null),
                'origin' => self::code($segment['origin'] ?? null),
                'destination' => self::code($segment['destination'] ?? null),
                'departAt' => self::date($segment['departure_at'] ?? null, 'segments.departure_at'),
                'arriveAt' => self::date($segment['arrival_at'] ?? null, 'segments.arrival_at'),
                'cabin' => self::status($segment['cabin'] ?? null),
            ];
        }

        return new self(
            externalId: mb_substr($externalId, 0, 80),
            bookingReference: self::text($data['booking_reference'] ?? null),
            pnr: self::code($data['pnr'] ?? null),
            customerName: self::text($customer['name'] ?? $data['customer_name'] ?? null),
            customerEmail: self::text($customer['email'] ?? $data['customer_email'] ?? null),
            customerPhone: self::text($customer['phone'] ?? $data['customer_phone'] ?? null),
            airlineCode: self::code($airline['code'] ?? $data['airline_code'] ?? null),
            airlineName: self::text($airline['name'] ?? $data['airline_name'] ?? null),
            origin: self::code($data['origin'] ?? null),
            destination: self::code($data['destination'] ?? null),
            tripType: self::status($data['trip_type'] ?? null),
            cabin: self::status($data['cabin'] ?? null),
            departureAt: self::date($data['departure_at'] ?? null, 'departure_at'),
            arrivalAt: self::date($data['arrival_at'] ?? null, 'arrival_at'),
            returnAt: self::date($data['return_at'] ?? null, 'return_at'),
            passengers: $passengers,
            segments: $segments,
            currency: strtoupper(self::text($data['currency'] ?? null) ?? 'KES'),
            fare: self::amount($data['fare_amount'] ?? null),
            total: self::amount($data['total_amount'] ?? null),
            markup: self::amount($data['markup_amount'] ?? null),
            bookingStatus: $bookingStatus,
            paymentStatus: self::status($data['payment_status'] ?? null),
            cancellationStatus: self::status($cancellation['status'] ?? $data['cancellation_status'] ?? null),
            cancelledAt: self::date($cancellation['cancelled_at'] ?? $data['cancelled_at'] ?? null, 'cancelled_at'),
            cancellationReason: self::text($cancellation['reason'] ?? $data['cancellation_reason'] ?? null),
            refundStatus: self::status($refund['status'] ?? $data['refund_status'] ?? null),
            refundAmount: self::amount($refund['amount'] ?? $data['refund_amount'] ?? null),
            refundMethod: self::status($refund['method'] ?? $data['refund_method'] ?? null),
            refundRequestedAt: self::date($refund['requested_at'] ?? $data['refund_requested_at'] ?? null, 'refund.requested_at'),
            refundCompletedAt: self::date($refund['completed_at'] ?? $data['refund_completed_at'] ?? null, 'refund.completed_at'),
            bookedAt: $bookedAt,
            agentReference: self::text($data['agent_reference'] ?? null),
            promoCode: self::text($data['promo_code'] ?? null),
            updatedAt: self::date($data['updated_at'] ?? null, 'updated_at'),
            raw: $data,
        );
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private static function code(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : strtoupper(mb_substr($value, 0, 20));
    }

    private static function status(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : mb_strtolower(mb_substr($value, 0, 30));
    }

    private static function amount(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function date(mixed $value, string $field): ?CarbonImmutable
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new InvalidArgumentException("{$field} is not a valid date");
        }
    }
}
