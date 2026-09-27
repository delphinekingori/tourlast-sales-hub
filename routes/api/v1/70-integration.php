<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use Illuminate\Support\Facades\Route;

/*
| tourlast.com integration: push provider records, sync log, run a sync.
*/

Route::middleware('ability:integration:push')->group(function (): void {
    Route::post('integrations/tourlast/providers', [IntegrationController::class, 'push'])->name('integrations.tourlast.providers');
    Route::post('integrations/tourlast/sync', [IntegrationController::class, 'sync'])->name('integrations.tourlast.sync');
});

Route::get('integrations/tourlast/sync-runs', [IntegrationController::class, 'syncRuns'])
    ->middleware('ability:integration:read')
    ->name('integrations.tourlast.sync-runs');
