<?php

namespace App\Models;

use App\Enums\Travel\TravelTargetMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly Travel Sales target (bookings or revenue) set by a Sales Admin.
 * Stored in the targets table beside the points targets, under its own metric.
 */
#[Fillable(['user_id', 'month', 'metric', 'target', 'target_value', 'set_by'])]
class TravelTarget extends Model
{
    protected $table = 'targets';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'target' => 0,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('travel', fn (Builder $query) => $query->whereIn(
            $query->qualifyColumn('metric'),
            array_column(TravelTargetMetric::cases(), 'value'),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => TravelTargetMetric::class,
            'target_value' => 'integer',
        ];
    }

    /**
     * Always the first day of the month, as a plain Y-m-d date.
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
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
