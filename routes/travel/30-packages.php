<?php

use App\Livewire\Travel\Approvals\Index as PackageApprovals;
use App\Livewire\Travel\Packages\Form as PackageForm;
use App\Livewire\Travel\Packages\Index as PackagesIndex;
use App\Livewire\Travel\Packages\Show as PackageShow;
use Illuminate\Support\Facades\Route;

Route::livewire('/travel/packages', PackagesIndex::class)->name('travel.packages.index');
Route::livewire('/travel/packages/create', PackageForm::class)->name('travel.packages.create');
Route::livewire('/travel/packages/{package}', PackageShow::class)->whereNumber('package')->name('travel.packages.show');
Route::livewire('/travel/packages/{package}/edit', PackageForm::class)->whereNumber('package')->name('travel.packages.edit');
Route::livewire('/travel/approvals', PackageApprovals::class)->name('travel.approvals.index');
