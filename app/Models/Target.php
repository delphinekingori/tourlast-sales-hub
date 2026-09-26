<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'month', 'target'])]
class Target extends Model
{
    /** @use HasFactory<TargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'integer',
        ];
    }

    /**
     * Always stored as the first day of the month, as a plain Y-m-d date.
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function month(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value)->startOfMonth() : null,
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->startOfMonth()->toDateString(),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A month's target can be set or changed until the lock day, then it is fixed.
     * Past months are always locked; future months are always open.
     */
    public static function isLockedFor(CarbonInterface $month, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());
        $month = CarbonImmutable::instance($month)->startOfMonth();

        if ($month->lt($now->startOfMonth())) {
            return true;
        }

        if ($month->gt($now->startOfMonth())) {
            return false;
        }

        return $now->day > config('hub.target_lock_day');
    }

    public static function lockDateFor(CarbonInterface $month): CarbonImmutable
    {
        return CarbonImmutable::instance($month)->startOfMonth()->day((int) config('hub.target_lock_day'));
    }
}
