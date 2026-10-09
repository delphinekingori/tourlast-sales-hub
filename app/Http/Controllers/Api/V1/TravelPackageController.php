<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Travel\Packages\CreatePackage;
use App\Actions\Travel\Packages\PublishPackage;
use App\Actions\Travel\Packages\ReviewPackage;
use App\Actions\Travel\Packages\SavePackage;
use App\Actions\Travel\Packages\SubmitPackage;
use App\Actions\Travel\Packages\UnpublishPackage;
use App\Enums\Travel\ApprovalDecision;
use App\Http\Resources\V1\PackageResource;
use App\Models\Package;
use App\Support\Travel\PackageContent;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Tour and experience packages: read the catalogue, create and change
 * drafts, submit, review (two different approvers, never the creator),
 * publish and unpublish. Every rule lives in the App\Actions\Travel\Packages
 * actions shared with the web app.
 */
class TravelPackageController extends ApiController
{
    /**
     * GET /travel/packages — Packages (?q, status, provider_id, mine).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        $packages = Package::query()
            ->with(['provider:id,name', 'owner:id,name,avatar_path', 'liveVersion', 'workingVersion'])
            ->when(! $request->boolean('archived'), fn (Builder $query) => $query->current())
            ->search((string) $request->query('q', ''))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('provider_id'), fn (Builder $query) => $query->where('travel_provider_id', $request->integer('provider_id')))
            ->when($request->boolean('mine'), fn (Builder $query) => $query->where('owner_id', $viewer->id))
            ->latest('updated_at')
            ->paginate($this->perPage($request));

        return PackageResource::collection($packages);
    }

    /**
     * GET /travel/packages/{id} — One package with live and working versions, itinerary, media and approval history.
     */
    public function show(Request $request, int $package): PackageResource
    {
        TravelAccess::abortUnlessWorks($this->user($request));

        return new PackageResource($this->load(Package::query()->findOrFail($package)));
    }

    /**
     * POST /travel/packages — Create a draft package (v1.0). Send accept_duplicate=true to continue past a possible duplicate (managers).
     */
    public function store(Request $request, CreatePackage $createPackage): JsonResponse
    {
        $request->validate(['itinerary' => ['sometimes', 'array'], 'accept_duplicate' => ['sometimes', 'boolean']]);

        $package = $createPackage->handle(
            $this->user($request),
            $request->except(['itinerary', 'accept_duplicate']),
            (array) $request->input('itinerary', []),
            $request->boolean('accept_duplicate'),
        );

        return (new PackageResource($this->load($package)))->response()->setStatusCode(201);
    }

    /**
     * PATCH /travel/packages/{id} — Change a package. Changes to an approved package create the next version; material changes need approval.
     */
    public function update(Request $request, int $package, SavePackage $savePackage): PackageResource
    {
        $request->validate(['itinerary' => ['sometimes', 'array'], 'accept_duplicate' => ['sometimes', 'boolean']]);
        $record = Package::query()->with(['workingVersion', 'liveVersion'])->findOrFail($package);
        $current = $record->latestVersion();
        abort_if($current === null, 422, 'This package has no content yet.');

        $savePackage->handle(
            $this->user($request),
            $record,
            array_replace(PackageContent::contentOf($current), $request->except(['itinerary', 'accept_duplicate'])),
            $request->has('itinerary') ? (array) $request->input('itinerary') : PackageContent::itineraryOf($current),
            $request->boolean('accept_duplicate'),
        );

        return new PackageResource($this->load($record->fresh()));
    }

    /**
     * POST /travel/packages/{id}/submit — Submit the draft for approval (only when the readiness checklist passes).
     */
    public function submit(Request $request, int $package, SubmitPackage $submitPackage): PackageResource
    {
        $record = Package::query()->findOrFail($package);
        $submitPackage->handle($this->user($request), $record);

        return new PackageResource($this->load($record->fresh()));
    }

    /**
     * POST /travel/packages/{id}/review — Approve, reject or request changes (decision, reason). Sales Admin first, then Super Admin.
     */
    public function review(Request $request, int $package, ReviewPackage $reviewPackage): PackageResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(ApprovalDecision::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $record = Package::query()->findOrFail($package);

        $reviewPackage->handle($this->user($request), $record, ApprovalDecision::from($data['decision']), $data['reason'] ?? null);

        return new PackageResource($this->load($record->fresh()));
    }

    /**
     * POST /travel/packages/{id}/publish — Mark an approved package as published (channel, url; override_reason for Super Admin only).
     */
    public function publish(Request $request, int $package, PublishPackage $publishPackage): PackageResource
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:500'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $record = Package::query()->findOrFail($package);

        $publishPackage->handle($this->user($request), $record, $data['channel'], $data['url'] ?? null, $data['override_reason'] ?? null);

        return new PackageResource($this->load($record->fresh()));
    }

    /**
     * POST /travel/packages/{id}/unpublish — Take a published package off sale (reason optional).
     */
    public function unpublish(Request $request, int $package, UnpublishPackage $unpublishPackage): PackageResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $record = Package::query()->findOrFail($package);

        $unpublishPackage->handle($this->user($request), $record, $data['reason'] ?? null);

        return new PackageResource($this->load($record->fresh()));
    }

    private function load(Package $package): Package
    {
        return $package->load([
            'provider:id,name', 'owner:id,name,avatar_path', 'creator:id,name,avatar_path',
            'liveVersion.itineraryDays', 'workingVersion.itineraryDays', 'media', 'approvals.user:id,name',
        ]);
    }
}
