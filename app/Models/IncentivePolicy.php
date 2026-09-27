<?php

namespace App\Models;

use App\Incentives\Policy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     */
    public static function for(CarbonInterface $date): self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()
            ?? static::query()->orderBy('effective_from')->first()
            ?? new static(['name' => 'Schedule 1', 'effective_from' => '2026-01-01', 'rules' => Policy::schedule1()]);
    }
}
