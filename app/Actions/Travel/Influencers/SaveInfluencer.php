<?php

namespace App\Actions\Travel\Influencers;

use App\Models\Influencer;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\InfluencerAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds an influencer for the acting travel salesperson, or updates one they
 * (or a Travel manager) may change. An influencer can be on several
 * platforms, each once; the first is also kept on the influencer itself.
 */
class SaveInfluencer
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'platforms' => ['array', 'max:'.count(Influencer::Platforms)],
            'platforms.*.platform' => ['required', 'distinct', Rule::in(array_keys(Influencer::Platforms))],
            'platforms.*.handle' => ['nullable', 'string', 'max:120'],
            'platforms.*.url' => ['nullable', 'url:http,https', 'max:255'],
            'payout_method' => ['nullable', Rule::in(['mpesa', 'bank'])],
            'payout_details' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'platforms.*.platform.required' => 'Choose the platform.',
            'platforms.*.platform.distinct' => 'Each platform can only be added once.',
            'platforms.*.url.url' => 'Enter a full link, starting with https://.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?Influencer $influencer = null): Influencer
    {
        abort_unless($influencer ? InfluencerAccess::canManage($actor, $influencer) : InfluencerAccess::canCreate($actor), 403);

        $data = Validator::make($data, self::rules(), self::messages())->validate();
        $platforms = array_values(array_map(fn (array $platform): array => [
            'platform' => $platform['platform'],
            'handle' => trim((string) ($platform['handle'] ?? '')) ?: null,
            'url' => trim((string) ($platform['url'] ?? '')) ?: null,
        ], $data['platforms'] ?? []));
        unset($data['platforms']);

        $data = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $data);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['platform'] = $platforms[0]['platform'] ?? null;
        $data['handle'] = $platforms[0]['handle'] ?? null;

        return DB::transaction(function () use ($influencer, $data, $platforms, $actor): Influencer {
            if (! $influencer) {
                $influencer = Influencer::query()->create([...$data, 'owner_id' => $actor->id]);
                $this->syncPlatforms($influencer, $platforms);
                Audit::record($influencer, 'influencer.created', 'Added influencer '.$influencer->name.' ('.$influencer->platformSummary().')');

                return $influencer;
            }

            $before = [...$influencer->only(array_keys($data)), 'platforms' => $influencer->platformSummary('None')];
            $influencer->fill($data)->save();
            $this->syncPlatforms($influencer, $platforms);

            $changes = Audit::diff($before, [...$influencer->only(array_keys($data)), 'platforms' => $influencer->platformSummary('None')]);
            unset($changes['platform'], $changes['handle']);

            if (array_key_exists('payout_details', $changes)) {
                $changes['payout_details'] = ['(hidden)', '(hidden)'];
            }

            if ($changes !== []) {
                Audit::record($influencer, 'influencer.updated', 'Updated influencer '.$influencer->name, $changes);
            }

            return $influencer;
        });
    }

    /**
     * @param  list<array{platform: string, handle: ?string, url: ?string}>  $platforms
     */
    private function syncPlatforms(Influencer $influencer, array $platforms): void
    {
        $influencer->platforms()->delete();

        foreach ($platforms as $position => $platform) {
            $influencer->platforms()->create([...$platform, 'position' => $position]);
        }

        $influencer->unsetRelation('platforms');
    }
}
