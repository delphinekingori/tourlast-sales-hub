<?php

namespace App\Console\Commands;

use App\Enums\ApiScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionMethod;

#[Signature('hub:api-spec {--path=docs/api/openapi.json : Where to write the spec}')]
#[Description('Generate the OpenAPI 3.1 description of the Sales Hub API from its routes')]
class GenerateApiSpec extends Command
{
    /**
     * Tag per route-name prefix, in documentation order.
     */
    private const Tags = [
        'auth' => 'Authentication', 'me' => 'Your account', 'meta' => 'Option lists', 'notifications' => 'Notifications',
        'announcements' => 'Notifications', 'leads' => 'Leads', 'schedule' => 'Schedule', 'duplicates' => 'Duplicate check',
        'registry' => 'Property Engagement Registry', 'onboardings' => 'Onboardings', 'partner-accounts' => 'Partner accounts',
        'earnings' => 'Earnings and statements', 'statements' => 'Earnings and statements', 'claims' => 'Claims',
        'users' => 'Team and accounts', 'invitations' => 'Team and accounts', 'team' => 'Team and accounts',
        'insights' => 'Insights and reports', 'partners' => 'Insights and reports', 'reports' => 'Insights and reports',
        'integrations' => 'tourlast.com integration', 'admin' => 'Admin: API tokens',
    ];

    public function handle(): int
    {
        $paths = [];

        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.Str::after($route->uri(), 'api/v1/');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $path);
            }
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Tourlast Sales Hub API',
                'version' => 'v1',
                'description' => 'REST API for the Tourlast Sales Hub. A request needs the token scope listed on the operation AND the token owner\'s permission in the Hub. Full guide: docs/API.md.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'security' => [['bearerAuth' => []]],
            'tags' => collect(self::Tags)->unique()->values()->map(fn (string $tag) => ['name' => $tag])->all(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Sanctum token from POST /auth/tokens or Admin → API tokens. Scopes: '.implode(', ', ApiScope::values()).'.',
                    ],
                ],
                'responses' => [
                    'Unauthenticated' => $this->errorResponse('Missing, invalid or expired token'),
                    'Forbidden' => $this->errorResponse('Token lacks the scope, or the person may not do this'),
                    'NotFound' => $this->errorResponse('Record does not exist'),
                    'ValidationError' => $this->errorResponse('Validation failed (see errors)'),
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            'required_scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];

        $target = base_path((string) $this->option('path'));
        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $count = collect($paths)->flatten(1)->count();
        $this->components->info("Wrote {$count} operations to {$this->option('path')}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $path): array
    {
        $scopes = $this->scopes($route);
        [$summary, $description] = $this->docs($route);
        $prefix = Str::before((string) Str::after((string) $route->getName(), 'api.v1.'), '.');
        $public = ! in_array('auth:sanctum', $route->gatherMiddleware(), true);

        $operation = [
            'tags' => [self::Tags[$prefix] ?? Str::headline($prefix)],
            'operationId' => Str::camel(str_replace(['api.v1.', '.', '-'], ['', ' ', ' '], (string) $route->getName())),
            'summary' => $summary,
            'description' => trim($description."\n\n".($scopes ? '**Scope:** `'.implode('` or `', $scopes).'`' : ($public ? '**Public** (no token).' : '**Scope:** any token.'))),
            'parameters' => collect($route->parameterNames())->map(fn (string $name) => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => in_array($name, ['key'], true) ? 'string' : 'integer'],
            ])->values()->all(),
            'responses' => [
                '200' => ['description' => 'Success (201 for creates). Body: {"data": …}; lists are paginated with links and meta.'],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];

        if ($scopes) {
            $operation['x-scopes'] = $scopes;
        }

        if ($public) {
            $operation['security'] = [];
        }

        return $operation;
    }

    /**
     * Scopes from the route's ability:/abilities: middleware.
     *
     * @return list<string>
     */
    private function scopes(Route $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && preg_match('/^abilit(y|ies):/', $middleware))
            ->flatMap(fn (string $middleware) => explode(',', Str::after($middleware, ':')))
            ->values()
            ->all();
    }

    /**
     * Summary and description from the controller method's docblock.
     *
     * @return array{0: string, 1: string}
     */
    private function docs(Route $route): array
    {
        $action = $route->getActionName();
        $fallback = Str::headline(Str::after((string) $route->getName(), 'api.v1.'));

        if (! str_contains($action, '@')) {
            return [$fallback, ''];
        }

        [$class, $method] = explode('@', $action);

        if (! method_exists($class, $method)) {
            return [$fallback, ''];
        }

        $comment = (string) (new ReflectionMethod($class, $method))->getDocComment();
        $lines = collect(preg_split('/\R/', $comment) ?: [])
            ->map(fn (string $line) => trim(ltrim(trim($line), '/*')))
            ->reject(fn (string $line) => $line === '' || str_starts_with($line, '@'))
            ->values();

        if ($lines->isEmpty()) {
            return [$fallback, ''];
        }

        // "GET /leads — Your leads…" → summary after the dash.
        $first = (string) $lines->first();
        $summary = str_contains($first, ' — ') ? Str::after($first, ' — ') : $first;

        return [Str::limit(rtrim($summary, '.'), 120, ''), $lines->slice(1)->implode(' ')];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }
}
