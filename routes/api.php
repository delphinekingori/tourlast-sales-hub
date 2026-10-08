<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales Hub API, version 1 — https://sales-hub.tourlast.com/api/v1
|--------------------------------------------------------------------------
|
| Every route needs a Sanctum token (Authorization: Bearer <token>) except
| signing in. Each route also names the token scope it needs, and the token
| owner must be allowed to do the same thing in the web Hub.
| Module routes live in routes/api/v1/*.php. Reference: docs/API.md.
|
| The tourlast.com integration is the one exception: its feed endpoints
| authenticate with the single shared sync token (TOURLAST_API_TOKEN here,
| TOURLAST_HUB_TOKEN in each source app) instead of a user token, so the file
| is required outside the Sanctum group below and picks its own middleware.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [AuthController::class, 'store'])
        ->middleware('throttle:api-login')
        ->name('auth.tokens.store');

    $files = glob(__DIR__.'/api/v1/*.php') ?: [];
    sort($files);

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () use ($files): void {
        foreach ($files as $file) {
            if (basename($file) === '70-integration.php') {
                continue;
            }

            require $file;
        }
    });

    require __DIR__.'/api/v1/70-integration.php';
});
