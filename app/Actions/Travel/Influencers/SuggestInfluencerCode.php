<?php

namespace App\Actions\Travel\Influencers;

use App\Models\Influencer;
use App\Models\InfluencerCode;
use Illuminate\Support\Str;

/**
 * A free code built from the influencer's handle or first name plus digits,
 * e.g. AMINA10 or AMINAKE24.
 */
class SuggestInfluencerCode
{
    public function handle(Influencer $influencer): string
    {
        $source = $influencer->handle ?: Str::before(trim($influencer->name), ' ');
        $stem = Str::of(Str::ascii((string) $source))->upper()->replaceMatches('/[^A-Z0-9]/', '')->substr(0, 12)->toString();

        if (strlen($stem) < 2) {
            $stem = 'TL';
        }

        foreach ([10, 15, 20, 24, 25] as $suffix) {
            if (! $this->taken($stem.$suffix)) {
                return $stem.$suffix;
            }
        }

        do {
            $candidate = $stem.random_int(10, 9999);
        } while ($this->taken($candidate));

        return $candidate;
    }

    private function taken(string $code): bool
    {
        return InfluencerCode::query()->where('code', strtoupper($code))->exists();
    }
}
