<?php

use App\Enums\ReferralTarget;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\EngagementRegistryExportController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\PartnerRegisterExportController;
use App\Http\Controllers\ReferralRedirectController;
use App\Http\Controllers\TourlastWebhookController;
use App\Http\Controllers\Travel\BookingTicketController;
use App\Livewire\Accounts\Index as AccountsIndex;
use App\Livewire\Accounts\Show as AccountsShow;
use App\Livewire\Activities\Index as ActivitiesIndex;
use App\Livewire\Admin\ApiTokens;
use App\Livewire\Admin\Incentives as AdminIncentives;
use App\Livewire\Admin\Integration;
use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Calendar\Index as CalendarIndex;
use App\Livewire\Claims\Approvals as ClaimApprovals;
use App\Livewire\Claims\Index as ClaimsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Incentives\MyEarnings;
use App\Livewire\Insights\MyLosses;
use App\Livewire\Insights\Objections as ObjectionInsights;
use App\Livewire\Leads\Index as LeadsIndex;
use App\Livewire\Leads\Show as LeadsShow;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Onboardings\Mine as MyOnboardings;
use App\Livewire\Onboardings\Unattributed;
use App\Livewire\Partners\Index as PartnersIndex;
use App\Livewire\Payments\Details as PaymentDetails;
use App\Livewire\Payouts\Index as PayoutsIndex;
use App\Livewire\People\Index as PeopleIndex;
use App\Livewire\People\Show as PeopleShow;
use App\Livewire\Profile;
use App\Livewire\Referrals\Center as ReferralCenter;
use App\Livewire\Registry\Form as RegistryForm;
use App\Livewire\Registry\Index as RegistryIndex;
use App\Livewire\Registry\Show as RegistryShow;
use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Performance;
use App\Livewire\Team\Targets;
use Illuminate\Support\Facades\Route;

/*
| Public tracked referral links: sales-hub.tourlast.com/r/TL-JOHN-2847 (Stays)
| and sales-hub.tourlast.com/r/TL-JOHN-2847/experiences (Experiences).
*/
Route::get('/r/{code}/{target?}', ReferralRedirectController::class)
    ->where('code', '[A-Za-z0-9\-]+')
    ->whereIn('target', array_column(ReferralTarget::cases(), 'value'))
    ->middleware('throttle:120,1')
    ->name('referral.redirect');

/*
| Signed provider updates from tourlast.com (optional; see docs/TOURLAST_INTEGRATION.md).
*/
Route::post('/webhooks/tourlast', TourlastWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.tourlast');

/*
| Public ticket verification (the QR code on a booking ticket). The token is
| random and unguessable; ids and references never work here.
*/
Route::get('/booking/verify/{token}', [BookingTicketController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware('throttle:30,1')
    ->name('bookings.verify');

Route::livewire('/invitations/{token}', AcceptInvitation::class)->name('invitations.accept');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::livewire('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('/onboardings', MyOnboardings::class)->name('onboardings.mine');
    Route::livewire('/onboardings/unattributed', Unattributed::class)->name('onboardings.unattributed');

    Route::livewire('/leads', LeadsIndex::class)->name('leads.index');
    Route::livewire('/leads/{lead}', LeadsShow::class)->name('leads.show');
    Route::livewire('/activities', ActivitiesIndex::class)->name('activities.index');
    Route::livewire('/calendar', CalendarIndex::class)->name('calendar.index');

    Route::livewire('/team', TeamIndex::class)->name('team.index');
    Route::livewire('/team/performance', Performance::class)->name('team.performance');
    Route::livewire('/team/performance/{user}', Dashboard::class)->name('team.member');
    Route::livewire('/team/targets', Targets::class)->name('team.targets');

    Route::livewire('/partners', PartnersIndex::class)->name('partners.index');
    Route::get('/partners/export', [PartnerRegisterExportController::class, 'excel'])->name('partners.export');
    Route::get('/partners/report', [PartnerRegisterExportController::class, 'pdf'])->name('partners.report');

    Route::livewire('/registry', RegistryIndex::class)->name('registry.index');
    Route::livewire('/registry/create', RegistryForm::class)->name('registry.create');
    Route::get('/registry/export', [EngagementRegistryExportController::class, 'excel'])->name('registry.export');
    Route::get('/registry/report', [EngagementRegistryExportController::class, 'pdf'])->name('registry.report');
    Route::livewire('/registry/{engagement}', RegistryShow::class)->whereNumber('engagement')->name('registry.show');
    Route::livewire('/registry/{engagement}/edit', RegistryForm::class)->whereNumber('engagement')->name('registry.edit');
    Route::livewire('/referrals', ReferralCenter::class)->name('referrals.index');
    Route::livewire('/insights/lost', ObjectionInsights::class)->name('insights.objections');
    Route::livewire('/my-losses', MyLosses::class)->name('insights.mine');

    Route::livewire('/earnings', MyEarnings::class)->name('earnings.mine');
    Route::livewire('/earnings/{user}', MyEarnings::class)->name('earnings.member');
    Route::livewire('/accounts', AccountsIndex::class)->name('accounts.index');
    Route::livewire('/accounts/{account}', AccountsShow::class)->name('accounts.show');
    Route::livewire('/claims', ClaimsIndex::class)->name('claims.index');
    Route::livewire('/claims/approvals', ClaimApprovals::class)->name('claims.approvals');
    Route::livewire('/payouts', PayoutsIndex::class)->name('payouts.index');

    Route::get('/downloads/claims/{attachment}', [DownloadController::class, 'claimAttachment'])->name('downloads.claim-attachment');
    Route::get('/downloads/evidence/{item}', [DownloadController::class, 'evidence'])->name('downloads.evidence');
    Route::get('/downloads/statements/{statement}', [DownloadController::class, 'statement'])->name('downloads.statement');
    Route::get('/downloads/payouts', [DownloadController::class, 'payouts'])->name('downloads.payouts');

    Route::livewire('/admin/integration', Integration::class)->name('admin.integration');
    Route::livewire('/admin/incentives', AdminIncentives::class)->name('admin.incentives');
    Route::livewire('/admin/api-tokens', ApiTokens::class)->name('admin.api-tokens');

    Route::livewire('/profile', Profile::class)->name('profile');
    Route::livewire('/people', PeopleIndex::class)->name('people.index');
    Route::livewire('/people/{user}', PeopleShow::class)->name('people.show');
    Route::livewire('/notifications', NotificationsIndex::class)->name('notifications.index');
    Route::livewire('/payment-details', PaymentDetails::class)->name('payment-details.index');
    Route::post('/logout', LogoutController::class)->name('logout');

    /*
    | Travel Sales workspace: one file per module in routes/travel/.
    */
    $travelRoutes = glob(__DIR__.'/travel/*.php') ?: [];
    sort($travelRoutes);

    foreach ($travelRoutes as $file) {
        require $file;
    }
});
