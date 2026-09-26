<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A reporting period: this week, month, quarter or year, or a custom range.
 */
final readonly class Period
{
    public function __construct(
        public string $key,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function named(string $key, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return match ($key) {
            'week' => new self('week', $now->startOfWeek(), $now->endOfWeek()),
            'quarter' => new self('quarter', $now->firstOfQuarter()->startOfDay(), $now->lastOfQuarter()->endOfDay()),
            'year' => new self('year', $now->startOfYear(), $now->endOfYear()),
            default => new self('month', $now->startOfMonth(), $now->endOfMonth()),
        };
    }

    public static function between(string $from, string $to): self
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();

        return $start->lte($end) ? new self('custom', $start, $end) : new self('custom', $end->startOfDay(), $start->endOfDay());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return ['week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'];
    }

    public function label(): string
    {
        return match ($this->key) {
            'week' => 'Week of '.$this->from->format('j M'),
            'month' => $this->from->format('F Y'),
            'quarter' => 'Q'.$this->from->quarter.' '.$this->from->year,
            'year' => (string) $this->from->year,
            default => $this->from->format('j M Y').' – '.$this->to->format('j M Y'),
        };
    }

    /**
     * Share of the period already elapsed, between 0 and 1.
     */
    public function elapsedFraction(?CarbonImmutable $now = null): float
    {
        $now ??= CarbonImmutable::now();

        if ($now->lte($this->from)) {
            return 0.0;
        }

        if ($now->gte($this->to)) {
            return 1.0;
        }

        return $this->from->diffInSeconds($now) / max(1, $this->from->diffInSeconds($this->to));
    }

    /**
     * The months this period covers, as first-of-month dates.
     *
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->from->startOfMonth(); $month->lte($this->to); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }
}
