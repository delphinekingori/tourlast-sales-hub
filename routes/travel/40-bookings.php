<?php

use App\Livewire\Travel\Bookings\Create as TravelBookingCreate;
use App\Livewire\Travel\Bookings\Index as TravelBookingsIndex;
use App\Livewire\Travel\Bookings\Show as TravelBookingShow;
use App\Livewire\Travel\Cancellations\Index as TravelCancellationsIndex;
use App\Livewire\Travel\Clients\Index as TravelClientsIndex;
use App\Livewire\Travel\Departures\Index as TravelDeparturesIndex;
use App\Livewire\Travel\Resources\Index as TravelResourcesIndex;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/inventory', TravelDeparturesIndex::class)->name('travel.departures.index');
Route::livewire('/travel/bookings', TravelBookingsIndex::class)->name('travel.bookings.index');
Route::livewire('/travel/bookings/create', TravelBookingCreate::class)->name('travel.bookings.create');
Route::livewire('/travel/bookings/{booking}', TravelBookingShow::class)->whereNumber('booking')->name('travel.bookings.show');
Route::livewire('/travel/clients', TravelClientsIndex::class)->name('travel.clients.index');
Route::livewire('/travel/drivers-guides', TravelResourcesIndex::class)->name('travel.resources.index');
Route::livewire('/travel/cancellations', TravelCancellationsIndex::class)->name('travel.cancellations.index');
