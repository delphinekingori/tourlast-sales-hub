<?php

namespace Database\Factories;

use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Models\MediaAsset;
use App\Models\TravelProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $file = fake()->uuid().'.jpg';

        return [
            'disk' => 'public',
            'path' => 'media/'.$file,
            'original_name' => $file,
            'mime_type' => 'image/jpeg',
            'size' => 120_000,
            'width' => 1600,
            'height' => 1067,
            'kind' => 'image',
            'title' => fake()->randomElement(['Sunrise game drive', 'Lodge deck', 'Balloon safari', 'Beach dhow', 'Elephant herd']),
            'alt_text' => 'Safari scene',
            'travel_provider_id' => TravelProvider::factory(),
            'destination' => fake()->randomElement(['Masai Mara', 'Amboseli', 'Diani', 'Naivasha']),
            'category' => MediaCategory::Wildlife,
            'tags' => ['safari'],
            'source' => 'Provider supplied',
            'usage_permission' => MediaUsagePermission::Granted,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['usage_permission' => MediaUsagePermission::Revoked]);
    }
}
