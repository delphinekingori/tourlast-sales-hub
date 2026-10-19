<?php

use App\Http\Controllers\Travel\TripReviewExportController;
use App\Livewire\Travel\Reviews\Index as TravelReviewsIndex;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/reviews', TravelReviewsIndex::class)->name('travel.reviews.index');
Route::get('/travel/reviews/export', TripReviewExportController::class)->name('travel.reviews.export');
