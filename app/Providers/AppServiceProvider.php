<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Integrations\Tourlast\ApiProviderSource;
use App\Integrations\Tourlast\ProviderSource;
use App\Integrations\Tourlast\PushOnlyProviderSource;
use App\Integrations\Tourlast\SandboxProviderSource;
use App\Models\ExpenseClaim;
use App\Models\PartnerAccount;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\SampleData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProviderSource::class, fn ($app): ProviderSource => match (config('tourlast.source')) {
            'sandbox' => tap($app->make(SandboxProviderSource::class), fn () => SampleData::ensureAllowed('TOURLAST_SOURCE=sandbox')),
            'api' => $app->make(ApiProviderSource::class),
            'push' => $app->make(PushOnlyProviderSource::class),
            default => throw new InvalidArgumentException('TOURLAST_SOURCE must be sandbox, api or push. Reading another app\'s database is not supported: the Hub connects to source apps over the API only.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API: 120 requests a minute per token owner (or IP), 10 sign-in attempts a minute.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Super Admin can do everything, except that actions on a person's account
        // go through UserPolicy so nobody (Super Admin included) can act on themselves.
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $user->hasRole(Role::SuperAdmin->value) && ! (($arguments[0] ?? null) instanceof User) ? true : null);

        Gate::define('view-earnings', fn (User $user, User $subject): bool => $user->is($subject) || $user->can(Permission::ViewTeamEarnings->value));

        Gate::define('view-account', fn (User $user, PartnerAccount $account): bool => $account->user_id === $user->id
            || $user->can(Permission::VerifyAccounts->value)
            || $user->can(Permission::ViewTeamPerformance->value)
            || $user->can(Permission::ViewTeamEarnings->value));

        Gate::define('view-claim', fn (User $user, ExpenseClaim $claim): bool => $claim->user_id === $user->id
            || $user->can(Permission::ApproveClaimsManager->value)
            || $user->can(Permission::ApproveClaimsHr->value)
            || $user->can(Permission::ApproveClaimsFinance->value)
            || $user->can(Permission::ViewTeamEarnings->value));
    }
}
