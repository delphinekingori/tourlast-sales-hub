<?php

namespace App\Models;

use Database\Factories\SandboxProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Local stand-in for a tourlast.com provider record, used until the real
 * read-only connection is configured.
 */
#[Fillable([
    'property_id', 'account_id', 'ref_code', 'property_name', 'legal_name', 'property_type', 'category', 'inventory_count', 'location', 'contact_name',
    'contact_phone', 'contact_email', 'status', 'submitted_at', 'approved_at', 'active_at', 'inactive_at', 'rejected_at', 'first_booking_at',
])]
class SandboxProvider extends Model
{
    /** @use HasFactory<SandboxProviderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'active_at' => 'datetime',
            'inactive_at' => 'datetime',
            'rejected_at' => 'datetime',
            'first_booking_at' => 'datetime',
        ];
    }
}
