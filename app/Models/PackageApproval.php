<?php

namespace App\Models;

use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\ApprovalLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review decision on a package version. Append-only: never updated or
 * deleted, and only written by the approval actions.
 */
#[Fillable(['package_id', 'package_version_id', 'level', 'decision', 'reason', 'user_id', 'decided_at'])]
class PackageApproval extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ApprovalLevel::class,
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'package_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
