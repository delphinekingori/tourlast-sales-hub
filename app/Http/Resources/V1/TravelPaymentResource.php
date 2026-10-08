<?php

namespace App\Http\Resources\V1;

use App\Models\TravelClient;
use App\Models\TravelPayment;
use App\Support\Travel\PaymentAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client payment towards a package booking (M-Pesa or cash/bank). The
 * payer's phone is shown in full to Accounts and Travel managers only.
 *
 * @mixin TravelPayment
 */
class TravelPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $full = PaymentAccess::seesAll($request->user());

        return [
            'id' => $this->id,
            'booking' => $this->whenLoaded('booking', fn () => $this->booking ? ['id' => $this->booking->id, 'reference' => $this->booking->reference] : null),
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'counts' => $this->counts(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'mpesa_receipt' => $this->mpesa_receipt,
            'reference' => $this->reference,
            'account_reference' => $this->account_reference,
            'phone' => $full ? $this->phone : TravelClient::mask($this->phone),
            'payer_name' => $this->payer_name,
            'result_description' => $this->result_description,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
