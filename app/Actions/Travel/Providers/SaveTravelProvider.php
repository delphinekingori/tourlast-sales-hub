<?php

namespace App\Actions\Travel\Providers;

use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a travel provider and writes the audit log. Travel
 * salespeople always own the providers they create; only Travel managers
 * may set or change the owner.
 */
class SaveTravelProvider
{
    /**
     * @param  array<string, mixed>  $data  Validated provider fields.
     */
    public function handle(array $data, User $actor, ?TravelProvider $provider = null): TravelProvider
    {
        if ($provider) {
            TravelAccess::abortUnlessCanChange($actor, $provider->owner_id);
        } else {
            TravelAccess::abortUnlessWorks($actor);
        }

        if (! TravelAccess::managesAll($actor)) {
            $data['owner_id'] = $provider?->owner_id ?? $actor->id;
        } else {
            $data['owner_id'] = ($data['owner_id'] ?? null) ?: ($provider?->owner_id ?? $actor->id);
        }

        return DB::transaction(function () use ($data, $actor, $provider): TravelProvider {
            if (! $provider) {
                $provider = TravelProvider::query()->create([...$data, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                Audit::record($provider, 'provider.created', 'Added travel provider '.$provider->name);

                return $provider;
            }

            $before = $provider->only(array_keys($data));
            $provider->fill([...$data, 'updated_by' => $actor->id])->save();
            $changes = Audit::diff($before, $provider->only(array_keys($data)));

            if ($changes !== []) {
                $statusChanged = array_key_exists('status', $changes);
                Audit::record(
                    $provider,
                    $statusChanged ? 'provider.status_changed' : 'provider.updated',
                    $statusChanged
                        ? 'Provider status changed to '.$provider->status->label()
                        : 'Updated provider '.$provider->name,
                    $changes,
                );
            }

            return $provider;
        });
    }

    /**
     * Archive (hide) or restore a provider. Travel managers only.
     */
    public function setArchived(TravelProvider $provider, bool $archived, User $actor): void
    {
        abort_unless(TravelAccess::managesAll($actor), 403);

        $provider->forceFill(['archived_at' => $archived ? now() : null, 'updated_by' => $actor->id])->save();
        Audit::record($provider, $archived ? 'provider.archived' : 'provider.restored', ($archived ? 'Archived ' : 'Restored ').$provider->name);
    }
}
