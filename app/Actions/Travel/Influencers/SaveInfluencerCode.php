<?php

namespace App\Actions\Travel\Influencers;

use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\InfluencerCommissionType;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\InfluencerAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates a referral code for an influencer with its commission terms, or
 * changes the terms of a code that has not earned anything yet. Once a code
 * has commission lines its code and terms are fixed: create a new code.
 */
class SaveInfluencerCode
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?InfluencerCode $code = null): array
    {
        return [
            'code' => [
                'required', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('influencer_codes', 'code')->ignore($code?->id),
            ],
            'commission_type' => ['required', Rule::enum(InfluencerCommissionType::class)],
            'commission_value' => ['required', 'numeric'],
            'applies_to' => ['required', Rule::enum(InfluencerCodeScope::class)],
            'max_bookings' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers and dashes only.',
            'code.unique' => 'That code is already taken. Try another.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Influencer $influencer, array $data, ?InfluencerCode $code = null): InfluencerCode
    {
        abort_unless(InfluencerAccess::canManage($actor, $influencer), 403);
        abort_if($code && $code->influencer_id !== $influencer->id, 404);

        if ($code && $code->commissions()->exists()) {
            throw ValidationException::withMessages([
                'code' => 'This code has already earned commission, so its code and terms are fixed. Create a new code instead.',
            ]);
        }

        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $data['max_bookings'] = ($data['max_bookings'] ?? '') === '' ? null : $data['max_bookings'];
        $data['ends_on'] = ($data['ends_on'] ?? '') === '' ? null : $data['ends_on'];

        $data = Validator::make($data, self::rules($code), self::messages())->validate();
        $this->checkValue($data);

        if (! $code) {
            $code = InfluencerCode::query()->create([
                ...$data,
                'influencer_id' => $influencer->id,
                'status' => InfluencerCodeStatus::Active,
                'created_by' => $actor->id,
            ]);

            Audit::record($code, 'influencer.code_created', "Created code {$code->code} for {$influencer->name} ({$code->termsLabel()})");

            return $code;
        }

        $before = $code->only(array_keys($data));
        $code->fill($data)->save();
        $changes = Audit::diff($before, $code->only(array_keys($data)));

        if ($changes !== []) {
            Audit::record($code, 'influencer.code_updated', "Changed the terms of code {$code->code}", $changes);
        }

        return $code;
    }

    /**
     * Percentages run 0.5–50; fixed amounts must be above zero.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkValue(array $data): void
    {
        $value = (float) $data['commission_value'];
        $type = InfluencerCommissionType::from($data['commission_type']);

        $message = match (true) {
            $type === InfluencerCommissionType::Percentage && ($value < 0.5 || $value > 50) => 'A percentage must be between 0.5 and 50.',
            $type === InfluencerCommissionType::Fixed && $value <= 0 => 'Enter an amount above zero.',
            $type === InfluencerCommissionType::Fixed && $value > 1_000_000 => 'That amount is too large.',
            default => null,
        };

        if ($message) {
            throw ValidationException::withMessages(['commission_value' => $message]);
        }
    }
}
