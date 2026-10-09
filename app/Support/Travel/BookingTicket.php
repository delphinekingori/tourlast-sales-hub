<?php

namespace App\Support\Travel;

use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\MediaAsset;
use App\Models\PackageBooking;
use App\Support\Barcode;
use App\Support\QrCode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything the confirmation ticket shows, read live from the booking (no
 * ticket records are stored). Used by the ticket page, the PDF, the email
 * and the public verification page, so they always agree.
 */
class BookingTicket
{
    /** Ticket states and their tone: confirmed, pending, cancelled, refunded, completed, no_show. */
    public const States = [
        'confirmed' => ['label' => 'Booking confirmed', 'short' => 'Confirmed', 'tone' => 'success'],
        'completed' => ['label' => 'Trip completed', 'short' => 'Completed', 'tone' => 'brand'],
        'pending' => ['label' => 'Awaiting confirmation', 'short' => 'Pending', 'tone' => 'warning'],
        'cancelled' => ['label' => 'Booking cancelled', 'short' => 'Cancelled', 'tone' => 'danger'],
        'refunded' => ['label' => 'Cancelled and refunded', 'short' => 'Refunded', 'tone' => 'neutral'],
        'no_show' => ['label' => 'Marked as no-show', 'short' => 'No-show', 'tone' => 'danger'],
    ];

    public const SupportEmail = 'support@tourlast.com';

    public function __construct(public PackageBooking $booking)
    {
        $booking->loadMissing([
            'package.provider', 'package.driver', 'package.guide', 'package.media',
            'version', 'departure.driver', 'departure.guide', 'client', 'guests', 'driver', 'guide',
        ]);
    }

    public static function for(PackageBooking $booking): self
    {
        return new self($booking);
    }

    public function state(): string
    {
        return match (true) {
            $this->booking->status === TravelBookingStatus::Cancelled && $this->booking->payment_status === BookingPaymentStatus::Refunded => 'refunded',
            $this->booking->status === TravelBookingStatus::Cancelled => 'cancelled',
            $this->booking->status === TravelBookingStatus::Completed => 'completed',
            $this->booking->status === TravelBookingStatus::NoShow => 'no_show',
            $this->booking->status === TravelBookingStatus::Confirmed => 'confirmed',
            default => 'pending',
        };
    }

    /**
     * @return array{label: string, short: string, tone: string}
     */
    public function stateInfo(): array
    {
        return self::States[$this->state()];
    }

    /**
     * A customer-ready ticket: confirmed or completed. Only these are emailed.
     */
    public function isIssued(): bool
    {
        return in_array($this->state(), ['confirmed', 'completed'], true);
    }

    public function reference(): string
    {
        return $this->booking->reference;
    }

    public function packageName(): string
    {
        return $this->booking->version?->name ?? $this->booking->package?->name ?? 'Tour package';
    }

    public function providerName(): ?string
    {
        return $this->booking->package?->provider?->trading_name ?: $this->booking->package?->provider?->name;
    }

    public function destination(): ?string
    {
        $version = $this->booking->version;
        $place = $version?->destination ?? $this->booking->package?->destination;
        $country = $version?->country ?? $this->booking->package?->country;

        return $place ? $place.($country && ! Str::contains($place, $country) ? ', '.$country : '') : null;
    }

    public function dateLabel(): string
    {
        $departure = $this->booking->departure;

        if (! $departure) {
            return 'Not specified';
        }

        if ($departure->ends_on === null || $departure->ends_on->isSameDay($departure->starts_on)) {
            return $departure->starts_on->format('D j M Y');
        }

        return $departure->starts_on->isSameMonth($departure->ends_on)
            ? $departure->starts_on->format('j').'–'.$departure->ends_on->format('j M Y')
            : $departure->starts_on->format('j M').' – '.$departure->ends_on->format('j M Y');
    }

    public function pickupTime(): string
    {
        $time = $this->booking->departure?->start_time;

        return $time ? date('g:i A', strtotime((string) $time)) : 'To be confirmed';
    }

    public function pickupLocation(): string
    {
        $version = $this->booking->version;

        foreach ([$version?->meeting_point, $version?->pickup_info, $version?->start_location] as $place) {
            if (filled($place)) {
                return Str::limit(trim((string) $place), 60);
            }
        }

        return 'To be confirmed';
    }

    public function duration(): string
    {
        $version = $this->booking->version;

        if (filled($version?->duration_label)) {
            return $version->duration_label;
        }

        $days = (int) ($version?->days ?? 0);
        $nights = (int) ($version?->nights ?? 0);

        if ($days <= 0) {
            return 'Not specified';
        }

        return $days === 1 && $nights === 0
            ? '1 day'
            : $days.' '.Str::plural('day', $days).($nights > 0 ? ' / '.$nights.' '.Str::plural('night', $nights) : '');
    }

    public function guests(): string
    {
        $parts = array_filter([
            $this->booking->adults ? $this->booking->adults.' '.Str::plural('adult', $this->booking->adults) : null,
            $this->booking->children ? $this->booking->children.' '.($this->booking->children === 1 ? 'child' : 'children') : null,
            $this->booking->infants ? $this->booking->infants.' '.Str::plural('infant', $this->booking->infants) : null,
        ]);

        return implode(', ', $parts) ?: $this->booking->travelers.' guests';
    }

    /**
     * Guest names in booking order ("Jane Doe, John Doe"), or null when the
     * guest details were never captured.
     */
    public function guestNames(): ?string
    {
        $names = $this->booking->guests->pluck('full_name')->filter();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    public function leadGuest(): string
    {
        return $this->booking->client?->name ?? 'Guest';
    }

    public function guestEmail(): ?string
    {
        return $this->booking->client?->email;
    }

    public function guestPhone(): ?string
    {
        return $this->booking->client?->phone;
    }

    /**
     * @return array{name: string, detail: ?string, assigned: bool}
     */
    public function driver(): array
    {
        $driver = $this->booking->effectiveDriver();

        return $driver
            ? ['name' => $driver->name, 'detail' => collect([$driver->phone, $driver->vehicle_registration])->filter()->implode(' · ') ?: null, 'assigned' => true]
            : ['name' => 'To be assigned', 'detail' => null, 'assigned' => false];
    }

    /**
     * @return array{name: string, detail: ?string, assigned: bool}
     */
    public function guide(): array
    {
        $guide = $this->booking->effectiveGuide();

        if ($guide) {
            return ['name' => $guide->name, 'detail' => $guide->phone, 'assigned' => true];
        }

        return $this->booking->package?->guide_required
            ? ['name' => 'To be assigned', 'detail' => null, 'assigned' => false]
            : ['name' => 'Not required', 'detail' => null, 'assigned' => false];
    }

    /**
     * @return array{label: string, tone: string, total: string, paid: string, balance: string, balance_due: bool, currency: string}
     */
    public function payment(): array
    {
        $booking = $this->booking;
        $currency = $booking->currency ?: 'KES';
        $money = fn ($value): string => $currency.' '.number_format((float) $value, ((float) $value) == floor((float) $value) ? 0 : 2);
        $balance = $booking->balance();

        [$label, $tone] = match ($booking->payment_status) {
            BookingPaymentStatus::Paid => ['Paid in full', 'success'],
            BookingPaymentStatus::PartiallyPaid => ['Balance due: '.$money($balance), 'warning'],
            BookingPaymentStatus::Refunded => ['Refunded', 'neutral'],
            BookingPaymentStatus::PartiallyRefunded => ['Partly refunded', 'neutral'],
            default => ['Payment pending', 'warning'],
        };

        return [
            'label' => $label,
            'tone' => $tone,
            'total' => $money($booking->amount_total),
            'paid' => $money($booking->amount_paid),
            'balance' => $money($balance),
            'balance_due' => $balance > 0 && ! in_array($booking->payment_status, [BookingPaymentStatus::Refunded, BookingPaymentStatus::PartiallyRefunded], true),
            'currency' => $currency,
        ];
    }

    /**
     * The package's primary Media Gallery image (falls back to its first
     * image). Never uploads anything new.
     */
    public function image(): ?MediaAsset
    {
        $media = $this->booking->package?->media ?? collect();

        return $media->first(fn (MediaAsset $asset) => $asset->pivot?->is_primary && $asset->isImage() && $asset->archived_at === null)
            ?? $media->first(fn (MediaAsset $asset) => $asset->isImage() && $asset->archived_at === null);
    }

    public function imageUrl(): ?string
    {
        return $this->image()?->url();
    }

    /**
     * The image embedded as a data URI (for the PDF, which can't fetch URLs).
     */
    public function imageDataUri(): ?string
    {
        $image = $this->image();

        if (! $image || ! Storage::disk($image->disk)->exists($image->path)) {
            return null;
        }

        return 'data:'.$image->mime_type.';base64,'.base64_encode((string) Storage::disk($image->disk)->get($image->path));
    }

    public function verificationUrl(): string
    {
        return $this->booking->verificationUrl();
    }

    public function qrDataUri(int $scale = 8): string
    {
        return QrCode::pngDataUri($this->verificationUrl(), $scale);
    }

    /**
     * Date without the weekday (\"19 Oct 2026\", or a range), for compact layouts.
     */
    public function dateShort(): string
    {
        $departure = $this->booking->departure;

        if (! $departure) {
            return 'Not specified';
        }

        return $departure->ends_on === null || $departure->ends_on->isSameDay($departure->starts_on)
            ? $departure->starts_on->format('j M Y')
            : $departure->dateLabel();
    }

    /**
     * Weekday of departure (\"Monday\"), or empty when there is no departure.
     */
    public function weekday(): string
    {
        return $this->booking->departure?->starts_on->format('l') ?? '';
    }

    public function barcodeDataUri(int $height = 44): string
    {
        return Barcode::code128PngDataUri($this->reference(), $height);
    }

    public function issuedOn(): string
    {
        return ($this->booking->confirmed_at ?? $this->booking->created_at)->format('j M Y');
    }

    public function fileName(): string
    {
        return 'tourlast-ticket-'.Str::slug($this->reference()).'.pdf';
    }
}
