<?php

use App\Http\Controllers\Travel\TravelReportExportController;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Travel\Reports as TravelReports;
use App\Livewire\Travel\Targets as TravelTargets;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/targets', TravelTargets::class)->name('travel.targets.index');
Route::livewire('/travel/reports', TravelReports::class)->name('travel.reports.index');
Route::get('/travel/reports/export', TravelReportExportController::class)->name('travel.reports.export');
Route::livewire('/admin/audit-log', AuditLog::class)->name('admin.audit-log');
