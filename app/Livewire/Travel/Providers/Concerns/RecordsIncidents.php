<?php

namespace App\Livewire\Travel\Providers\Concerns;

use App\Actions\Travel\Providers\SaveProviderIncident;
use App\Enums\Travel\IncidentSeverity;
use App\Enums\Travel\IncidentStatus;
use App\Enums\Travel\IncidentType;
use App\Models\ProviderIncident;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * The record / update incident pop-up, shared by the provider page and the
 * incident log.
 */
trait RecordsIncidents
{
    public bool $showIncident = false;

    #[Locked]
    public ?int $editingIncidentId = null;

    /** @var array<string, mixed> */
    public array $incidentForm = [];

    public function openIncident(?int $incidentId = null, ?int $providerId = null): void
    {
        $user = Auth::user();
        $incident = $incidentId ? ProviderIncident::query()->with('provider')->findOrFail($incidentId) : null;

        $incident
            ? abort_unless(SaveProviderIncident::canUpdate($user, $incident), 403)
            : TravelAccess::abortUnlessWorks($user);

        $this->editingIncidentId = $incident?->id;
        $this->incidentForm = [
            'travel_provider_id' => $incident?->travel_provider_id ?? $providerId,
            'package_id' => $incident?->package_id,
            'occurred_on' => $incident?->occurred_on?->toDateString() ?? today()->toDateString(),
            'type' => $incident?->type?->value ?? IncidentType::CustomerComplaint->value,
            'severity' => $incident?->severity?->value ?? IncidentSeverity::Medium->value,
            'status' => $incident?->status?->value ?? IncidentStatus::Open->value,
            'description' => $incident?->description,
            'resolution' => $incident?->resolution,
            'assigned_to' => $incident?->assigned_to,
        ];
        $this->resetErrorBag();
        $this->showIncident = true;
    }

    public function saveIncident(SaveProviderIncident $save): void
    {
        $incident = $this->editingIncidentId ? ProviderIncident::query()->findOrFail($this->editingIncidentId) : null;

        $data = $this->validate([
            'incidentForm.travel_provider_id' => ['required', Rule::exists('travel_providers', 'id')],
            'incidentForm.package_id' => ['nullable', Rule::exists('packages', 'id')->where('travel_provider_id', $this->incidentForm['travel_provider_id'] ?? 0)],
            'incidentForm.occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'incidentForm.type' => ['required', Rule::enum(IncidentType::class)],
            'incidentForm.severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'incidentForm.status' => ['required', Rule::enum(IncidentStatus::class)],
            'incidentForm.description' => ['required', 'string', 'max:5000'],
            'incidentForm.resolution' => [Rule::requiredIf(in_array($this->incidentForm['status'] ?? null, [IncidentStatus::Resolved->value, IncidentStatus::Closed->value], true)), 'nullable', 'string', 'max:5000'],
            'incidentForm.assigned_to' => ['nullable', Rule::exists('users', 'id')],
        ], [], [
            'incidentForm.travel_provider_id' => 'provider',
            'incidentForm.occurred_on' => 'date',
            'incidentForm.resolution' => 'resolution',
        ])['incidentForm'];

        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);

        if ($incident) {
            unset($data['travel_provider_id']);
        }

        $save->handle($data, Auth::user(), $incident);

        $this->showIncident = false;
        $this->dispatch('toast', message: $incident ? 'Incident updated.' : 'Incident recorded.');
    }
}
