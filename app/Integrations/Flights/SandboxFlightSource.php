<?php

namespace App\Integrations\Flights;

use App\Enums\Role;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Models\InfluencerCode;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * FLIGHTS_SOURCE=sandbox (the default until Flights Super Admin connects):
 * about sixty realistic test bookings over the last 45 days and the next 60,
 * on Kenyan and regional routes. The same seed always produces the same
 * bookings (ids SBX-0001…), so syncing again only updates them. Bookings are
 * credited to existing travel salespeople by email, and a few use an active
 * influencer code, exactly as live data will be.
 */
class SandboxFlightSource implements FlightSource
{
    public const Count = 60;

    private const Routes = [
        ['NBO', 'MBA', 60], ['MBA', 'NBO', 60], ['NBO', 'KIS', 55], ['NBO', 'EDL', 45], ['NBO', 'UKA', 65],
        ['NBO', 'EBB', 75], ['NBO', 'DAR', 80], ['NBO', 'JRO', 50], ['NBO', 'KGL', 95], ['NBO', 'DXB', 330],
        ['KIS', 'NBO', 55], ['UKA', 'NBO', 65],
    ];

    private const Airlines = [
        ['KQ', 'Kenya Airways'], ['JM', 'Jambojet'], ['5Y', 'Safarilink'], ['5H', 'Fly540'],
    ];

    private const Names = [
        'Grace Wanjiru', 'Brian Kiprop', 'Faith Achieng', 'Daniel Mutua', 'Mercy Chebet', 'Samuel Odhiambo',
        'Ann Nduta', 'Victor Kamau', 'Lilian Atieno', 'Joseph Mwangi', 'Esther Wambui', 'Collins Omondi',
        'Sarah Njoki', 'Peter Kibet', 'Agnes Moraa', 'Dennis Otieno', 'Ruth Wairimu', 'Kevin Mugo',
        'Tom Fischer', 'Amelia Brown', 'Liam O\'Connor', 'Priya Shah',
    ];

    public function name(): string
    {
        return 'sandbox';
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        mt_srand(4417);

        $agents = User::query()->role(Role::TravelSalesperson->value)->orderBy('id')->pluck('email')->all();
        $codes = InfluencerCode::query()
            ->whereIn('applies_to', [InfluencerCodeScope::Flights, InfluencerCodeScope::All])
            ->where('status', InfluencerCodeStatus::Active)
            ->orderBy('id')
            ->pluck('code')
            ->all();
        $today = CarbonImmutable::today();
        $refundStates = ['pending', 'processing', 'completed', 'failed', 'rejected'];
        $records = [];

        for ($i = 1; $i <= self::Count; $i++) {
            [$origin, $destination, $minutes] = self::Routes[mt_rand(0, count(self::Routes) - 1)];
            [$airlineCode, $airlineName] = $destination === 'DXB' || $destination === 'KGL' || $destination === 'EBB'
                ? self::Airlines[0]
                : self::Airlines[mt_rand(0, count(self::Airlines) - 1)];

            $bookedAt = $today->subDays(mt_rand(0, 45))->setTime(mt_rand(7, 21), mt_rand(0, 59));
            $departureAt = $bookedAt->addDays(mt_rand(1, 60))->setTime(mt_rand(6, 20), [0, 15, 30, 45][mt_rand(0, 3)]);
            $arrivalAt = $departureAt->addMinutes($minutes);
            $isReturn = mt_rand(1, 4) === 1;
            $returnAt = $isReturn ? $departureAt->addDays(mt_rand(2, 9)) : null;
            $paxCount = mt_rand(1, 10) <= 7 ? 1 : mt_rand(2, 4);
            $perPax = (int) round(($minutes * 140 + mt_rand(2000, 6000)) / 100) * 100;
            $fare = $perPax * $paxCount * ($isReturn ? 2 : 1);
            $markup = round($fare * 0.06, -1);
            $total = $fare + $markup + 600 * $paxCount;
            $lead = self::Names[mt_rand(0, count(self::Names) - 1)];

            $passengers = [['name' => $lead, 'type' => 'adult', 'ticket_number' => '706'.str_pad((string) mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT)]];

            for ($p = 2; $p <= $paxCount; $p++) {
                $passengers[] = [
                    'name' => self::Names[mt_rand(0, count(self::Names) - 1)],
                    'type' => $p === $paxCount && mt_rand(0, 1) ? 'child' : 'adult',
                    'ticket_number' => '706'.str_pad((string) mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT),
                ];
            }

            $segments = [[
                'flight_number' => $airlineCode.mt_rand(100, 899),
                'airline' => $airlineCode,
                'origin' => $origin,
                'destination' => $destination,
                'departure_at' => $departureAt->toIso8601String(),
                'arrival_at' => $arrivalAt->toIso8601String(),
                'cabin' => 'economy',
            ]];

            if ($returnAt) {
                $segments[] = [
                    'flight_number' => $airlineCode.mt_rand(100, 899),
                    'airline' => $airlineCode,
                    'origin' => $destination,
                    'destination' => $origin,
                    'departure_at' => $returnAt->toIso8601String(),
                    'arrival_at' => $returnAt->addMinutes($minutes)->toIso8601String(),
                    'cabin' => 'economy',
                ];
            }

            $status = mt_rand(1, 20) === 1 ? 'pending' : 'confirmed';
            $cancellation = null;
            $refund = null;

            // Every sixth booking is cancelled; refunds cycle through each Flights refund state.
            if ($i % 6 === 0) {
                $status = 'cancelled';
                $cancelledAt = $bookedAt->addDays(mt_rand(0, 3))->addHours(2);
                $cancellation = ['status' => 'cancelled', 'cancelled_at' => $cancelledAt->toIso8601String(), 'reason' => ['Customer request', 'Change of travel plans', 'Flight rescheduled by airline', 'Duplicate booking'][mt_rand(0, 3)]];
                $refundStatus = $refundStates[intdiv($i, 6) % count($refundStates)];
                $refund = [
                    'status' => $refundStatus,
                    'amount' => round($total * 0.8, -1),
                    'method' => mt_rand(0, 2) ? 'mpesa' : 'card',
                    'requested_at' => $cancelledAt->toIso8601String(),
                    'completed_at' => $refundStatus === 'completed' ? $cancelledAt->addDays(5)->toIso8601String() : null,
                ];
            }

            $records[] = FlightRecord::fromArray(array_filter([
                'external_id' => 'SBX-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'booking_reference' => 'TLF'.str_pad((string) (10400 + $i * 7), 5, '0', STR_PAD_LEFT),
                'pnr' => strtoupper(substr(md5('pnr'.$i), 0, 6)),
                'customer' => [
                    'name' => $lead,
                    'email' => strtolower(str_replace([' ', '\''], ['.', ''], $lead)).'@example.com',
                    'phone' => '07'.str_pad((string) mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                ],
                'airline' => ['code' => $airlineCode, 'name' => $airlineName],
                'origin' => $origin,
                'destination' => $destination,
                'trip_type' => $isReturn ? 'return' : 'one_way',
                'cabin' => 'economy',
                'departure_at' => $departureAt->toIso8601String(),
                'arrival_at' => $arrivalAt->toIso8601String(),
                'return_at' => $returnAt?->toIso8601String(),
                'passengers' => $passengers,
                'segments' => $segments,
                'currency' => 'KES',
                'fare_amount' => $fare,
                'total_amount' => $total,
                'markup_amount' => $markup,
                'booking_status' => $status,
                'payment_status' => $status === 'pending' ? 'awaiting_payment' : ($refund && $refund['status'] === 'completed' ? 'refunded' : 'paid'),
                'cancellation' => $cancellation,
                'refund' => $refund,
                'booked_at' => $bookedAt->toIso8601String(),
                'agent_reference' => $agents !== [] && $i % 5 !== 0 ? $agents[$i % count($agents)] : null,
                'promo_code' => $codes !== [] && $i % 9 === 0 ? $codes[$i % count($codes)] : null,
                'updated_at' => ($cancellation ? CarbonImmutable::parse($cancellation['cancelled_at']) : $bookedAt)->toIso8601String(),
            ], fn ($value) => $value !== null));
        }

        mt_srand();

        return array_values(array_filter(
            $records,
            fn (FlightRecord $record): bool => $since === null || ($record->updatedAt ?? $record->bookedAt)->gte($since),
        ));
    }
}
