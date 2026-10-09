<?php

namespace Database\Seeders\Travel;

use App\Actions\Travel\Packages\CreatePackage;
use App\Actions\Travel\Packages\PublishPackage;
use App\Actions\Travel\Packages\ReviewPackage;
use App\Actions\Travel\Packages\SavePackage;
use App\Actions\Travel\Packages\SubmitPackage;
use App\Actions\Travel\Packages\SyncPackageMedia;
use App\Enums\Role;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\TravelProviderStatus;
use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\PackageContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Demo packages in every state, created through the real actions so the
 * versions, approvals and audit log are genuine.
 */
class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        if (Package::query()->exists()) {
            return;
        }

        Notification::fake();

        $sellers = User::query()->role(Role::TravelSalesperson->value)->orderBy('id')->get();
        $salesAdmin = User::query()->where('email', 'grace@tourlast.test')->first() ?? User::factory()->withRole(Role::SalesAdmin)->create();
        $superAdmin = User::query()->where('email', 'admin@tourlast.test')->first() ?? User::factory()->withRole(Role::SuperAdmin)->create();

        if ($sellers->isEmpty()) {
            $sellers = collect([User::factory()->withRole(Role::TravelSalesperson)->create()]);
        }

        $plans = [
            ['Masai Mara 3-Day Safari', 'Masai Mara', 'safari', 45000, 3, 'published'],
            ['Amboseli Elephant Escape', 'Amboseli', 'safari', 38000, 2, 'published'],
            ['Diani Beach Dhow Sunset Cruise', 'Diani', 'experience', 6500, 1, 'approved'],
            ['Lake Naivasha Boat & Hike Day Trip', 'Naivasha', 'day_trip', 9500, 1, 'price_change'],
            ['Tsavo East 4-Day Adventure', 'Tsavo East', 'safari', 62000, 4, 'pending_super'],
            ['Nairobi Culture & Food Walk', 'Nairobi', 'cultural', 4500, 1, 'pending_sales'],
            ['Hell\'s Gate Cycling Experience', 'Naivasha', 'activity', 5500, 1, 'draft'],
            ['Samburu Big Five Safari', 'Samburu', 'safari', 70000, 5, 'draft'],
        ];

        foreach ($plans as $index => [$name, $destination, $type, $price, $days, $state]) {
            $seller = $sellers[$index % $sellers->count()];
            Auth::setUser($seller);

            $provider = TravelProvider::query()->where('owner_id', $seller->id)
                ->whereIn('status', [TravelProviderStatus::Active, TravelProviderStatus::Contracted])
                ->withContractInForce()->inRandomOrder()->first()
                ?? TravelProvider::factory()->create(['owner_id' => $seller->id]);
            $contract = $provider->contracts()->get()->first(fn (ProviderContract $c) => $c->isInForce())
                ?? ProviderContract::factory()->create(['travel_provider_id' => $provider->id]);

            $content = [
                'name' => $name, 'short_description' => $name.' with '.$provider->name.'.', 'description' => 'A '.$days.'-day '.$type.' in '.$destination.', run by '.$provider->name.'.',
                'package_type' => $type, 'travel_provider_id' => $provider->id, 'provider_contract_id' => $contract->id,
                'destination' => $destination, 'country' => 'Kenya', 'days' => $days, 'nights' => max(0, $days - 1),
                'duration_label' => $days === 1 ? 'Full day' : $days.' days, '.($days - 1).' nights',
                'default_capacity' => 12, 'max_travelers' => 12, 'min_travelers' => 1,
                'highlights' => ['Expert local guide', 'Small group'], 'inclusions' => ['Transport', 'Entry fees', 'Bottled water'], 'exclusions' => ['Tips', 'Personal items'],
                'cancellation_policy' => 'Full refund up to 14 days before departure; 50% up to 7 days; no refund after.', 'refund_policy' => 'Refunds are paid within 10 working days.',
                'meeting_point' => 'Tourlast office, Westlands', 'currency' => 'KES', 'adult_price' => $price, 'child_price' => round($price * 0.7),
                'provider_price' => round($price * 0.85), 'net_provider_price' => round($price * 0.85), 'commission_amount' => round($price * 0.15),
            ];
            $itinerary = collect(range(1, $days))->map(fn (int $day) => ['title' => $day === 1 ? 'Depart for '.$destination : 'Day '.$day.' in '.$destination, 'meals' => ['breakfast', 'lunch']])->all();

            $package = app(CreatePackage::class)->handle($seller, $content, $itinerary, acceptDuplicate: true);

            $media = MediaAsset::query()->usable()->where('travel_provider_id', $provider->id)->limit(3)->pluck('id')->all()
                ?: MediaAsset::query()->usable()->inRandomOrder()->limit(2)->pluck('id')->all()
                ?: [MediaAsset::factory()->create(['travel_provider_id' => $provider->id, 'destination' => $destination])->id];
            app(SyncPackageMedia::class)->handle($seller, $package, $media);

            if ($state === 'draft') {
                continue;
            }

            app(SubmitPackage::class)->handle($seller, $package->fresh());

            if ($state === 'pending_sales') {
                continue;
            }

            app(ReviewPackage::class)->handle($salesAdmin, $package->fresh(), ApprovalDecision::Approved);

            if ($state === 'pending_super') {
                continue;
            }

            app(ReviewPackage::class)->handle($superAdmin, $package->fresh(), ApprovalDecision::Approved);

            if ($state === 'approved') {
                continue;
            }

            app(PublishPackage::class)->handle($seller, $package->fresh(), 'tourlast.com', null, 'Demo data');

            if ($state === 'price_change') {
                $live = $package->fresh()->liveVersion;
                $changed = [...PackageContent::contentOf($live), 'adult_price' => $price + 1500];
                app(SavePackage::class)->handle($seller, $package->fresh(), $changed, PackageContent::itineraryOf($live));
                app(SubmitPackage::class)->handle($seller, $package->fresh());
            }
        }

        Auth::forgetGuards();
    }
}
