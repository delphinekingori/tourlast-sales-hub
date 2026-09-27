<?php

use App\Http\Controllers\Api\V1\EarningsController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PartnerAccountController;
use App\Http\Controllers\Api\V1\StatementController;
use Illuminate\Support\Facades\Route;

/*
| tourlast.com onboardings, Partner Accounts, earnings and payout statements.
*/

Route::middleware('ability:onboardings:read')->group(function (): void {
    Route::get('onboardings', [OnboardingController::class, 'index'])->name('onboardings.index');
    Route::get('onboardings/unattributed', [OnboardingController::class, 'unattributed'])->name('onboardings.unattributed');
    Route::get('onboardings/{onboarding}', [OnboardingController::class, 'show'])->whereNumber('onboarding')->name('onboardings.show');
});

Route::post('onboardings/{onboarding}/assign', [OnboardingController::class, 'assign'])
    ->whereNumber('onboarding')->middleware('ability:onboardings:write')->name('onboardings.assign');

Route::middleware('ability:incentives:read')->group(function (): void {
    Route::get('partner-accounts', [PartnerAccountController::class, 'index'])->name('partner-accounts.index');
    Route::get('partner-accounts/{account}', [PartnerAccountController::class, 'show'])->whereNumber('account')->name('partner-accounts.show');
    Route::get('earnings/{user}', [EarningsController::class, 'show'])->whereNumber('user')->name('earnings.show');
    Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
    Route::get('statements/{statement}', [StatementController::class, 'show'])->whereNumber('statement')->name('statements.show');
    Route::get('statements/{statement}/pdf', [StatementController::class, 'pdf'])->whereNumber('statement')->name('statements.pdf');
});

Route::middleware('ability:incentives:write')->group(function (): void {
    Route::post('partner-accounts/{account}/verify', [PartnerAccountController::class, 'verify'])->whereNumber('account')->name('partner-accounts.verify');
    Route::post('partner-accounts/{account}/fail-review', [PartnerAccountController::class, 'failReview'])->whereNumber('account')->name('partner-accounts.fail-review');
    Route::post('statements/{statement}/compliance', [StatementController::class, 'compliance'])->whereNumber('statement')->name('statements.compliance');
    Route::post('statements/{statement}/approve', [StatementController::class, 'approve'])->whereNumber('statement')->name('statements.approve');
    Route::post('statements/{statement}/pay', [StatementController::class, 'pay'])->whereNumber('statement')->name('statements.pay');
});
