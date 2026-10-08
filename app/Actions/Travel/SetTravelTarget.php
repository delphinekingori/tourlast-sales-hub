<?php

namespace App\Actions\Travel;

use App\Enums\Permission;
use App\Enums\Travel\TravelTargetMetric;
use App\Models\TravelTarget;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A Sales Admin sets (or clears) one travel salesperson's monthly target for
 * one metric. Travel salespeople cannot set their own.
 */
class SetTravelTarget
{
    public function handle(User $actor, User $salesperson, CarbonImmutable $month, TravelTargetMetric $metric, ?int $value): ?TravelTarget
    {
        abort_unless($actor->can(Permission::ManageTravelTargets->value), 403);
        abort_unless($salesperson->isTravelSalesperson(), 422, 'Travel targets are only for travel salespeople.');

        if ($month->lt(CarbonImmutable::now()->startOfMonth()->subMonth())) {
            throw ValidationException::withMessages(['target' => 'Targets for months before last month can no longer be changed.']);
        }

        if ($value !== null && $value < 0) {
            throw ValidationException::withMessages(['target' => 'A target cannot be negative.']);
        }

        $existing = TravelTarget::query()
            ->where('user_id', $salesperson->id)
            ->whereDate('month', $month->startOfMonth()->toDateString())
            ->where('metric', $metric->value)
            ->first();

        $old = $existing?->target_value;

        if ($value === null) {
            $existing?->delete();
            $target = null;
        } else {
            $target = $existing ?? new TravelTarget(['user_id' => $salesperson->id, 'month' => $month, 'metric' => $metric]);
            $target->fill(['target_value' => $value, 'set_by' => $actor->id])->save();
        }

        if ($old !== $value) {
            Audit::record(
                $salesperson,
                'travel_target.set',
                "{$metric->label()} target for {$month->format('F Y')}: ".($value === null ? 'cleared' : number_format($value)),
                ['target_value' => [$old, $value]],
            );
        }

        return $target;
    }
}
