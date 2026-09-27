<?php

use App\Http\Controllers\Api\V1\DuplicateCheckController;
use App\Http\Controllers\Api\V1\LeadActivityController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
| Leads, activities, schedule/calendar and the duplicate check.
| Loaded inside the auth:sanctum + active + throttle:api group in routes/api.php.
*/

Route::middleware('ability:leads:read')->group(function (): void {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
    Route::get('leads/{lead}/activities', [LeadActivityController::class, 'index'])->whereNumber('lead')->name('leads.activities.index');
});

Route::middleware('ability:leads:write')->group(function (): void {
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::post('leads/transfer-bulk', [LeadController::class, 'transferBulk'])->name('leads.transfer-bulk');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->name('leads.update');
    Route::post('leads/{lead}/status', [LeadController::class, 'status'])->whereNumber('lead')->name('leads.status');
    Route::post('leads/{lead}/lost', [LeadController::class, 'lost'])->whereNumber('lead')->name('leads.lost');
    Route::post('leads/{lead}/transfer', [LeadController::class, 'transfer'])->whereNumber('lead')->name('leads.transfer');
    Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->whereNumber('lead')->name('leads.activities.store');
});

Route::get('schedule', [ScheduleController::class, 'index'])->middleware('ability:schedule:read')->name('schedule.index');

Route::middleware('ability:schedule:write')->group(function (): void {
    Route::post('schedule', [ScheduleController::class, 'store'])->name('schedule.store');
    Route::patch('schedule/{item}', [ScheduleController::class, 'update'])->whereNumber('item')->name('schedule.update');
    Route::post('schedule/{item}/complete', [ScheduleController::class, 'complete'])->whereNumber('item')->name('schedule.complete');
    Route::delete('schedule/{item}', [ScheduleController::class, 'destroy'])->whereNumber('item')->name('schedule.destroy');
});

Route::post('duplicates/check', DuplicateCheckController::class)->middleware('ability:leads:read,registry:read')->name('duplicates.check');
