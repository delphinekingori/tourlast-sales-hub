<?php

namespace Database\Factories;

use App\Models\PointEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointEntry>
 */
class PointEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $earnedOn = now()->startOfMonth()->addDays(1)->setTime(10, 0);

        return [
            'user_id' => User::factory(),
            'type' => 'base',
            'points' => 3,
            'earned_on' => $earnedOn,
            'month' => $earnedOn->toDateString(),
            'bonus_week' => 1,
            'status' => 'approved',
        ];
    }

    /**
     * Earned on a given date, with the matching month and Schedule 1 bonus week.
     */
    public function on(\DateTimeInterface $date): static
    {
        $day = (int) $date->format('j');

        return $this->state(fn (array $attributes) => [
            'earned_on' => $date,
            'month' => $date->format('Y-m-01'),
            'bonus_week' => match (true) {
                $day <= 7 => 1,
                $day <= 14 => 2,
                $day <= 21 => 3,
                default => 4,
            },
        ]);
    }

    public function provisional(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'provisional']);
    }
}
