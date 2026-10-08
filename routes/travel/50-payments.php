<?php

use App\Livewire\Travel\Payments\Index as TravelPayments;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/payments', TravelPayments::class)->name('travel.payments.index');
