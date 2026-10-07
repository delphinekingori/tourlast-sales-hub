<?php

namespace App\Integrations\Tourlast;

/**
 * A provider source that keeps going when one app or one record fails, and
 * reports what it had to skip once the sync has read everything.
 */
interface ReportsSourceFailures
{
    /**
     * Short, safe-to-show descriptions of what was skipped during the last read.
     * Never includes response bodies, file paths or other internals.
     *
     * @return list<string>
     */
    public function failures(): array;
}
