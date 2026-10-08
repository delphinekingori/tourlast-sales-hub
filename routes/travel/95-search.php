<?php

use App\Livewire\Travel\Search as TravelSearch;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/search', TravelSearch::class)->name('travel.search');
