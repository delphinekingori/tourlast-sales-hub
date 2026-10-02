<?php

namespace App\Http\Resources\V1;

use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tourlast.com sync run from the log.
 *
 * @mixin SyncRun
 */
class SyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'mode' => $this->mode,
            'status' => $this->status,
            'changed_since' => $this->changed_since?->toIso8601String(),
            'records_seen' => (int) $this->records_seen,
            'records_created' => (int) $this->records_created,
            'records_updated' => (int) $this->records_updated,
            'records_deleted' => (int) $this->records_deleted,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
