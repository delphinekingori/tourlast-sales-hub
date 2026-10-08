<?php

use App\Http\Controllers\DarajaCallbackController;
use Illuminate\Support\Facades\Route;

/*
| Safaricom Daraja callbacks for package payments (see App\Integrations\Mpesa).
| {secret} is DARAJA_CALLBACK_SECRET; print the full URLs with
| `php artisan travel:mpesa-register-urls`.
*/
Route::prefix('daraja/{secret}')
    ->middleware('throttle:600,1')
    ->name('daraja.')
    ->group(function (): void {
        Route::post('stk', [DarajaCallbackController::class, 'stk'])->name('stk');
        Route::post('c2b/validation', [DarajaCallbackController::class, 'validation'])->name('c2b.validation');
        Route::post('c2b/confirmation', [DarajaCallbackController::class, 'confirmation'])->name('c2b.confirmation');
    });
