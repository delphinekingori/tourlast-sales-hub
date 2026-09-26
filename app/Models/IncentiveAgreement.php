<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\IncentiveAgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A salesperson's signed agreement. Incentives are earned only while one is in force.
 */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'notes', 'created_by', 'expiry_alert_sent_at'])]
class IncentiveAgreement extends Model
{
    /** @use HasFactory<IncentiveAgreementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function covers(CarbonInterface $date): bool
    {
        return $this->starts_on->lte($date) && ($this->ends_on === null || $this->ends_on->endOfDay()->gte($date));
    }

    /**
     * @param  Builder<IncentiveAgreement>  $query
     */
    #[Scope]
    protected function coveringDate(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()));
    }

    /**
     * Agreements in force at any point of the month.
     *
     * @param  Builder<IncentiveAgreement>  $query
     */
    #[Scope]
    protected function coveringMonth(Builder $query, CarbonInterface $month): void
    {
        $query->whereDate('starts_on', '<=', $month->endOfMonth()->toDateString())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $month->startOfMonth()->toDateString()));
    }
}
