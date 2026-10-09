<?php

namespace Database\Seeders\Travel;

use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\InfluencerCommissionType;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo influencers and codes for the two demo travel salespeople. Bookings
 * and flights seeders run afterwards and book with some of these codes.
 */
class InfluencersSeeder extends Seeder
{
    public function run(): void
    {
        $aisha = User::query()->where('email', 'aisha@tourlast.test')->first();
        $kevin = User::query()->where('email', 'kevin@tourlast.test')->first();

        if (! $aisha || ! $kevin) {
            return;
        }

        $influencers = [
            ['Amina Wanjiru', 'instagram', '@aminatravels', $aisha, [
                ['AMINA10', InfluencerCommissionType::Percentage, 10, InfluencerCodeScope::Packages, 20, -20, 70, InfluencerCodeStatus::Active],
                ['AMINA-KE24', InfluencerCommissionType::Fixed, 1500, InfluencerCodeScope::All, 5, -10, 50, InfluencerCodeStatus::Active],
            ]],
            ['Brian Kiprop', 'tiktok', '@safaribrian', $aisha, [
                ['SAFARIB15', InfluencerCommissionType::Percentage, 7.5, InfluencerCodeScope::Packages, null, -30, 90, InfluencerCodeStatus::Active],
            ]],
            ['Zawadi Achieng', 'youtube', '@zawadiexplores', $kevin, [
                ['ZAWADI20', InfluencerCommissionType::Fixed, 2000, InfluencerCodeScope::Packages, 10, -120, -15, InfluencerCodeStatus::Ended],
                ['ZAWADI-FLY', InfluencerCommissionType::Percentage, 2, InfluencerCodeScope::Flights, 50, -5, 120, InfluencerCodeStatus::Active],
            ]],
            ['Coast Diaries', 'blog', 'coastdiaries.co.ke', $kevin, [
                ['COAST25', InfluencerCommissionType::Percentage, 5, InfluencerCodeScope::Packages, 30, -3, 60, InfluencerCodeStatus::Paused],
            ]],
            ['Njeri Mwangi', 'instagram', '@njeri.wanders', $kevin, [
                ['NJERI10', InfluencerCommissionType::Fixed, 1000, InfluencerCodeScope::Packages, 15, 0, 90, InfluencerCodeStatus::Active],
            ]],
        ];

        foreach ($influencers as [$name, $platform, $handle, $owner, $codes]) {
            $influencer = Influencer::query()->firstOrCreate(['name' => $name, 'owner_id' => $owner->id], [
                'platform' => $platform,
                'handle' => $handle,
                'phone' => '07'.fake()->numerify('########'),
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
                'payout_method' => 'mpesa',
                'payout_details' => '07'.fake()->numerify('########').' ('.$name.')',
                'is_active' => true,
            ]);

            if (! $influencer->platforms()->exists()) {
                $extra = ['Amina Wanjiru' => ['tiktok', '@amina.travels'], 'Zawadi Achieng' => ['instagram', '@zawadi.explores']][$name] ?? null;

                foreach (array_filter([[$platform, $handle], $extra]) as $position => [$extraPlatform, $extraHandle]) {
                    $influencer->platforms()->create(['platform' => $extraPlatform, 'handle' => $extraHandle, 'position' => $position]);
                }
            }

            foreach ($codes as [$code, $type, $value, $scope, $max, $startOffset, $endOffset, $status]) {
                InfluencerCode::query()->firstOrCreate(['code' => $code], [
                    'influencer_id' => $influencer->id,
                    'commission_type' => $type,
                    'commission_value' => $value,
                    'applies_to' => $scope,
                    'max_bookings' => $max,
                    'starts_on' => today()->addDays($startOffset),
                    'ends_on' => today()->addDays($endOffset),
                    'status' => $status,
                    'created_by' => $owner->id,
                ]);
            }
        }
    }
}
