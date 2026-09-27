<?php

namespace Tests\Concerns;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Models\User;

/**
 * Call the API as a user holding a token with the given scopes.
 *
 * Laravel remembers the authenticated user between requests in one test, so
 * each switch forgets the resolved guards before sending the new token.
 */
trait MakesApiRequests
{
    /**
     * @param  list<string>|null  $scopes  null = every scope
     */
    protected function api(User $user, ?array $scopes = null): static
    {
        $token = app(IssueApiToken::class)->handle($user, 'Test', $scopes ?? ApiScope::values())->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
