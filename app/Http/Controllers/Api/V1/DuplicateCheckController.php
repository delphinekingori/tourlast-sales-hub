<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DuplicateMatchResource;
use App\Support\PropertyDuplicateCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Hub-wide duplicate rule as a lookup: does Tourlast already know this
 * property (registry, any salesperson's leads, tourlast.com signups)?
 */
class DuplicateCheckController extends ApiController
{
    /**
     * POST /duplicates/check
     */
    public function __invoke(Request $request, PropertyDuplicateCheck $duplicateCheck): AnonymousResourceCollection
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:190'],
            'phones' => ['nullable', 'array', 'max:10'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'emails' => ['nullable', 'array', 'max:10'],
            'emails.*' => ['nullable', 'string', 'max:190'],
            'website' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'kra_pin' => ['nullable', 'string', 'max:30'],
            'except_lead_id' => ['nullable', 'integer'],
            'except_engagement_id' => ['nullable', 'integer'],
        ]);

        $matches = $duplicateCheck->find([
            'name' => $data['name'] ?? '',
            'trading_name' => $data['trading_name'] ?? '',
            'city' => $data['city'] ?? '',
            'phones' => array_values($data['phones'] ?? []),
            'emails' => array_values($data['emails'] ?? []),
            'website' => $data['website'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'kra_pin' => $data['kra_pin'] ?? null,
        ], $this->user($request), isset($data['except_lead_id']) ? (int) $data['except_lead_id'] : null, isset($data['except_engagement_id']) ? (int) $data['except_engagement_id'] : null);

        return DuplicateMatchResource::collection($matches);
    }
}
