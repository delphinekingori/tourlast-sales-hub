<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\TrackPresence;
use App\Http\Middleware\ValidateSharedToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'shared-token' => ValidateSharedToken::class,
        ]);
        $middleware->api(append: [TrackPresence::class]);
        $middleware->web(append: [TrackPresence::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A token without the route's scope gets a clear 403 naming the scope it needs.
        $exceptions->map(MissingAbilityException::class, fn (MissingAbilityException $exception) => new HttpResponseException(response()->json([
            'message' => 'This token is missing the required scope: '.implode(', ', $exception->abilities()).'.',
            'required_scopes' => array_values($exception->abilities()),
        ], 403)));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
