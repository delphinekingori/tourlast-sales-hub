<?php

namespace App\Http\Resources\V1;

use App\Models\FlightBooking;
use App\Support\Travel\TravelAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A read-only flight booking synced from Tourlast Flights Super Admin.
 * Statuses are exactly as the Flights system sends them. Customer contact is
 * masked unless the token owner sold the booking or is a Travel manager;
 * markup only with "view travel financials".
 *
 * @mixin FlightBooking
 */
class FlightBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $contact = $this->contactVisibleTo($viewer);

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'source_system' => $this->source_system,
            'booking_reference' => $this->booking_reference,
            'pnr' => $this->pnr,
            'customer_name' => $this->customer_name,
            'customer_email' => $contact ? $this->customer_email : $this->maskedEmail(),
            'customer_phone' => $contact ? $this->customer_phone : $this->maskedPhone(),
            'airline_code' => $this->airline_code,
            'airline_name' => $this->airline_name,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'route' => $this->route(),
            'trip_type' => $this->trip_type,
            'cabin' => $this->cabin,
            'departure_at' => $this->departure_at?->toIso8601String(),
            'arrival_at' => $this->arrival_at?->toIso8601String(),
            'return_at' => $this->return_at?->toIso8601String(),
            'passenger_count' => $this->passenger_count,
            'currency' => $this->currency,
            'fare_amount' => $this->fare_amount,
            'total_amount' => $this->total_amount,
            'markup_amount' => $this->when(TravelAccess::seesFinancials($viewer), $this->markup_amount),
            'booking_status' => $this->booking_status,
            'booking_status_label' => FlightBooking::statusLabel($this->booking_status),
            'payment_status' => $this->payment_status,
            'cancellation_status' => $this->cancellation_status,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'refund_status' => $this->refund_status,
            'refund_amount' => $this->refund_amount,
            'refund_method' => $this->refund_method,
            'refund_requested_at' => $this->refund_requested_at?->toIso8601String(),
            'refund_completed_at' => $this->refund_completed_at?->toIso8601String(),
            'booked_at' => $this->booked_at?->toIso8601String(),
            'salesperson' => new UserSummaryResource($this->whenLoaded('salesperson')),
            'promo_code' => $this->promo_code,
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($segment) => [
                'sequence' => $segment->sequence,
                'flight_number' => $segment->flight_number,
                'airline_code' => $segment->airline_code,
                'origin' => $segment->origin,
                'destination' => $segment->destination,
                'departure_at' => $segment->departure_at?->toIso8601String(),
                'arrival_at' => $segment->arrival_at?->toIso8601String(),
                'cabin' => $segment->cabin,
            ])->all()),
            'passengers' => $this->whenLoaded('passengers', fn () => $this->passengers->map(fn ($passenger) => [
                'name' => $passenger->name,
                'type' => $passenger->passenger_type,
                'ticket_number' => $passenger->ticket_number,
            ])->all()),
            'admin_url' => $this->adminUrl(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'sync_status' => $this->sync_status,
        ];
    }
}
