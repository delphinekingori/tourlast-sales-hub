<?php

namespace App\Console\Commands;

use App\Actions\SyncOnboardings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:sync-tourlast {--full : Re-read every referred provider instead of only recent changes}')]
#[Description('Pull referred providers from tourlast.com and update onboardings')]
class SyncTourlast extends Command
{
    public function handle(SyncOnboardings $syncOnboardings): int
    {
        $run = $syncOnboardings->handle($this->option('full') ? 'full' : 'incremental');

        if (! $run->succeeded()) {
            $this->components->error("Sync from {$run->source} failed: {$run->error}");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Synced from %s: %d seen, %d new, %d updated%s.',
            $run->source,
            $run->records_seen,
            $run->records_created,
            $run->records_updated,
            $run->records_deleted > 0 ? ", {$run->records_deleted} deleted" : '',
        ));

        return self::SUCCESS;
    }
}
