<?php

namespace App\Http\Resources\V1;

use App\Models\PackageBooking;
use App\Models\TravelClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A package booking. Booking status and payment status are separate; the
 * trip status is on the departure. Client contact is masked unless the
 * token owner sold the booking, owns the package, or is a Travel manager or
 * Accounts.
 *
 * @mixin PackageBooking
 */
class PackageBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contact = $this->canSeeClientContact($request->user());

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'package' => $this->whenLoaded('package', fn () => ['id' => $this->package->id, 'reference' => $this->package->reference, 'name' => $this->package->name]),
            'package_version_id' => $this->package_version_id,
            'departure' => new PackageDepartureResource($this->whenLoaded('departure')),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $contact ? $this->client->email : null,
                'phone' => $contact ? $this->client->phone : TravelClient::mask($this->client->phone),
                'country' => $this->client->country,
            ]),
            'salesperson' => new UserSummaryResource($this->whenLoaded('salesperson')),
            'influencer_code' => $this->whenLoaded('influencerCode', fn () => $this->influencerCode?->code),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
            'travelers' => $this->travelers,
            'currency' => $this->currency,
            'amount_total' => $this->amount_total,
            'amount_paid' => $this->amount_paid,
            'amount_refunded' => $this->amount_refunded,
            'balance' => $this->balance(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'special_requirements' => $this->special_requirements,
            'dietary_requirements' => $this->dietary_requirements,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
