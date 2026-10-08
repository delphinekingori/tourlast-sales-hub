<?php

namespace App\Actions\Travel\Resources;

use App\Enums\Travel\ResourceStatus;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds or edits a driver or guide. Any Travel Sales user may keep the shared
 * list up to date; every change is audited.
 */
class SaveTripResource
{
    /**
     * @param  'driver'|'guide'  $kind
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, string $kind, array $input, Driver|Guide|null $resource = null): Driver|Guide
    {
        TravelAccess::abortUnlessWorks($actor);

        $common = [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'travel_provider_id' => ['nullable', 'integer', Rule::exists('travel_providers', 'id')],
            'status' => ['required', Rule::enum(ResourceStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        $rules = $kind === 'driver'
            ? $common + [
                'vehicle' => ['nullable', 'string', 'max:120'],
                'vehicle_registration' => ['nullable', 'string', 'max:20'],
                'license_number' => ['nullable', 'string', 'max:40'],
            ]
            : $common + [
                'languages' => ['nullable', 'array'],
                'languages.*' => ['string', 'max:40'],
                'specialization' => ['nullable', 'string', 'max:120'],
            ];

        $data = Validator::make($input, $rules)->validate();
        $data['travel_provider_id'] = $data['travel_provider_id'] ?? null;

        $resource ??= $kind === 'driver' ? new Driver : new Guide;
        $isNew = ! $resource->exists;
        $before = $resource->only(array_keys($data));

        $resource->fill($data)->save();

        Audit::record(
            $resource,
            $kind.($isNew ? '.created' : '.updated'),
            ucfirst($kind).($isNew ? ' added: ' : ' changed: ').$resource->name,
            $isNew ? [] : Audit::diff($before, $resource->only(array_keys($data))),
        );

        return $resource;
    }
}
