<?php

namespace App\Actions;

use App\Integrations\Tourlast\ProviderSource;
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

        try {
            foreach ($this->source->changedSince($since) as $record) {
                $counts['records_seen']++;

                match ($this->applyProviderRecord->handle($record, 'sync')) {
                    ApplyProviderRecord::Created => $counts['records_created']++,
                    ApplyProviderRecord::Updated => $counts['records_updated']++,
                    ApplyProviderRecord::Deleted => $counts['records_deleted']++,
                    default => null,
                };
            }

            $run->update([...$counts, 'status' => 'succeeded', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('tourlast.com sync failed', ['exception' => $exception]);

            $run->update([
                ...$counts,
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        }

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
