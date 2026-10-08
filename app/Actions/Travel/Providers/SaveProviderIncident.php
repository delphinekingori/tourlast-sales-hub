<?php

namespace App\Actions\Travel\Providers;

use App\Enums\Travel\IncidentStatus;
use App\Models\ProviderIncident;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;

/**
 * Records a provider incident, or updates / resolves one. Anyone in Travel
 * Sales may report an incident; the reporter, the person assigned, the
 * provider's owner and Travel managers may update it.
 */
class SaveProviderIncident
{
    /**
     * @param  array<string, mixed>  $data  Validated incident fields.
     */
    public function handle(array $data, User $actor, ?ProviderIncident $incident = null): ProviderIncident
    {
        if (! $incident) {
            TravelAccess::abortUnlessWorks($actor);

            $incident = new ProviderIncident([...$data, 'reported_by' => $actor->id]);
            $incident->status ??= IncidentStatus::Open;
            $this->stampResolution($incident);
            $incident->save();

            Audit::record($incident->provider, 'incident.recorded', $incident->severity->label().' incident recorded: '.$incident->type->label());

            return $incident;
        }

        abort_unless(self::canUpdate($actor, $incident), 403);

        $before = $incident->only(array_keys($data));
        $incident->fill($data);
        $this->stampResolution($incident);
        $incident->save();
        $changes = Audit::diff($before, $incident->only(array_keys($data)));

        if ($changes !== []) {
            Audit::record($incident->provider, 'incident.updated', 'Incident updated ('.$incident->type->label().', '.$incident->status->label().')', $changes);
        }

        return $incident;
    }

    public static function canUpdate(User $actor, ProviderIncident $incident): bool
    {
        return TravelAccess::works($actor) && (
            TravelAccess::managesAll($actor)
            || in_array($actor->id, [$incident->reported_by, $incident->assigned_to, $incident->provider?->owner_id], true)
        );
    }

    private function stampResolution(ProviderIncident $incident): void
    {
        $resolved = in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);
        $incident->resolved_at = $resolved ? ($incident->resolved_at ?? now()) : null;
    }
}
