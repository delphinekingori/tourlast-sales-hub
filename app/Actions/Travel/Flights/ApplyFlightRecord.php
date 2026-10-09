<?php

namespace App\Actions\Travel\Flights;

use App\Enums\Role;
use App\Events\FlightBookingSynced;
use App\Integrations\Flights\FlightRecord;
use App\Models\FlightBooking;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\InfluencerCodes;
use Illuminate\Support\Facades\DB;

/**
 * Writes one booking from Flights Super Admin into the Hub's read-only copy.
 * The only code that creates or changes FlightBooking rows.
 */
class ApplyFlightRecord
{
    public const Created = 'created';

    public const Updated = 'updated';

    public const Unchanged = 'unchanged';

    /** Status fields whose changes are written to the audit log. */
    private const AuditedStatuses = [
        'booking_status' => 'Booking synced',
        'cancellation_status' => 'Cancellation synced',
        'refund_status' => 'Refund synced',
    ];

    /**
     * @param  string  $via  "sandbox", "api" or "push"
     */
    public function handle(FlightRecord $record, string $via): string
    {
        return DB::transaction(function () use ($record, $via): string {
            $system = (string) config('travel.flights.system', 'tourlast-flights');

            $booking = FlightBooking::query()
                ->lockForUpdate()
                ->firstOrNew(['source_system' => $system, 'external_id' => $record->externalId]);
            $isNew = ! $booking->exists;

            if (! $isNew && $record->updatedAt && $booking->source_updated_at && $record->updatedAt->lt($booking->source_updated_at)) {
                return self::Unchanged;
            }

            $before = $booking->only(array_keys(self::AuditedStatuses));
            $code = InfluencerCodes::resolve($record->promoCode, 'flights', $record->bookedAt);

            $booking->fill([
                'booking_reference' => $record->bookingReference,
                'pnr' => $record->pnr,
                'customer_name' => $record->customerName,
                'customer_email' => $record->customerEmail,
                'customer_phone' => $record->customerPhone,
                'airline_code' => $record->airlineCode,
                'airline_name' => $record->airlineName,
                'origin' => $record->origin,
                'destination' => $record->destination,
                'trip_type' => $record->tripType,
                'cabin' => $record->cabin,
                'departure_at' => $record->departureAt,
                'arrival_at' => $record->arrivalAt,
                'return_at' => $record->returnAt,
                'passenger_count' => max(1, count($record->passengers)),
                'currency' => $record->currency,
                'fare_amount' => $record->fare,
                'total_amount' => $record->total,
                'markup_amount' => $record->markup,
                'booking_status' => $record->bookingStatus,
                'payment_status' => $record->paymentStatus,
                'cancellation_status' => $record->cancellationStatus,
                'cancelled_at' => $record->cancelledAt,
                'cancellation_reason' => $record->cancellationReason,
                'refund_status' => $record->refundStatus,
                'refund_amount' => $record->refundAmount,
                'refund_method' => $record->refundMethod,
                'refund_requested_at' => $record->refundRequestedAt,
                'refund_completed_at' => $record->refundCompletedAt,
                'booked_at' => $record->bookedAt,
                'agent_reference' => $record->agentReference,
                'salesperson_id' => $this->salespersonFor($record->agentReference),
                'promo_code' => $record->promoCode ? strtoupper($record->promoCode) : null,
                'influencer_code_id' => $code?->id,
                'source_updated_at' => $record->updatedAt ?? $booking->source_updated_at,
                'payload' => $record->raw,
            ]);

            $changed = $isNew || $booking->isDirty();

            $booking->forceFill([
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error' => null,
            ])->save();

            if (! $changed) {
                return self::Unchanged;
            }

            $booking->segments()->delete();

            foreach (array_values($record->segments) as $index => $segment) {
                $booking->segments()->create([
                    'sequence' => $index + 1,
                    'flight_number' => $segment['flightNumber'],
                    'airline_code' => $segment['airline'],
                    'origin' => $segment['origin'],
                    'destination' => $segment['destination'],
                    'departure_at' => $segment['departAt'],
                    'arrival_at' => $segment['arriveAt'],
                    'cabin' => $segment['cabin'],
                ]);
            }

            $booking->passengers()->delete();

            foreach ($record->passengers as $passenger) {
                $booking->passengers()->create([
                    'name' => $passenger['name'],
                    'passenger_type' => mb_substr($passenger['type'], 0, 10),
                    'ticket_number' => $passenger['ticket'],
                ]);
            }

            $this->audit($booking, $before, $isNew, $via);

            FlightBookingSynced::dispatch($booking, $isNew);

            return $isNew ? self::Created : self::Updated;
        });
    }

    /**
     * The travel salesperson whose Hub email matches the booking's agent reference.
     */
    private function salespersonFor(?string $agentReference): ?int
    {
        if (blank($agentReference)) {
            return null;
        }

        return User::query()
            ->where('email', mb_strtolower(trim($agentReference)))
            ->role(Role::TravelSalesperson->value)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function audit(FlightBooking $booking, array $before, bool $isNew, string $via): void
    {
        $label = $booking->booking_reference ?? $booking->external_id;

        if ($isNew) {
            Audit::record($booking, 'flight.synced', "Booking synced: {$label} ({$booking->route()}) via {$via}", userId: null);

            return;
        }

        foreach (self::AuditedStatuses as $field => $summary) {
            $changes = Audit::diff([$field => $before[$field] ?? null], [$field => $booking->{$field}]);

            if ($changes !== []) {
                Audit::record($booking, 'flight.synced', "{$summary}: {$label} via {$via}", $changes, userId: null);
            }
        }
    }
}
