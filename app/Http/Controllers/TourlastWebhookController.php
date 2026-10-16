<?php

namespace App\Http\Controllers;

use App\Actions\ApplyProviderRecord;
use App\Actions\Travel\Flights\ApplyFlightRecord;
use App\Integrations\Flights\FlightRecord;
use App\Integrations\Tourlast\ProviderRecordMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class TourlastWebhookController extends Controller
{
    /**
     * Which payload shape each source sends. Everything but flights is a
     * provider record; flights sends one flight booking.
     *
     * @var array<string, string>
     */
    private const Shapes = [
        'tourlast' => 'provider',
        'stays' => 'provider',
        'experiences' => 'provider',
        'flights' => 'booking',
    ];

    /**
     * Receive a signed update from a source app. One contract for every app:
     *
     * POST {APP_URL}/webhooks/{source}   (source: tourlast, stays, experiences, flights)
     * Header: X-Tourlast-Signature: sha256=<hex HMAC-SHA256 of the raw body>
     * Body (provider): {"event_id": "...", "provider": { ...Hub field names... }}
     * Body (booking):  {"event_id": "...", "booking":  { ...FlightRecord shape... }}
     *
     * The same secret (TOURLAST_WEBHOOK_SECRET) signs for every app; {source}
     * identifies who it is, namespaces the duplicate-event cache and picks
     * the handler, so two apps can never collide.
     */
    public function __invoke(Request $request, string $source, ProviderRecordMapper $mapper, ApplyProviderRecord $applyProviderRecord, ApplyFlightRecord $applyFlightRecord): JsonResponse
    {
        $shape = self::Shapes[$source] ?? null;

        if ($shape === null) {
            return response()->json(['message' => 'Unknown webhook source.'], 404);
        }

        $secret = config('tourlast.webhook_secret');

        if (blank($secret)) {
            return response()->json(['message' => 'Webhooks are not enabled.'], 404);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Tourlast-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventId = (string) $request->input('event_id') ?: sha1($request->getContent());

        if (! Cache::add('webhook:'.$source.':'.$eventId, true, now()->addDays(7))) {
            return response()->json(['result' => 'duplicate']);
        }

        try {
            if ($shape === 'booking') {
                $record = FlightRecord::fromArray((array) $request->input('booking', []));
            } else {
                $record = $mapper->fromArray((array) $request->input('provider', []));
            }
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $result = $shape === 'booking'
            ? $applyFlightRecord->handle($record, 'webhook')
            : $applyProviderRecord->handle($record, 'webhook');

        return response()->json(['result' => $result]);
    }
}
