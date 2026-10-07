<?php

namespace App\Integrations\Tourlast;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Reads referred providers from one or more read-only JSON endpoints, one
 * per source app (tourlast-stays, experiences-v1), using the shared path
 * and bearer token.
 *
 * Expected response: {"data": [ {provider}, ... ], "next_page": 2|null}
 * where each provider uses the Hub field names documented in
 * docs/TOURLAST_INTEGRATION.md.
 */
class ApiProviderSource implements ProviderSource, ReportsSourceFailures
{
    /** @var list<string> */
    private array $failures = [];

    public function __construct(private ProviderRecordMapper $mapper) {}

    public function failures(): array
    {
        return $this->failures;
    }

    public function name(): string
    {
        return 'api';
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        $config = config('tourlast.api');

        if (blank($config['token'])) {
            throw new RuntimeException('TOURLAST_API_TOKEN is not set.');
        }

        $baseUrls = array_values(array_filter(array_map('trim', explode(',', (string) $config['base_url']))));

        if ($baseUrls === []) {
            throw new RuntimeException('TOURLAST_API_URL is not set.');
        }

        $this->failures = [];

        foreach ($baseUrls as $baseUrl) {
            try {
                yield from $this->pull($baseUrl, $config, $since);
            } catch (Throwable $exception) {
                Log::error('tourlast.com feed failed', ['url' => $baseUrl, 'exception' => $exception]);

                $this->failures[] = $this->describe($baseUrl, $exception);
            }
        }
    }

    /**
     * What went wrong with one app's feed, without the response body or any server paths.
     */
    private function describe(string $baseUrl, Throwable $exception): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;

        return $exception instanceof RequestException
            ? "{$host} answered HTTP {$exception->response->status()}"
            : "{$host} could not be read";
    }

    /**
     * Walks every page of a single app's feed.
     *
     * @param  array{path: string, token: string, timeout: int}  $config
     */
    private function pull(string $baseUrl, array $config, ?CarbonImmutable $since): iterable
    {
        $page = 1;

        do {
            $response = Http::baseUrl($baseUrl)
                ->withToken($config['token'])
                ->acceptJson()
                ->timeout($config['timeout'])
                ->retry(3, 1000, throw: false)
                ->get($config['path'], array_filter([
                    'updated_since' => $since?->toIso8601String(),
                    'page' => $page,
                ]));

            $response->throw();

            foreach ($response->json('data', []) as $row) {
                try {
                    $record = $this->mapper->fromArray($row);
                } catch (InvalidArgumentException $exception) {
                    $this->failures[] = (parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl).': skipped a record ('.$exception->getMessage().')';

                    continue;
                }

                yield $record;
            }

            $page = $response->json('next_page');
        } while ($page);
    }
}
