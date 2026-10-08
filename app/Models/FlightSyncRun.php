<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One flights sync: a scheduled pull ("full" or "incremental") or a push
 * received from Flights Super Admin ("push").
 */
#[Fillable([
    'source', 'mode', 'status', 'changed_since', 'records_seen', 'records_created',
    'records_updated', 'records_failed', 'error', 'started_at', 'finished_at',
])]
class FlightSyncRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_since' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
