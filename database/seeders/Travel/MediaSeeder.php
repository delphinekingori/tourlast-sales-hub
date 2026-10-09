<?php

namespace Database\Seeders\Travel;

use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Models\MediaAsset;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo Media Gallery: placeholder JPEGs (solid colour with a caption) on the
 * public disk, linked to existing providers when there are any.
 */
class MediaSeeder extends Seeder
{
    /** [title, category, destination, RGB] */
    private const Items = [
        ['Sunrise game drive', MediaCategory::Wildlife, 'Masai Mara', [196, 120, 40]],
        ['Lion pride at rest', MediaCategory::Wildlife, 'Masai Mara', [168, 132, 62]],
        ['Wildebeest crossing', MediaCategory::Wildlife, 'Masai Mara', [120, 98, 60]],
        ['Tented camp at dusk', MediaCategory::Lodges, 'Masai Mara', [82, 60, 48]],
        ['Elephants under Kilimanjaro', MediaCategory::Landscapes, 'Amboseli', [110, 140, 170]],
        ['Observation hill view', MediaCategory::Landscapes, 'Amboseli', [140, 160, 120]],
        ['Safari Land Cruiser', MediaCategory::Vehicles, 'Amboseli', [70, 90, 60]],
        ['Lodge swimming pool', MediaCategory::Lodges, 'Amboseli', [40, 130, 160]],
        ['Dhow sunset cruise', MediaCategory::Activities, 'Diani', [220, 110, 70]],
        ['Snorkelling the reef', MediaCategory::Activities, 'Diani', [30, 120, 150]],
        ['Seafood platter', MediaCategory::Food, 'Diani', [200, 160, 90]],
        ['Boat ride with hippos', MediaCategory::Activities, 'Naivasha', [60, 110, 100]],
        ['Crescent Island walk', MediaCategory::Experiences, 'Naivasha', [100, 140, 80]],
        ['Guide briefing', MediaCategory::Guides, 'Naivasha', [90, 100, 120]],
        ['Nairobi skyline', MediaCategory::Landscapes, 'Nairobi', [60, 80, 120]],
        ['Giraffe Centre visit', MediaCategory::Experiences, 'Nairobi', [170, 130, 70]],
    ];

    public function run(): void
    {
        if (MediaAsset::query()->exists()) {
            return;
        }

        $uploaders = User::query()->whereIn('email', ['aisha@tourlast.test', 'kevin@tourlast.test'])->pluck('id')->all();
        $providers = TravelProvider::query()->inRandomOrder()->pluck('id')->all();
        $canDraw = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');

        foreach (self::Items as $index => [$title, $category, $destination, $rgb]) {
            $path = 'media/'.now()->format('Y/m').'/'.Str::slug($title).'-'.Str::random(6).'.jpg';
            Storage::disk('public')->put($path, $canDraw ? $this->placeholder($title, $rgb) : '');

            MediaAsset::query()->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => Str::slug($title).'.jpg',
                'mime_type' => 'image/jpeg',
                'size' => Storage::disk('public')->size($path),
                'width' => 800,
                'height' => 534,
                'kind' => 'image',
                'title' => $title,
                'alt_text' => $title,
                'travel_provider_id' => $providers === [] ? null : $providers[$index % count($providers)],
                'destination' => $destination,
                'category' => $category,
                'tags' => [strtolower($destination), strtolower($category->label())],
                'source' => 'Provider supplied',
                'copyright_owner' => 'Provider',
                'usage_permission' => $index === 15 ? MediaUsagePermission::Restricted : MediaUsagePermission::Granted,
                'usage_notes' => $index === 15 ? 'Tourlast channels only; credit the provider.' : null,
                'uploaded_by' => $uploaders === [] ? null : $uploaders[$index % count($uploaders)],
            ]);
        }
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function placeholder(string $caption, array $rgb): string
    {
        $image = imagecreatetruecolor(800, 534);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $band = imagecolorallocatealpha($image, 0, 0, 0, 80);
        imagefilledrectangle($image, 0, 454, 800, 534, $band);
        imagestring($image, 5, 24, 484, $caption, imagecolorallocate($image, 255, 255, 255));

        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
