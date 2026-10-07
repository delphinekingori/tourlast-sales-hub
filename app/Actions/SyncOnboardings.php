<?php

namespace App\Actions;

use App\Integrations\Tourlast\ProviderSource;
use App\Integrations\Tourlast\ReportsSourceFailures;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull referred providers from tourlast.com and apply them.
 *
 * "incremental" reads only what changed since the last successful run.
 * "full" re-reads everything, which catches anything an incremental run missed.
 */
class SyncOnboardings
{
    public function __construct(private ProviderSource $source, private ApplyProviderRecord $applyProviderRecord) {}

    public function handle(string $mode = 'incremental'): SyncRun
    {
        $since = $mode === 'incremental' ? $this->lastSuccessfulStart() : null;

        $run = SyncRun::create([
            'source' => $this->source->name(),
            'mode' => $mode,
            'status' => 'running',
            'changed_since' => $since,
            'started_at' => now(),
        ]);

        $counts = ['records_seen' => 0, 'records_created' => 0, 'records_updated' => 0, 'records_deleted' => 0];
        $failures = [];

        try {
            foreach ($this->source->changedSince($since) as $record) {
                $counts['records_seen']++;

                try {
                    match ($this->applyProviderRecord->handle($record, 'sync')) {
                        ApplyProviderRecord::Created => $counts['records_created']++,
                        ApplyProviderRecord::Updated => $counts['records_updated']++,
                        ApplyProviderRecord::Deleted => $counts['records_deleted']++,
                        default => null,
                    };
                } catch (Throwable $exception) {
                    report($exception);

                    $failures[] = "{$record->propertyId} could not be applied";
                }
            }
        } catch (Throwable $exception) {
            Log::error('tourlast.com sync failed', ['exception' => $exception]);

            $failures[] = $this->source instanceof ReportsSourceFailures ? 'The source could not be read' : $exception->getMessage();
        }

        if ($this->source instanceof ReportsSourceFailures) {
            $failures = [...$this->source->failures(), ...$failures];
        }

        // A run with anything skipped counts as failed, so the next incremental
        // run starts from the last clean one and picks the skipped records up again.
        $run->update([
            ...$counts,
            'status' => $failures === [] ? 'succeeded' : 'failed',
            'error' => $failures === [] ? null : mb_substr(implode('; ', array_slice($failures, 0, 10)), 0, 2000),
            'finished_at' => now(),
        ]);

        return $run;
    }

    /**
     * Start time of the last successful run, minus a minute of overlap so that
     * records saved while that run was in progress are not skipped.
     */
    private function lastSuccessfulStart(): ?CarbonImmutable
    {
        $startedAt = SyncRun::query()->where('status', 'succeeded')->latest('started_at')->value('started_at');

        return $startedAt ? CarbonImmutable::parse($startedAt)->subMinute() : null;
    }
}
