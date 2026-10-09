<?php

namespace Tests\Feature\Travel\Providers;

use App\Enums\Role;
use App\Enums\Travel\IncidentStatus;
use App\Livewire\Travel\Incidents\Index;
use App\Models\AuditEvent;
use App\Models\ProviderIncident;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_incidents_are_recorded_resolved_and_audited(): void
    {
        $provider = TravelProvider::factory()->create(['name' => 'Pundamilia Tours']);
        $reporter = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($reporter)->test(Index::class)
            ->call('openIncident')
            ->set('incidentForm.travel_provider_id', $provider->id)
            ->set('incidentForm.type', 'driver_no_show')
            ->set('incidentForm.severity', 'high')
            ->set('incidentForm.description', 'Driver never arrived at the airport.')
            ->call('saveIncident')
            ->assertHasNoErrors()
            ->assertSee('Pundamilia Tours');

        $incident = ProviderIncident::query()->firstOrFail();
        $this->assertSame($reporter->id, $incident->reported_by);
        $this->assertSame(IncidentStatus::Open, $incident->status);

        // Resolving needs a resolution.
        Livewire::actingAs($reporter)->test(Index::class)
            ->call('openIncident', $incident->id)
            ->set('incidentForm.status', 'resolved')
            ->call('saveIncident')
            ->assertHasErrors('incidentForm.resolution')
            ->set('incidentForm.resolution', 'Provider refunded the transfer and replaced the driver.')
            ->call('saveIncident')
            ->assertHasNoErrors();

        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame(2, AuditEvent::query()->where('subject_id', $provider->id)->where('action', 'like', 'incident.%')->count());
    }

    public function test_only_people_involved_or_managers_update_an_incident(): void
    {
        $incident = ProviderIncident::query()->create([
            'travel_provider_id' => TravelProvider::factory()->create()->id,
            'occurred_on' => today(),
            'type' => 'vehicle_issue',
            'severity' => 'medium',
            'description' => 'Flat tyre',
            'reported_by' => User::factory()->withRole(Role::TravelSalesperson)->create()->id,
        ]);

        Livewire::actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->test(Index::class)
            ->call('openIncident', $incident->id)
            ->assertForbidden();

        Livewire::actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->test(Index::class)
            ->call('openIncident', $incident->id)
            ->assertSet('showIncident', true);
    }
}
