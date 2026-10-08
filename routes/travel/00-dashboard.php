<?php

use App\Livewire\Travel\Dashboard as TravelDashboard;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel', TravelDashboard::class)->name('travel.dashboard');
