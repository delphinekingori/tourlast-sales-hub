<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales Hub API, version 1 — https://sales.tourlast.com/api/v1
|--------------------------------------------------------------------------
|
| Every route needs a Sanctum token (Authorization: Bearer <token>) except
| signing in. Each route also names the token scope it needs, and the token
| owner must be allowed to do the same thing in the web Hub.
| Module routes live in routes/api/v1/*.php. Reference: docs/API.md.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [AuthController::class, 'store'])
        ->middleware('throttle:api-login')
        ->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        $files = glob(__DIR__.'/api/v1/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            require $file;
        }
    });
});
