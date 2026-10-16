<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\SyncRun;
use App\Support\Travel\FlightSyncStatus;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Every source app the Hub reads from, whether each one answers right now,
 * and how fresh the data it brought in is. Probes run in parallel with a
 * short timeout so an unreachable app cannot slow the page down, and no
 * token ever reaches the browser.
 */
#[Title('Connected apps')]
class ConnectedApps extends Component
{
    /**
     * Latest probe result per app key: {state, detail}. Filled by check().
     *
     * @var array<string, array{state: string, detail: string}>
     */
    public array $statuses = [];

    public ?string $checkedAt = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageIntegration->value), 403);
    }

    public function check(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageIntegration->value), 403);

        $apps = $this->apps();
        $probeable = array_values(array_filter(
            $apps,
            fn (array $app): bool => $app['url'] !== null && filled($app['token']),
        ));

        $responses = [];

        if ($probeable !== []) {
            $responses = Http::pool(function (Pool $pool) use ($probeable) {
                foreach ($probeable as $app) {
                    $pool->as($app['key'])
                        ->acceptJson()
                        ->withToken((string) $app['token'])
                        ->timeout(4)
                        ->get((string) $app['url'], ['page' => 1]);
                }
            });
        }

        foreach ($apps as $app) {
            $this->statuses[$app['key']] = $this->stateFor($app, $responses[$app['key']] ?? null);
        }

        $this->checkedAt = now()->format('j M, H:i:s');
    }

    public function render(): View
    {
        $apps = array_map(function (array $app): array {
            $checked = $this->statuses[$app['key']] ?? null;

            return [
                ...$app,
                'state' => $checked['state'] ?? $app['state'],
                'detail' => $checked['detail'] ?? $app['detail'],
            ];
        }, $this->apps());

        return view('livewire.admin.connected-apps', [
            'apps' => $apps,
            'online' => count(array_filter($apps, fn (array $app): bool => $app['state'] === 'online')),
            'lastProviderSync' => SyncRun::query()->where('status', 'succeeded')->latest('started_at')->first(),
            'flightsSync' => new FlightSyncStatus,
        ]);
    }

    /**
     * Every source app the Hub is configured to talk to, from config alone.
     *
     * @return list<array{key: string, name: string, feed: string, mode: string, url: string|null, token: string|null, state: string, detail: string}>
     */
    private function apps(): array
    {
        return [...$this->providerApps(), $this->flightsApp()];
    }

    /**
     * One row per base in TOURLAST_API_URL, or a single row explaining why
     * there is nothing to call out in push or sandbox mode.
     *
     * @return list<array{key: string, name: string, feed: string, mode: string, url: string|null, token: string|null, state: string, detail: string}>
     */
    private function providerApps(): array
    {
        $mode = (string) config('tourlast.source');
        $config = config('tourlast.api');

        if ($mode !== 'api') {
            return [[
                'key' => 'providers',
                'name' => 'Providers feed',
                'feed' => 'Providers',
                'mode' => $mode,
                'url' => null,
                'token' => null,
                'state' => match ($mode) {
                    'push' => 'push',
                    'sandbox' => 'sandbox',
                    default => 'not_connected',
                },
                'detail' => match ($mode) {
                    'push' => 'Source apps push providers into this Hub; nothing is called out.',
                    'sandbox' => 'Sample data kept in this app.',
                    default => 'TOURLAST_SOURCE=database is refused: use api or push.',
                },
            ]];
        }

        $path = '/'.ltrim((string) ($config['path'] ?? ''), '/');
        $token = $config['token'];
        $bases = array_values(array_filter(array_map('trim', explode(',', (string) $config['base_url']))));

        if ($bases === []) {
            return [[
                'key' => 'providers',
                'name' => 'Providers feed',
                'feed' => 'Providers',
                'mode' => 'api',
                'url' => null,
                'token' => $token,
                'state' => 'not_connected',
                'detail' => 'TOURLAST_API_URL is not set.',
            ]];
        }

        $apps = [];

        foreach ($bases as $index => $base) {
            $apps[] = [
                'key' => 'provider-'.$index,
                'name' => $this->appName($base),
                'feed' => 'Providers',
                'mode' => 'api',
                'url' => rtrim($base, '/').$path,
                'token' => $token,
                'state' => blank($token) ? 'no_token' : 'checking',
                'detail' => blank($token) ? 'TOURLAST_API_TOKEN is not set. Run hub:generate-token.' : 'Waiting for the first check.',
            ];
        }

        return $apps;
    }

    /**
     * The Flights Super Admin bookings feed.
     *
     * @return array{key: string, name: string, feed: string, mode: string, url: string|null, token: string|null, state: string, detail: string}
     */
    private function flightsApp(): array
    {
        $config = config('travel.flights');
        $mode = (string) ($config['source'] ?? 'sandbox');
        $base = rtrim(trim((string) ($config['api_url'] ?? '')), '/');

        $app = [
            'key' => 'flights',
            'name' => $base !== '' ? $this->appName($base) : 'Flights bookings',
            'feed' => 'Flight bookings',
            'mode' => $mode,
            'url' => null,
            'token' => $config['api_token'] ?? null,
            'state' => 'checking',
            'detail' => 'Waiting for the first check.',
        ];

        if ($mode === 'push') {
            $app['state'] = 'push';
            $app['detail'] = 'Flights Super Admin posts bookings into this Hub; nothing is called out.';

            return $app;
        }

        if ($mode !== 'api') {
            $app['state'] = 'sandbox';
            $app['detail'] = 'Dummy bookings in this app until the Flights team connects.';

            return $app;
        }

        if ($base === '') {
            $app['state'] = 'not_connected';
            $app['detail'] = 'FLIGHTS_API_URL is not set.';

            return $app;
        }

        if (blank($app['token'])) {
            $app['state'] = 'no_token';
            $app['detail'] = 'FLIGHTS_API_TOKEN is not set.';

            return $app;
        }

        $app['url'] = $base.'/hub/bookings';

        return $app;
    }

    /**
     * The app's host as its display name (e.g. tourlast-stays.test).
     */
    private function appName(string $base): string
    {
        $host = parse_url(str_contains($base, '://') ? $base : '//'.$base, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $base;
    }

    /**
     * Turn one probe result into the state the row shows, keeping the
     * config-only answer when there was nothing to call.
     *
     * @param  array{state: string, detail: string}  $app
     * @return array{state: string, detail: string}
     */
    private function stateFor(array $app, mixed $result): array
    {
        if ($app['url'] === null || blank($app['token'])) {
            return ['state' => $app['state'], 'detail' => $app['detail']];
        }

        if ($result instanceof Throwable) {
            return ['state' => 'offline', 'detail' => 'Could not be reached — '.Str::limit($result->getMessage(), 140)];
        }

        if (! $result instanceof Response) {
            return ['state' => 'offline', 'detail' => 'No answer within 4 seconds.'];
        }

        $code = $result->status();

        return match (true) {
            $result->successful() => ['state' => 'online', 'detail' => "Answered HTTP {$code}"],
            in_array($code, [401, 403], true) => ['state' => 'rejected', 'detail' => "HTTP {$code} — the shared sync token was refused"],
            $code === 404 => ['state' => 'missing', 'detail' => 'HTTP 404 — the app is up but has no feed here'],
            default => ['state' => 'error', 'detail' => "HTTP {$code}"],
        };
    }
}
