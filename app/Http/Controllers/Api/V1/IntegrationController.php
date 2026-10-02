<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApplyProviderRecord;
use App\Actions\SyncOnboardings;
use App\Enums\Permission;
use App\Http\Resources\V1\ReferralCodeResource;
use App\Http\Resources\V1\SyncRunResource;
use App\Integrations\Tourlast\ProviderRecordMapper;
use App\Models\ReferralCode;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use Throwable;

/**
 * tourlast.com integration: push provider records, read ref-codes, read the sync log, trigger a sync.
 * The feed endpoints (push, ref-codes) authenticate with the one shared sync
 * token (no user, role or scope); the sync log and a manual sync need an
 * admin token with integration:read / integration:push plus
 * Permission::ManageIntegration.
 */
class IntegrationController extends ApiController
{
    /**
     * POST /integrations/tourlast/providers - {"provider": {...}} or {"providers": [...]} (max 100).
     * Auth: the shared sync token from php artisan hub:generate-token, no user account.
     * Each record goes through the same credit rules as the scheduled sync.
     * A record with is_deleted=true archives the property and its linked lead and
     * registry record (result "deleted"); is_deleted=false restores them.
     */
    public function push(Request $request, ProviderRecordMapper $mapper, ApplyProviderRecord $applyProviderRecord): JsonResponse
    {
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
                'deleted' => collect($results)->where('result', ApplyProviderRecord::Deleted)->count(),
                'failed' => $failed,
            ],
        ], $failed === count($rows) ? 422 : 200);
    }

    /**
     * GET /integrations/tourlast/ref-codes — every active referral code, so the source app can validate ?ref= and stamp its rows.
     * Auth: the shared sync token from php artisan hub:generate-token, no user account.
     */
    public function refCodes(): AnonymousResourceCollection
    {
        return ReferralCodeResource::collection(
            ReferralCode::query()->where('is_active', true)->with('user:id,name')->orderBy('code')->get()
        );
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
