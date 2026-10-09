<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Travel\Flights\ApplyFlightRecord;
use App\Enums\Permission;
use App\Integrations\Flights\FlightRecord;
use App\Models\FlightSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Tourlast Flights Super Admin pushes bookings to the Hub's read-only copy.
 * Auth: a token with only flights:push, owned by the role-less account from
 * php artisan travel:create-flights-account (permission push-flight-bookings).
 */
class FlightIntegrationController extends ApiController
{
    /**
     * POST /integrations/flights/bookings — Send flight bookings from Flights Super Admin: {"booking": {...}} or {"bookings": [...]} (max 500).
     * Each booking uses the shape documented on App\Integrations\Flights\FlightRecord;
     * external_id, booked_at and booking_status are required. Sending a booking
     * again updates it (older updated_at values are ignored).
     */
    public function push(Request $request, ApplyFlightRecord $apply): JsonResponse
    {
        $this->requirePermission($request, Permission::PushFlightBookings);

        $request->validate([
            'booking' => ['required_without:bookings', 'array'],
            'bookings' => ['required_without:booking', 'array', 'min:1', 'max:500'],
            'bookings.*' => ['array'],
        ]);

        $rows = $request->has('bookings') ? array_values((array) $request->input('bookings')) : [(array) $request->input('booking')];
        $run = FlightSyncRun::query()->create(['source' => 'push', 'mode' => 'push', 'status' => 'running', 'started_at' => now()]);
        $counts = [ApplyFlightRecord::Created => 0, ApplyFlightRecord::Updated => 0, ApplyFlightRecord::Unchanged => 0];
        $failed = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = trim((string) ($row['external_id'] ?? '')) ?: null;

            foreach (['external_id', 'booked_at', 'booking_status'] as $key) {
                if (blank($row[$key] ?? null)) {
                    $failed[] = ['external_id' => $externalId, 'error' => "{$key} is required"];

                    continue 2;
                }
            }

            try {
                $counts[$apply->handle(FlightRecord::fromArray($row), 'push')]++;
            } catch (InvalidArgumentException $exception) {
                $failed[] = ['external_id' => $externalId, 'error' => $exception->getMessage()];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = ['external_id' => $externalId, 'error' => 'This booking could not be processed.'];
            }
        }

        $run->update([
            'status' => $failed !== [] && count($failed) === count($rows) ? 'failed' : 'succeeded',
            'records_seen' => count($rows),
            'records_created' => $counts[ApplyFlightRecord::Created],
            'records_updated' => $counts[ApplyFlightRecord::Updated],
            'records_failed' => count($failed),
            'error' => $failed === [] ? null : mb_substr(collect($failed)->map(fn (array $row) => ($row['external_id'] ?? '?').': '.$row['error'])->take(20)->implode("\n"), 0, 1000),
            'finished_at' => now(),
        ]);

        return response()->json([
            'received' => count($rows),
            'created' => $counts[ApplyFlightRecord::Created],
            'updated' => $counts[ApplyFlightRecord::Updated],
            'unchanged' => $counts[ApplyFlightRecord::Unchanged],
            'failed' => $failed,
        ], $failed !== [] && count($failed) === count($rows) ? 422 : 200);
    }
}
