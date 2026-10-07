<?php

namespace App\Models;

use App\Incentives\Policy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'effective_from', 'rules'])]
class IncentivePolicy extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'rules' => 'array',
        ];
    }

    public function policy(): Policy
    {
        return new Policy($this->rules);
    }

    /**
     * The schedule in force on a date. Falls back to Schedule 1 when none is stored.
     *
     * The few schedules are read once per request and reused, so listing many
     * accounts does not repeat the same lookup for each one.
     */
    public static function for(CarbonInterface $date): self
    {
        $day = $date->toDateString();
        $schedules = static::loadedSchedules();

        return $schedules->first(fn (self $schedule): bool => $schedule->effective_from->toDateString() <= $day)
            ?? $schedules->last()
            ?? new static(['name' => 'Schedule 1', 'effective_from' => '2026-01-01', 'rules' => Policy::schedule1()]);
    }

    /**
     * @return Collection<int, static>
     */
    private static function loadedSchedules(): Collection
    {
        if (! app()->bound('incentive.schedules')) {
            app()->scoped('incentive.schedules', fn (): Collection => static::query()->orderByDesc('effective_from')->orderByDesc('id')->get());
        }

        return app('incentive.schedules');
    }

    protected static function booted(): void
    {
        $forget = fn () => app()->forgetInstance('incentive.schedules');

        static::saved($forget);
        static::deleted($forget);
    }
}
