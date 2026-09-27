<?php

namespace App\Integrations\Tourlast;

use Carbon\CarbonImmutable;

/**
 * TOURLAST_SOURCE=push: tourlast.com sends provider records to the API
 * (POST /api/v1/integrations/tourlast/providers), so there is nothing for the
 * Hub to read. The scheduled sync is skipped in this mode.
 */
class PushOnlyProviderSource implements ProviderSource
{
    public function name(): string
    {
        return 'push';
    }

    /**
     * @return iterable<ProviderRecord>
     */
    public function changedSince(?CarbonImmutable $since): iterable
    {
        return [];
    }
}
