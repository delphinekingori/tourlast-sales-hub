<?php

use App\Http\Controllers\Travel\InfluencerExportController;
use App\Livewire\Travel\Influencers\Index as InfluencersIndex;
use App\Livewire\Travel\Influencers\Show as InfluencersShow;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/influencers', InfluencersIndex::class)->name('travel.influencers.index');
Route::get('/travel/influencers/export', InfluencerExportController::class)->name('travel.influencers.export');
Route::livewire('/travel/influencers/{influencer}', InfluencersShow::class)->whereNumber('influencer')->name('travel.influencers.show');
