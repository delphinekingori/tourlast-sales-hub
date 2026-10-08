<?php

namespace Database\Factories;

use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageItineraryDay;
use App\Models\PackageVersion;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft package with a complete v1.0 draft (working version). Use
 * approved() or published() for one with a live version that can be sold.
 *
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'PKG-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement(['Masai Mara', 'Amboseli', 'Tsavo East', 'Lake Naivasha', 'Diani Beach']).' '.fake()->numberBetween(2, 5).'-Day '.fake()->randomElement(['Safari', 'Escape', 'Adventure']),
            'package_type' => 'safari',
            'destination' => 'Masai Mara',
            'country' => 'Kenya',
            'travel_provider_id' => TravelProvider::factory(),
            'provider_contract_id' => fn (array $attributes) => ProviderContract::factory()->create(['travel_provider_id' => $attributes['travel_provider_id']])->id,
            'status' => PackageStatus::Draft,
            'owner_id' => fn (array $attributes) => TravelProvider::find($attributes['travel_provider_id'])->owner_id,
            'created_by' => fn (array $attributes) => $attributes['owner_id'],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Package $package): void {
            if ($package->versions()->exists()) {
                return;
            }

            $version = PackageVersion::factory()->create([
                'package_id' => $package->id,
                'name' => $package->name,
                'destination' => $package->destination,
                'package_type' => $package->package_type,
                'travel_provider_id' => $package->travel_provider_id,
                'provider_contract_id' => $package->provider_contract_id,
                'created_by' => $package->created_by,
                'status' => match ($package->status) {
                    PackageStatus::Draft => PackageVersionStatus::Draft,
                    PackageStatus::PendingApproval => PackageVersionStatus::Submitted,
                    default => PackageVersionStatus::Approved,
                },
                'approved_at' => in_array($package->status, [PackageStatus::Draft, PackageStatus::PendingApproval], true) ? null : now(),
            ]);

            foreach ([1 => 'Nairobi to the Mara', 2 => 'Full day in the Mara', 3 => 'Return to Nairobi'] as $day => $title) {
                PackageItineraryDay::query()->create([
                    'package_version_id' => $version->id,
                    'day_number' => $day,
                    'title' => $title,
                    'description' => 'Game drives and meals as listed.',
                    'meals' => ['breakfast', 'lunch', 'dinner'],
                ]);
            }

            $isLive = ! in_array($package->status, [PackageStatus::Draft, PackageStatus::PendingApproval], true);

            $package->forceFill($isLive
                ? ['live_version_id' => $version->id, 'working_version_id' => null]
                : ['working_version_id' => $version->id])->saveQuietly();
        });
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => PackageStatus::Approved]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PackageStatus::Published,
            'published_at' => now(),
            'published_channel' => 'tourlast.com',
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (): array => ['status' => PackageStatus::PendingApproval]);
    }
}
