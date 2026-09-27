<?php

use App\Http\Controllers\Api\V1\RegistryContactController;
use App\Http\Controllers\Api\V1\RegistryController;
use Illuminate\Support\Facades\Route;

/*
| Property Engagement Registry. Reading needs registry:read (every role);
| changes need registry:write and a manager account (PropertyEngagementPolicy).
*/

Route::middleware('ability:registry:read')->group(function (): void {
    Route::get('registry', [RegistryController::class, 'index'])->name('registry.index');
    Route::get('registry/{engagement}', [RegistryController::class, 'show'])->whereNumber('engagement')->name('registry.show');
});

Route::middleware('ability:registry:write')->group(function (): void {
    Route::post('registry', [RegistryController::class, 'store'])->name('registry.store');
    Route::patch('registry/{engagement}', [RegistryController::class, 'update'])->whereNumber('engagement')->name('registry.update');
    Route::post('registry/{engagement}/engagements', [RegistryController::class, 'logEngagement'])->whereNumber('engagement')->name('registry.engagements.store');
    Route::post('registry/{engagement}/assign', [RegistryController::class, 'assign'])->whereNumber('engagement')->name('registry.assign');
    Route::post('registry/{engagement}/links', [RegistryController::class, 'link'])->whereNumber('engagement')->name('registry.links.store');
    Route::post('registry/{engagement}/archive', [RegistryController::class, 'archive'])->whereNumber('engagement')->name('registry.archive');
    Route::post('registry/{engagement}/restore', [RegistryController::class, 'restore'])->whereNumber('engagement')->name('registry.restore');
    Route::post('registry/{engagement}/contacts', [RegistryContactController::class, 'store'])->whereNumber('engagement')->name('registry.contacts.store');
    Route::delete('registry/{engagement}/contacts/{contact}', [RegistryContactController::class, 'destroy'])->whereNumber(['engagement', 'contact'])->name('registry.contacts.destroy');
    Route::post('registry/{engagement}/contacts/{contact}/primary', [RegistryContactController::class, 'makePrimary'])->whereNumber(['engagement', 'contact'])->name('registry.contacts.primary');
});
