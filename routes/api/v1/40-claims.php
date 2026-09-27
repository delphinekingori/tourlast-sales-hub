<?php

use App\Http\Controllers\Api\V1\ClaimController;
use Illuminate\Support\Facades\Route;

/*
| Airtime and transport claims and their approval chain.
*/

Route::middleware('ability:claims:read')->group(function (): void {
    Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::get('claims/approvals', [ClaimController::class, 'approvals'])->name('claims.approvals');
    Route::get('claims/{claim}', [ClaimController::class, 'show'])->whereNumber('claim')->name('claims.show');
    Route::get('claims/{claim}/attachments/{attachment}', [ClaimController::class, 'attachment'])
        ->whereNumber(['claim', 'attachment'])->name('claims.attachments.show');
});

Route::middleware('ability:claims:write')->group(function (): void {
    Route::post('claims', [ClaimController::class, 'store'])->name('claims.store');
    Route::post('claims/{claim}/approve', [ClaimController::class, 'approve'])->whereNumber('claim')->name('claims.approve');
    Route::post('claims/{claim}/reject', [ClaimController::class, 'reject'])->whereNumber('claim')->name('claims.reject');
    Route::post('claims/{claim}/disburse', [ClaimController::class, 'disburse'])->whereNumber('claim')->name('claims.disburse');
});
