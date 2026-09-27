<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Tokens, the signed-in person, option lists and notifications.
| Loaded inside the auth:sanctum + active + throttle:api group in routes/api.php.
*/

Route::get('auth/tokens', [AuthController::class, 'index'])->name('auth.tokens.index');
Route::delete('auth/tokens/current', [AuthController::class, 'destroyCurrent'])->name('auth.tokens.current');
Route::delete('auth/tokens/{token}', [AuthController::class, 'destroy'])->whereNumber('token')->name('auth.tokens.destroy');

Route::get('meta', MetaController::class)->name('meta');

Route::middleware('ability:profile')->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');
    Route::get('me/dashboard', [MeController::class, 'dashboard'])->name('me.dashboard');
    Route::get('me/referral', [MeController::class, 'referral'])->name('me.referral');
    Route::get('me/target', [MeController::class, 'target'])->name('me.target');
    Route::put('me/target', [MeController::class, 'updateTarget'])->name('me.target.update');
    Route::get('me/payment-details', [MeController::class, 'paymentDetails'])->name('me.payment-details');
    Route::put('me/payment-details', [MeController::class, 'updatePaymentDetails'])->name('me.payment-details.update');
    Route::get('me/earnings', [MeController::class, 'earnings'])->name('me.earnings');
});

Route::get('notifications', [NotificationController::class, 'index'])->middleware('ability:notifications:read')->name('notifications.index');
Route::middleware('ability:notifications:write')->group(function (): void {
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{key}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('announcements', [NotificationController::class, 'publish'])->name('announcements.store');
});
