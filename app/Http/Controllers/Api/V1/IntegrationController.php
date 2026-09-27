<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApplyProviderRecord;
use App\Actions\SyncOnboardings;
use App\Enums\Permission;
use App\Http\Resources\V1\SyncRunResource;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Throwable;

/**
 * tourlast.com integration: push provider records, read the sync log, trigger a sync.
 * The token owner must be allowed to manage the integration (Super Admin);
 * tourlast.com should use a dedicated service account with only integration:push.
 */
class IntegrationController extends ApiController
{
    /**
     * POST /integrations/tourlast/providers — {"provider": {...}} or {"providers": [...]} (max 100).
     * Each record goes through the same credit rules as the scheduled sync.
     */
    public function push(Request $request, ProviderRecordMapper $mapper, ApplyProviderRecord $applyProviderRecord): JsonResponse
    {
        $this->requirePermission($request, Permission::PushProviderRecords);

        $request->validate([
            'provider' => ['required_without:providers', 'array'],
            'providers' => ['required_without:provider', 'array', 'min:1', 'max:100'],
            'providers.*' => ['array'],
        ]);

        $rows = $request->has('providers') ? (array) $request->input('providers') : [(array) $request->input('provider')];
        $results = [];

        foreach ($rows as $index => $row) {
            $propertyId = trim((string) ($row['property_id'] ?? '')) ?: null;

            try {
                $results[] = ['index' => $index, 'property_id' => $propertyId, 'result' => $applyProviderRecord->handle($mapper->fromArray((array) $row), 'api-push')];
            } catch (InvalidArgumentException $exception) {
                $results[] = ['index' => $index, 'property_id' => $propertyId, 'error' => $exception->getMessage()];
            } catch (Throwable $exception) {
                report($exception);
                $results[] = ['index' => $index, 'property_id' => $propertyId, 'error' => 'This record could not be processed.'];
            }
        }

        $failed = collect($results)->whereNotNull('error')->count();

        return response()->json([
            'data' => $results,
            'meta' => [
                'received' => count($rows),
                'created' => collect($results)->where('result', ApplyProviderRecord::Created)->count(),
                'updated' => collect($results)->where('result', ApplyProviderRecord::Updated)->count(),
                'unchanged' => collect($results)->where('result', ApplyProviderRecord::Unchanged)->count(),
                'failed' => $failed,
            ],
        ], $failed === count($rows) ? 422 : 200);
    }

    /**
     * GET /integrations/tourlast/sync-runs — the sync log, newest first.
     */
    public function syncRuns(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, Permission::ManageIntegration);

        return SyncRunResource::collection(SyncRun::query()->latest('id')->paginate($this->perPage($request, 20)));
    }

    /**
     * POST /integrations/tourlast/sync — run a sync now (mode: incremental|full).
     */
    public function sync(Request $request, SyncOnboardings $syncOnboardings): JsonResponse
    {
        $this->requirePermission($request, Permission::ManageIntegration);

        $data = $request->validate(['mode' => ['sometimes', 'in:incremental,full']]);
        $run = $syncOnboardings->handle($data['mode'] ?? 'incremental');

        return (new SyncRunResource($run))->response()->setStatusCode($run->succeeded() ? 200 : 502);
    }
}
