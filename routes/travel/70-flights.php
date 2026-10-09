<?php

use App\Livewire\Travel\Flights\Index as FlightsIndex;
use App\Livewire\Travel\Flights\Show as FlightsShow;
use Illuminate\Support\Facades\Route;

/*
| Flights: read-only copy of Tourlast Flights Super Admin.
*/
Route::livewire('/travel/flights', FlightsIndex::class)->name('travel.flights.index');
Route::livewire('/travel/flights/{booking}', FlightsShow::class)->whereNumber('booking')->name('travel.flights.show');
