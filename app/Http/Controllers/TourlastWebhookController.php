<?php

namespace App\Http\Controllers;

use App\Actions\ApplyProviderRecord;
use App\Integrations\Tourlast\ProviderRecordMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class TourlastWebhookController extends Controller
{
    /**
     * Receive a signed provider update from tourlast.com.
     *
     * Header: X-Tourlast-Signature: sha256=<hex HMAC-SHA256 of the raw body>
     * Body:   {"event_id": "...", "provider": { ...Hub field names... }}
     */
    public function __invoke(Request $request, ProviderRecordMapper $mapper, ApplyProviderRecord $applyProviderRecord): JsonResponse
    {
        $secret = config('tourlast.webhook_secret');

        if (blank($secret)) {
            return response()->json(['message' => 'Webhooks are not enabled.'], 404);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Tourlast-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventId = (string) $request->input('event_id');

        if ($eventId !== '' && ! Cache::add('tourlast-webhook:'.$eventId, true, now()->addDays(7))) {
            return response()->json(['result' => 'duplicate']);
        }

        try {
            $record = $mapper->fromArray((array) $request->input('provider', []));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['result' => $applyProviderRecord->handle($record, 'webhook')]);
    }
}
