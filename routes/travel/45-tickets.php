<?php

use App\Http\Controllers\Travel\BookingTicketController;
use App\Livewire\Travel\Bookings\Ticket as BookingTicketPage;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/bookings/{booking}/ticket', BookingTicketPage::class)->whereNumber('booking')->name('travel.bookings.ticket');
Route::get('/travel/bookings/{booking}/ticket.pdf', [BookingTicketController::class, 'pdf'])->whereNumber('booking')->name('travel.bookings.ticket.pdf');
