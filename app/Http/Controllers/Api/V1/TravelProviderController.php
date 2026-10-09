<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Travel\ContractStatus;
use App\Http\Resources\V1\ProviderContractResource;
use App\Http\Resources\V1\TravelProviderResource;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tour and experience providers and their contracts (read). Travel
 * salespeople read every provider; commission figures follow the contract
 * visibility rule. Mirrors App\Livewire\Travel\Providers and Contracts.
 */
class TravelProviderController extends ApiController
{
    /**
     * GET /travel/providers — Providers (?q, status, type, mine).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        $providers = TravelProvider::query()
            ->with('owner:id,name,avatar_path')
            ->withCount('packages')
            ->when(! $request->boolean('archived'), fn (Builder $query) => $query->current())
            ->search((string) $request->query('q', ''))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('provider_type', (string) $request->query('type')))
            ->when($request->boolean('mine'), fn (Builder $query) => $query->where('owner_id', $viewer->id))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return TravelProviderResource::collection($providers);
    }

    /**
     * GET /travel/providers/{id} — One provider with its contracts.
     */
    public function show(Request $request, int $provider): TravelProviderResource
    {
        TravelAccess::abortUnlessWorks($this->user($request));

        return new TravelProviderResource(TravelProvider::query()
            ->with(['owner:id,name,avatar_path', 'contracts.provider', 'contracts.documents'])
            ->withCount('packages')
            ->findOrFail($provider));
    }

    /**
     * GET /travel/contracts — Provider contracts (?status incl. expiring_soon / expired, provider_id).
     */
    public function contracts(Request $request): AnonymousResourceCollection
    {
        TravelAccess::abortUnlessWorks($this->user($request));
        $status = ContractStatus::tryFrom((string) $request->query('status', ''));

        $contracts = ProviderContract::query()
            ->with('provider')
            ->when($status, fn (Builder $query) => $query->withEffectiveStatus($status))
            ->when($request->filled('provider_id'), fn (Builder $query) => $query->where('travel_provider_id', $request->integer('provider_id')))
            ->orderByRaw('ends_on is null')
            ->orderBy('ends_on')
            ->paginate($this->perPage($request));

        return ProviderContractResource::collection($contracts);
    }

    /**
     * GET /travel/contracts/{id} — One contract (documents are listed, never downloaded through the API).
     */
    public function contract(Request $request, int $contract): ProviderContractResource
    {
        TravelAccess::abortUnlessWorks($this->user($request));

        return new ProviderContractResource(ProviderContract::query()->with(['provider', 'documents'])->findOrFail($contract));
    }
}
