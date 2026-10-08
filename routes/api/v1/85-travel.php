<?php

use App\Http\Controllers\Api\V1\TravelBookingController;
use App\Http\Controllers\Api\V1\TravelController;
use App\Http\Controllers\Api\V1\TravelFlightController;
use App\Http\Controllers\Api\V1\TravelPackageController;
use App\Http\Controllers\Api\V1\TravelPaymentController;
use App\Http\Controllers\Api\V1\TravelProviderController;
use Illuminate\Support\Facades\Route;

/*
| Travel Sales. Reading needs travel:read, changes need travel:write, and the
| token owner must have Travel Sales access in the Hub (Sales Managers and HR
| never do; Accounts only for payments, bookings and reports). Flights are
| read-only: there are no write routes for them.
*/

Route::prefix('travel')->name('travel.')->group(function (): void {
    Route::middleware('ability:travel:read')->group(function (): void {
        Route::get('dashboard', [TravelController::class, 'dashboard'])->name('dashboard');
        Route::get('reports', [TravelController::class, 'reports'])->name('reports');
        Route::get('targets', [TravelController::class, 'targets'])->name('targets');

        Route::get('flights', [TravelFlightController::class, 'index'])->name('flights.index');
        Route::get('flights/{booking}', [TravelFlightController::class, 'show'])->whereNumber('booking')->name('flights.show');

        Route::get('providers', [TravelProviderController::class, 'index'])->name('providers.index');
        Route::get('providers/{provider}', [TravelProviderController::class, 'show'])->whereNumber('provider')->name('providers.show');
        Route::get('contracts', [TravelProviderController::class, 'contracts'])->name('contracts.index');
        Route::get('contracts/{contract}', [TravelProviderController::class, 'contract'])->whereNumber('contract')->name('contracts.show');

        Route::get('packages', [TravelPackageController::class, 'index'])->name('packages.index');
        Route::get('packages/{package}', [TravelPackageController::class, 'show'])->whereNumber('package')->name('packages.show');

        Route::get('departures', [TravelBookingController::class, 'departures'])->name('departures.index');
        Route::get('bookings', [TravelBookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [TravelBookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');

        Route::get('payments', [TravelPaymentController::class, 'index'])->name('payments.index');
        Route::get('influencer-codes', [TravelPaymentController::class, 'influencerCodes'])->name('influencer-codes.index');
    });

    Route::middleware('ability:travel:write')->group(function (): void {
        Route::post('packages', [TravelPackageController::class, 'store'])->name('packages.store');
        Route::patch('packages/{package}', [TravelPackageController::class, 'update'])->whereNumber('package')->name('packages.update');
        Route::post('packages/{package}/submit', [TravelPackageController::class, 'submit'])->whereNumber('package')->name('packages.submit');
        Route::post('packages/{package}/review', [TravelPackageController::class, 'review'])->whereNumber('package')->name('packages.review');
        Route::post('packages/{package}/publish', [TravelPackageController::class, 'publish'])->whereNumber('package')->name('packages.publish');
        Route::post('packages/{package}/unpublish', [TravelPackageController::class, 'unpublish'])->whereNumber('package')->name('packages.unpublish');

        Route::post('bookings', [TravelBookingController::class, 'store'])->name('bookings.store');
        Route::post('bookings/{booking}/confirm', [TravelBookingController::class, 'confirm'])->whereNumber('booking')->name('bookings.confirm');
        Route::post('bookings/{booking}/mpesa', [TravelBookingController::class, 'requestMpesa'])->whereNumber('booking')->name('bookings.mpesa');

        Route::post('schedule', [TravelController::class, 'schedule'])->name('schedule.store');
    });
});
