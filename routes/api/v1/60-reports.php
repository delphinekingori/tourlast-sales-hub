<?php

use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Lost & objections insights, the Partner Register, Excel and PDF exports.
*/

Route::middleware('ability:reports:read')->group(function (): void {
    Route::get('insights/objections', [ReportController::class, 'objections'])->name('insights.objections');
    Route::get('me/losses', [ReportController::class, 'myLosses'])->name('insights.mine');
    Route::get('partners', [ReportController::class, 'partners'])->name('partners.index');

    Route::get('reports/partner-register.xlsx', [ReportController::class, 'partnerRegisterExcel'])->name('reports.partner-register.xlsx');
    Route::get('reports/partner-register.pdf', [ReportController::class, 'partnerRegisterPdf'])->name('reports.partner-register.pdf');
    Route::get('reports/registry.xlsx', [ReportController::class, 'registryExcel'])->name('reports.registry.xlsx');
    Route::get('reports/registry.pdf', [ReportController::class, 'registryPdf'])->name('reports.registry.pdf');
});
