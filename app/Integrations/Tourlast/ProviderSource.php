<?php

namespace App\Integrations\Tourlast;

use Carbon\CarbonImmutable;

/**
 * Where the Hub reads referred providers from. Implementations must be read-only.
 */
interface ProviderSource
{
    /**
     * Short name shown in the sync log, e.g. "sandbox", "database", "api".
     */
    public function name(): string;

    /**
     * Providers created or changed after $since, or every referred provider when $since is null.
     *
     * @return iterable<ProviderRecord>
     */
    public function changedSince(?CarbonImmutable $since): iterable;
}
