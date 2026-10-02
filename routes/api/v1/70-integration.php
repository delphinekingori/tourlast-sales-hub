<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use Illuminate\Support\Facades\Route;

/*
| tourlast.com integration.
|
| The feed endpoints (push, ref-codes) authenticate with the one shared sync
| token from php artisan hub:generate-token — no user, role, scope or
| permission. The sync log and a manual sync stay on a normal admin API
| token: integration:read / integration:push plus Permission::ManageIntegration.
*/

Route::middleware(['shared-token', 'throttle:api'])->group(function (): void {
    Route::post('integrations/tourlast/providers', [IntegrationController::class, 'push'])
        ->name('integrations.tourlast.providers');

    Route::get('integrations/tourlast/ref-codes', [IntegrationController::class, 'refCodes'])
        ->name('integrations.tourlast.ref-codes');
});

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
    Route::post('integrations/tourlast/sync', [IntegrationController::class, 'sync'])
        ->middleware('ability:integration:push')
        ->name('integrations.tourlast.sync');

    Route::get('integrations/tourlast/sync-runs', [IntegrationController::class, 'syncRuns'])
        ->middleware('ability:integration:read')
        ->name('integrations.tourlast.sync-runs');
});
