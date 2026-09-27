<?php

namespace App\Actions;

use App\Models\Target;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SetTarget
{
    /**
     * Salespeople set their own monthly target. It can be changed until the
     * lock day of that month; after that it is fixed.
     */
    public function handle(User $user, CarbonImmutable $month, int $target): Target
    {
        $month = $month->startOfMonth();

        if (Target::isLockedFor($month)) {
            throw ValidationException::withMessages([
                'targetValue' => 'The target for '.$month->format('F Y').' is locked. Targets can be changed until the '
                    .Target::lockDateFor($month)->format('jS').' of the month.',
            ]);
        }

        return Target::updateOrCreate(
            ['user_id' => $user->id, 'month' => $month->toDateString()],
            ['target' => $target],
        );
    }
}
