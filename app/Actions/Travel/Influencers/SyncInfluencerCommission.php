<?php

namespace App\Actions\Travel\Influencers;

use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\AuditEvent;
use App\Models\FlightBooking;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the influencer commission line for one booking (package or flight)
 * in step with the booking. Idempotent: running it twice changes nothing.
 *
 * - One line per code and booking. Lines are never deleted.
 * - Pending until the booking is fully paid, then Payable.
 * - Cancelled / no-show / fully refunded bookings cancel the line (a line
 *   already Paid stays Paid and the refund is flagged in the audit log).
 * - A partly refunded booking earns on what the client kept.
 * - Only bookings made within the code's dates earn, and only the first
 *   max_bookings of them (a cancelled line frees its slot).
 */
class SyncInfluencerCommission
{
    /** Flights payment statuses (sent verbatim by Flights Super Admin) that mean paid. */
    public const FlightPaidStatuses = ['paid', 'completed', 'ticketed'];

    public function handle(Model $bookable): ?InfluencerCommission
    {
        $facts = match (true) {
            $bookable instanceof PackageBooking => $this->packageFacts($bookable),
            $bookable instanceof FlightBooking => $this->flightFacts($bookable),
            default => null,
        };

        if (! $facts || ! $bookable->influencer_code_id) {
            return null;
        }

        return DB::transaction(function () use ($bookable, $facts): ?InfluencerCommission {
            $code = InfluencerCode::query()->lockForUpdate()->find($bookable->influencer_code_id);

            if (! $code) {
                return null;
            }

            $line = InfluencerCommission::query()
                ->where('influencer_code_id', $code->id)
                ->where('bookable_type', $bookable->getMorphClass())
                ->where('bookable_id', $bookable->getKey())
                ->first();

            if ($facts['cancelled']) {
                return $this->cancel($line, $facts['reference']);
            }

            if ($line?->status === CommissionEntryStatus::Paid) {
                return $line;
            }

            $amount = $code->commissionOn($facts['base']);
            $status = $facts['paid'] ? CommissionEntryStatus::Payable : CommissionEntryStatus::Pending;

            if ($line && $line->status !== CommissionEntryStatus::Cancelled) {
                $line->fill([
                    'booking_amount' => $facts['base'],
                    'commission_amount' => $amount,
                    'status' => $status,
                ]);

                if ($line->isDirty()) {
                    $line->save();
                }

                return $line;
            }

            if (! $this->withinPeriod($code, $facts['madeOn'])) {
                return $line;
            }

            if ($code->max_bookings !== null && $this->usedSlots($code) >= $code->max_bookings) {
                $this->auditOnce($code, 'influencer.code_limit_reached', "Code {$code->code} limit reached ({$code->max_bookings} bookings): no commission on {$facts['reference']}", $bookable);

                return $line;
            }

            if ($line) {
                $line->forceFill([
                    'booking_amount' => $facts['base'],
                    'commission_amount' => $amount,
                    'status' => $status,
                    'earned_at' => now(),
                ])->save();

                return $line;
            }

            return InfluencerCommission::query()->create([
                'influencer_code_id' => $code->id,
                'influencer_id' => $code->influencer_id,
                'bookable_type' => $bookable->getMorphClass(),
                'bookable_id' => $bookable->getKey(),
                'booking_amount' => $facts['base'],
                'commission_amount' => $amount,
                'currency' => $facts['currency'],
                'status' => $status,
                'earned_at' => now(),
            ]);
        });
    }

    /**
     * @return array{base: float, paid: bool, cancelled: bool, madeOn: CarbonInterface, currency: string, reference: string}
     */
    private function packageFacts(PackageBooking $booking): array
    {
        $partlyRefunded = $booking->payment_status === BookingPaymentStatus::PartiallyRefunded;

        return [
            'base' => round($partlyRefunded ? (float) $booking->amount_paid - (float) $booking->amount_refunded : (float) $booking->amount_total, 2),
            'paid' => in_array($booking->payment_status, [BookingPaymentStatus::Paid, BookingPaymentStatus::PartiallyRefunded], true),
            'cancelled' => in_array($booking->status, [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow], true)
                || $booking->payment_status === BookingPaymentStatus::Refunded,
            'madeOn' => $booking->created_at ?? now(),
            'currency' => $booking->currency ?: 'KES',
            'reference' => 'booking '.$booking->reference,
        ];
    }

    /**
     * @return array{base: float, paid: bool, cancelled: bool, madeOn: CarbonInterface, currency: string, reference: string}
     */
    private function flightFacts(FlightBooking $booking): array
    {
        return [
            'base' => round((float) $booking->total_amount, 2),
            'paid' => in_array(strtolower((string) $booking->payment_status), self::FlightPaidStatuses, true),
            'cancelled' => filled($booking->cancellation_status) || $booking->cancelled_at !== null,
            'madeOn' => $booking->booked_at ?? $booking->created_at ?? now(),
            'currency' => $booking->currency ?: 'KES',
            'reference' => 'flight '.($booking->booking_reference ?? $booking->external_id),
        ];
    }

    private function cancel(?InfluencerCommission $line, string $reference): ?InfluencerCommission
    {
        if (! $line || $line->status === CommissionEntryStatus::Cancelled) {
            return $line;
        }

        if ($line->status === CommissionEntryStatus::Paid) {
            $this->auditOnce($line, 'influencer.commission_paid_on_refund', "Commission already paid on a cancelled or refunded {$reference}");

            return $line;
        }

        $line->forceFill(['status' => CommissionEntryStatus::Cancelled])->save();
        Audit::record($line, 'influencer.commission_cancelled', "Commission cancelled: {$reference} was cancelled or refunded", userId: null);

        return $line;
    }

    private function withinPeriod(InfluencerCode $code, CarbonInterface $madeOn): bool
    {
        $day = $madeOn->copy()->startOfDay();

        return $code->starts_on->lte($day) && ($code->ends_on === null || $code->ends_on->gte($day));
    }

    private function usedSlots(InfluencerCode $code): int
    {
        return InfluencerCommission::query()
            ->where('influencer_code_id', $code->id)
            ->where('status', '!=', CommissionEntryStatus::Cancelled)
            ->count();
    }

    /**
     * Write an audit line only once per subject and action (keeps the sync idempotent).
     */
    private function auditOnce(Model $subject, string $action, string $summary, ?Model $about = null): void
    {
        $exists = AuditEvent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('action', $action)
            ->when($about, fn ($query) => $query->where('summary', $summary))
            ->exists();

        if (! $exists) {
            Audit::record($subject, $action, $summary, userId: null);
        }
    }
}
