<?php

namespace App\Integrations\Flights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * FLIGHTS_SOURCE=api: pulls bookings from Tourlast Flights Super Admin.
 *
 *     GET {FLIGHTS_API_URL}/bookings?updated_since=2026-10-07T08:00:00+00:00&page=1
 *     Authorization: Bearer {FLIGHTS_API_TOKEN}
 *
 * Response: {"data": [ {booking}, ... ], "meta": {"current_page": 1, "last_page": 4}}
 * ("next_page": 2|null at the top level or in meta is accepted too). Each
 * booking uses the shape documented on FlightRecord. A non-2xx answer stops
 * the run with an exception; a single unreadable booking is skipped and
 * reported through skipped().
 */
class ApiFlightSource implements FlightSource
{
    /** @var list<string> */
    private array $skipped = [];

    public function name(): string
    {
        return 'api';
    }

    /**
     * Bookings skipped in the last run because they could not be read.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        $config = config('travel.flights');

        if (blank($config['api_url'])) {
            throw new RuntimeException('FLIGHTS_API_URL is not set.');
        }

        if (blank($config['api_token'])) {
            throw new RuntimeException('FLIGHTS_API_TOKEN is not set.');
        }

        $this->skipped = [];
        $page = 1;

        do {
            $response = Http::baseUrl(rtrim((string) $config['api_url'], '/'))
                ->withToken((string) $config['api_token'])
                ->acceptJson()
                ->timeout((int) $config['timeout'])
                ->get('bookings', array_filter([
                    'updated_since' => $since?->toIso8601String(),
                    'page' => $page,
                ]));

            if ($response->failed()) {
                throw new RuntimeException('Flights Super Admin answered HTTP '.$response->status().' on page '.$page.'.');
            }

            foreach ((array) $response->json('data', []) as $row) {
                try {
                    yield FlightRecord::fromArray(is_array($row) ? $row : []);
                } catch (InvalidArgumentException $exception) {
                    Log::warning('Skipped an unreadable flight booking', ['error' => $exception->getMessage()]);
                    $this->skipped[] = $exception->getMessage();
                }
            }

            $page = $this->nextPage($response->json(), $page);
        } while ($page !== null);
    }

    /**
     * @param  mixed  $body
     */
    private function nextPage($body, int $current): ?int
    {
        $body = is_array($body) ? $body : [];
        $next = $body['next_page'] ?? $body['meta']['next_page'] ?? null;

        if ($next !== null) {
            return (int) $next > $current ? (int) $next : null;
        }

        $last = $body['meta']['last_page'] ?? $body['last_page'] ?? null;

        return $last !== null && $current < (int) $last ? $current + 1 : null;
    }
}
