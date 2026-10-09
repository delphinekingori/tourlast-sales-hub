<?php

use App\Livewire\Travel\Media\Index as MediaGallery;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/media', MediaGallery::class)->name('travel.media.index');
