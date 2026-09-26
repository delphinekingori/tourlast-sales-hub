<?php

namespace App\Integrations\Tourlast;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads referred providers straight from the tourlast.com database through a
 * read-only connection. Table and column names come from config/tourlast.php.
 */
class DatabaseProviderSource implements ProviderSource
{
    public function __construct(private ProviderRecordMapper $mapper) {}

    public function name(): string
    {
        return 'database';
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        $config = config('tourlast.database');

        /** @var array<string, ?string> $columns */
        $columns = array_filter($config['columns']);

        $query = DB::connection($config['connection'])
            ->table($config['table'])
            ->select(array_map(fn (string $source, string $hub): string => "{$source} as {$hub}", $columns, array_keys($columns)))
            ->when($config['only_referred'] && isset($columns['ref_code']), fn ($query) => $query
                ->whereNotNull($columns['ref_code'])
                ->where($columns['ref_code'], '!=', ''))
            ->when($since && isset($columns['updated_at']), fn ($query) => $query->where($columns['updated_at'], '>', $since));

        foreach ($query->lazyById(500, $columns['property_id'], 'property_id') as $row) {
            yield $this->mapper->fromArray((array) $row);
        }
    }
}
