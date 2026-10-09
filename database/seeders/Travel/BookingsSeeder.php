<?php

namespace Database\Seeders\Travel;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Travel\BookingSource;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\ResourceStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TripStatus;
use App\Models\BookingChecklistItem;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\InfluencerCode;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageBookingGuest;
use App\Models\PackageCancellation;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use App\Models\TravelRefund;
use Illuminate\Database\Seeder;

/**
 * Demo inventory, drivers, guides, clients and package bookings (local only).
 */
class BookingsSeeder extends Seeder
{
    public function run(RefreshBookingPayment $refresh): void
    {
        if (PackageBooking::query()->exists()) {
            return;
        }

        $drivers = collect([
            ['Joseph Kariuki', 'Toyota Land Cruiser', 'KDA 123A'],
            ['Samuel Mutua', 'Toyota Land Cruiser', 'KDB 456B'],
            ['Daniel Ouma', 'Safari minivan', 'KCZ 789C'],
            ['Peter Kiprono', 'Toyota Hiace', 'KDE 321D'],
            ['Moses Lekishon', 'Toyota Land Cruiser', 'KDF 654E'],
        ])->map(fn (array $row) => Driver::query()->create([
            'name' => $row[0], 'phone' => '0722'.random_int(100000, 999999), 'vehicle' => $row[1], 'vehicle_registration' => $row[2], 'status' => ResourceStatus::Active,
        ]));

        $guides = collect([
            ['Grace Naserian', ['English', 'Swahili', 'Maa'], 'Big Five'],
            ['Ali Hassan', ['English', 'Swahili', 'Italian'], 'Coast & culture'],
            ['Esther Wanjiru', ['English', 'Swahili', 'German'], 'Birding'],
            ['Tom Omondi', ['English', 'Swahili', 'French'], 'Hiking'],
        ])->map(fn (array $row) => Guide::query()->create([
            'name' => $row[0], 'phone' => '0733'.random_int(100000, 999999), 'languages' => $row[1], 'specialization' => $row[2], 'status' => ResourceStatus::Active,
        ]));

        $clients = collect(['Wanjiku Kamau', 'Brian Otieno', 'Sarah Mitchell', 'Hans Becker', 'Fatma Ali', 'James Mwangi', 'Linda Achieng', 'Marco Rossi', 'Amina Yusuf', 'David Kim', 'Njeri Githinji', 'Olivia Brown'])
            ->map(fn (string $name, int $i) => TravelClient::query()->create([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
                'phone' => '07'.str_pad((string) (11000000 + $i * 7919), 8, '0', STR_PAD_LEFT),
                'country' => $i % 4 === 2 ? 'United Kingdom' : ($i % 4 === 3 ? 'Germany' : 'Kenya'),
            ]));

        $packages = Package::query()->whereIn('status', [PackageStatus::Approved, PackageStatus::Published])->whereNotNull('live_version_id')->with('liveVersion')->get();
        $codes = InfluencerCode::query()->where('applies_to', '!=', 'flights')->get();
        $made = 0;
        $codesUsed = 0;
        $nearlyFullDone = false;

        foreach ($packages as $p => $package) {
            $package->forceFill(['driver_id' => $drivers[$p % $drivers->count()]->id, 'guide_required' => $p % 2 === 0])->saveQuietly();
            $days = max(1, (int) $package->liveVersion->days);

            foreach ([12 + $p * 3, 30 + $p * 2, 55 + $p] as $d => $offset) {
                $start = today()->addDays($offset);
                $departure = PackageDeparture::query()->create([
                    'package_id' => $package->id,
                    'starts_on' => $start,
                    'start_time' => '07:00',
                    'ends_on' => $start->copy()->addDays($days - 1),
                    'capacity' => 12,
                    'status' => DepartureStatus::Open,
                    'trip_status' => $d === 0 ? TripStatus::Confirmed : TripStatus::Scheduled,
                    'guide_id' => $d === 0 && $p % 2 === 0 ? $guides[$p % $guides->count()]->id : null,
                    'created_by' => $package->owner_id,
                ]);

                $parties = match (true) {
                    ! $nearlyFullDone && $d === 0 => [2, 3, 3, 2],
                    $d === 0 => [2, 2],
                    $d === 1 => [3],
                    default => [],
                };
                $nearlyFullDone = $nearlyFullDone || ($d === 0);

                foreach ($parties as $i => $adults) {
                    $client = $clients[($made * 5) % $clients->count()];
                    $code = $codesUsed < 3 && $codes->isNotEmpty() ? $codes[$codesUsed % $codes->count()] : null;
                    $codesUsed += $code ? 1 : 0;
                    $status = match (true) {
                        $i === 0 => TravelBookingStatus::Confirmed,
                        $i === 1 && $d === 0 => TravelBookingStatus::Confirmed,
                        default => TravelBookingStatus::Pending,
                    };

                    $booking = PackageBooking::query()->create([
                        'reference' => PackageBooking::nextReference(),
                        'package_id' => $package->id,
                        'package_version_id' => $package->live_version_id,
                        'package_departure_id' => $departure->id,
                        'travel_client_id' => $client->id,
                        'salesperson_id' => $package->owner_id,
                        'influencer_code_id' => $code?->id,
                        'source' => BookingSource::Manual,
                        'adults' => $adults,
                        'children' => $i === 2 ? 1 : 0,
                        'currency' => $package->liveVersion->currency,
                        'amount_total' => $package->liveVersion->priceFor($adults, $i === 2 ? 1 : 0),
                        'status' => $status,
                        'confirmed_at' => $status === TravelBookingStatus::Confirmed ? now()->subDays(2) : null,
                        'hold_expires_at' => $status === TravelBookingStatus::Pending ? now()->addHours(36) : null,
                        'created_by' => $package->owner_id,
                        'created_at' => now()->subDays(10 - $i),
                    ]);

                    $this->seedGuests($booking, $client, $made);

                    if ($status === TravelBookingStatus::Confirmed) {
                        foreach (['customer_contacted', 'travel_details_sent'] as $item) {
                            BookingChecklistItem::query()->create(['package_booking_id' => $booking->id, 'item' => $item, 'completed_at' => now()->subDay(), 'completed_by' => $package->owner_id]);
                        }
                    }

                    $refresh->handle($booking);
                    $made++;
                }
            }
        }

        $confirmed = PackageBooking::query()->where('status', TravelBookingStatus::Confirmed)->orderBy('id')->get();

        if ($first = $confirmed->first()) {
            PackageCancellation::query()->create([
                'package_booking_id' => $first->id,
                'reason' => 'Client has a family emergency and cannot travel.',
                'policy_snapshot' => $first->version?->cancellation_policy,
                'refund_amount' => 0,
                'status' => CancellationStatus::Pending,
                'requested_by' => $first->salesperson_id,
            ]);
        }

        if ($second = $confirmed->skip(1)->first()) {
            $cancellation = PackageCancellation::query()->create([
                'package_booking_id' => $second->id,
                'reason' => 'Flight to Nairobi was cancelled.',
                'policy_snapshot' => $second->version?->cancellation_policy,
                'refund_amount' => 5000,
                'status' => CancellationStatus::Approved,
                'requested_by' => $second->salesperson_id,
                'decided_at' => now()->subDay(),
            ]);
            $second->forceFill(['status' => TravelBookingStatus::Cancelled, 'cancelled_at' => now()->subDay()])->save();
            TravelRefund::query()->create([
                'package_booking_id' => $second->id,
                'package_cancellation_id' => $cancellation->id,
                'amount' => 5000,
                'reason' => 'Cancellation: Flight to Nairobi was cancelled.',
                'status' => RefundStatus::Approved,
                'requested_by' => $second->salesperson_id,
                'approved_at' => now()->subDay(),
            ]);
            $refresh->handle($second);
        }
    }

    /**
     * The client travels as guest 1; the rest of the party gets demo names.
     */
    private function seedGuests(PackageBooking $booking, TravelClient $client, int $seed): void
    {
        $companions = ['Mary Njoroge', 'Kevin Odhiambo', 'Emma Clarke', 'Lukas Weber', 'Halima Said', 'Peter Mwangi', 'Chloe Martin', 'Ivan Petrov', 'Zawadi Wekesa', 'Noah Kimani'];
        $kids = ['Tumaini', 'Ella', 'Baraka', 'Leo'];
        $surname = str($client->name)->afterLast(' ')->toString();
        $position = 1;

        PackageBookingGuest::query()->create([
            'package_booking_id' => $booking->id, 'position' => $position++, 'full_name' => $client->name, 'type' => 'adult', 'is_booker' => true,
            'nationality' => $client->country, 'phone' => $client->phone, 'email' => $client->email,
            'id_number' => $client->country === 'Kenya' ? (string) (20000000 + $seed * 7331) : 'P'.(5000000 + $seed * 913),
        ]);

        for ($i = 1; $i < $booking->adults; $i++) {
            PackageBookingGuest::query()->create([
                'package_booking_id' => $booking->id, 'position' => $position++, 'full_name' => $companions[($seed + $i) % count($companions)], 'type' => 'adult',
                'nationality' => $client->country, 'special_requirements' => $i === 2 ? 'Vegetarian' : null,
            ]);
        }

        for ($i = 0; $i < $booking->children; $i++) {
            PackageBookingGuest::query()->create([
                'package_booking_id' => $booking->id, 'position' => $position++, 'full_name' => $kids[($seed + $i) % count($kids)].' '.$surname, 'type' => 'child',
                'date_of_birth' => today()->subYears(8 + $i)->subDays($seed * 11), 'nationality' => $client->country,
            ]);
        }
    }
}
