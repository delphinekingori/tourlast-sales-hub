<?php

use App\Http\Controllers\Api\V1\AccountStatusController;
use App\Http\Controllers\Api\V1\AdminTokenController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
| People, invitations, account status, team performance and targets, admin tokens.
*/

Route::middleware('ability:team:read')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::get('team/performance', [TeamController::class, 'performance'])->name('team.performance');
    Route::get('team/targets', [TeamController::class, 'targets'])->name('team.targets');
});

Route::middleware('ability:team:write')->group(function (): void {
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::post('users/{user}/suspend', [AccountStatusController::class, 'suspend'])->whereNumber('user')->name('users.suspend');
    Route::post('users/{user}/terminate', [AccountStatusController::class, 'terminate'])->whereNumber('user')->name('users.terminate');
    Route::post('users/{user}/reinstate', [AccountStatusController::class, 'reinstate'])->whereNumber('user')->name('users.reinstate');
    Route::delete('users/{user}', [AccountStatusController::class, 'destroy'])->whereNumber('user')->name('users.destroy');

    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->whereNumber('invitation')->name('invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('invitations.destroy');

    Route::get('admin/tokens', [AdminTokenController::class, 'index'])->name('admin.tokens.index');
    Route::post('admin/tokens', [AdminTokenController::class, 'store'])->name('admin.tokens.store');
    Route::delete('admin/tokens/{token}', [AdminTokenController::class, 'destroy'])->whereNumber('token')->name('admin.tokens.destroy');
});
