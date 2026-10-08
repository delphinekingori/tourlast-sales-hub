<?php

use App\Http\Controllers\Travel\ContractDocumentController;
use App\Livewire\Travel\Contracts\Index as ContractsIndex;
use App\Livewire\Travel\Contracts\Show as ContractsShow;
use App\Livewire\Travel\Incidents\Index as IncidentsIndex;
use App\Livewire\Travel\Providers\Form as ProvidersForm;
use App\Livewire\Travel\Providers\Index as ProvidersIndex;
use App\Livewire\Travel\Providers\Show as ProvidersShow;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/providers', ProvidersIndex::class)->name('travel.providers.index');
Route::livewire('/travel/providers/create', ProvidersForm::class)->name('travel.providers.create');
Route::livewire('/travel/providers/{provider}', ProvidersShow::class)->whereNumber('provider')->name('travel.providers.show');
Route::livewire('/travel/providers/{provider}/edit', ProvidersForm::class)->whereNumber('provider')->name('travel.providers.edit');

Route::livewire('/travel/contracts', ContractsIndex::class)->name('travel.contracts.index');
Route::livewire('/travel/contracts/{contract}', ContractsShow::class)->whereNumber('contract')->name('travel.contracts.show');
Route::get('/travel/contracts/documents/{document}', ContractDocumentController::class)->whereNumber('document')->name('travel.contracts.document');

Route::livewire('/travel/incidents', IncidentsIndex::class)->name('travel.incidents.index');
