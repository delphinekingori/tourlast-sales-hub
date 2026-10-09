<?php

namespace App\Livewire\Travel\Influencers\Concerns;

use App\Models\Influencer;
use App\Models\InfluencerPlatform;
use Illuminate\Validation\ValidationException;

/**
 * Form helpers shared by the influencer pages.
 */
trait InfluencerForms
{
    /**
     * @return array<string, mixed>
     */
    protected static function blankInfluencer(?Influencer $influencer = null): array
    {
        return [
            'name' => $influencer?->name ?? '',
            'phone' => $influencer?->phone ?? '',
            'email' => $influencer?->email ?? '',
            'platforms' => $influencer
                ? $influencer->platforms->map(fn (InfluencerPlatform $platform): array => ['platform' => $platform->platform, 'handle' => (string) $platform->handle, 'url' => (string) $platform->url])->all()
                : [self::blankPlatform('instagram')],
            'payout_method' => $influencer?->payout_method ?? '',
            'payout_details' => $influencer?->payout_details ?? '',
            'notes' => $influencer?->notes ?? '',
            'is_active' => $influencer?->is_active ?? true,
        ];
    }

    /**
     * @return array{platform: string, handle: string, url: string}
     */
    protected static function blankPlatform(string $platform = ''): array
    {
        return ['platform' => $platform, 'handle' => '', 'url' => ''];
    }

    /**
     * Adds a platform row, defaulting to the first platform not yet used.
     */
    public function addPlatform(): void
    {
        $used = array_column($this->influencerForm['platforms'] ?? [], 'platform');
        $next = collect(array_keys(Influencer::Platforms))->first(fn (string $platform): bool => ! in_array($platform, $used, true));

        if ($next !== null) {
            $this->influencerForm['platforms'][] = self::blankPlatform($next);
        }
    }

    public function removePlatform(int $index): void
    {
        unset($this->influencerForm['platforms'][$index]);
        $this->influencerForm['platforms'] = array_values($this->influencerForm['platforms']);
        $this->resetErrorBag('influencerForm.platforms');
    }

    /**
     * Action rules re-keyed for a Livewire form array ("name" → "form.name").
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected static function prefixed(string $form, array $rules): array
    {
        return collect($rules)->mapWithKeys(fn (mixed $rule, string $key) => [$form.'.'.$key => $rule])->all();
    }

    /**
     * Run an action and show its validation errors on the form's fields.
     */
    protected function runForm(string $form, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => [$form.'.'.$key => $messages])->all()
            );
        }
    }
}
