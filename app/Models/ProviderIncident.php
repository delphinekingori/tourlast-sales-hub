<?php

namespace App\Models;

use App\Enums\Travel\IncidentSeverity;
use App\Enums\Travel\IncidentStatus;
use App\Enums\Travel\IncidentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A serious issue with a provider (no-show, safety concern, complaint...).
 */
#[Fillable([
    'travel_provider_id', 'package_id', 'package_booking_id', 'occurred_on', 'type', 'severity', 'description',
    'resolution', 'assigned_to', 'status', 'reported_by', 'resolved_at',
])]
class ProviderIncident extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'type' => IncidentType::class,
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<PackageBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PackageBooking::class, 'package_booking_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
