<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateSharedToken
{
    /**
     * Authenticate a source app by the one shared token in TOURLAST_API_TOKEN.
     * There is no user behind it: possession of the secret is the credential,
     * so no account, role or API scope is checked.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('tourlast.api.token');
        $provided = (string) $request->bearerToken();

        if ($expected === '') {
            return response()->json([
                'message' => 'This Hub has no shared sync token yet. Run php artisan hub:generate-token.',
            ], 503);
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Missing or invalid shared token.'], 401);
        }

        return $next($request);
    }
}
