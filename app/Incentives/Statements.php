<?php

namespace App\Incentives;

use App\Models\ExpenseClaim;
use App\Models\IncentiveAgreement;
use App\Models\IncentivePolicy;
use App\Models\PayoutStatement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payout statements: drafted from live figures, frozen on approval, paid by the 5th.
 */
class Statements
{
    public function __construct(private MonthlyEarnings $earnings) {}

    /**
     * Create or refresh draft statements for everyone with an agreement in the month.
     *
     * @return Collection<int, PayoutStatement>
     */
    public function generate(CarbonImmutable $month): Collection
    {
        $month = $month->startOfMonth();

        $userIds = IncentiveAgreement::query()->coveringMonth($month)->pluck('user_id')->unique();

        return User::query()->whereIn('id', $userIds)->orderBy('name')->get()
            ->map(fn (User $user): PayoutStatement => $this->draft($user, $month));
    }

    public function draft(User $user, CarbonImmutable $month): PayoutStatement
    {
        $month = $month->startOfMonth();
        $statement = PayoutStatement::query()->where('user_id', $user->id)->whereDate('month', $month->toDateString())->first()
            ?? new PayoutStatement(['user_id' => $user->id, 'month' => $month->toDateString()]);

        if ($statement->isLocked()) {
            return $statement;
        }

        if (! $statement->exists) {
            $statement->compliance = array_fill_keys(array_keys(PayoutStatement::ComplianceItems), false);
            $statement->status = 'draft';
        }

        $earnings = $this->earnings->for($user, $month, $statement->isCompliant());

        $statement->fill([
            'incentive_policy_id' => IncentivePolicy::for($month)->id,
            'points' => $earnings->points,
            'weekly_points' => $earnings->weeklyPoints,
            'retainer' => $earnings->retainer,
            'weekly_bonus' => $earnings->weeklyBonusTotal(),
            'monthly_bonus' => $earnings->monthlyBonus,
            'exceptional' => $earnings->exceptional,
            'airtime' => $earnings->airtime,
            'transport' => $earnings->transport,
            'adjustments' => $earnings->adjustments,
            'adjustment_lines' => $earnings->adjustmentLines,
            'total' => $earnings->total(),
        ])->save();

        return $statement;
    }

    /**
     * @param  array<string, bool>  $compliance
     */
    public function setCompliance(PayoutStatement $statement, array $compliance): PayoutStatement
    {
        if ($statement->isLocked()) {
            throw ValidationException::withMessages(['compliance' => 'This statement is already approved.']);
        }

        $statement->update(['compliance' => array_map('boolval', array_intersect_key($compliance, PayoutStatement::ComplianceItems))]);

        return $this->draft($statement->user, $statement->month);
    }

    /**
     * Freeze the figures. Corrections to earlier months shown on this statement count as settled.
     */
    public function approve(PayoutStatement $statement, User $approver): PayoutStatement
    {
        return DB::transaction(function () use ($statement, $approver): PayoutStatement {
            $statement = $this->draft($statement->user, $statement->month);

            foreach ($statement->adjustment_lines ?? [] as $line) {
                PayoutStatement::query()->whereKey($line['statement_id'])->increment('recovered_amount', $line['amount']);
            }

            $statement->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);

            return $statement;
        });
    }

    public function markPaid(PayoutStatement $statement, User $payer, string $reference): PayoutStatement
    {
        if ($statement->status !== 'approved') {
            throw ValidationException::withMessages(['paymentReference' => 'Approve the statement before marking it paid.']);
        }

        return DB::transaction(function () use ($statement, $payer, $reference): PayoutStatement {
            $statement->update(['status' => 'paid', 'paid_by' => $payer->id, 'paid_at' => now(), 'payment_reference' => $reference]);

            ExpenseClaim::query()
                ->where('user_id', $statement->user_id)
                ->whereIn('type', ['airtime', 'transport_reimbursement'])
                ->whereDate('month', $statement->month->toDateString())
                ->where('status', 'approved')
                ->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $payer->id, 'payment_reference' => $reference]);

            return $statement;
        });
    }

    public function paymentDueOn(CarbonImmutable $month): CarbonImmutable
    {
        return $month->startOfMonth()->addMonth()->day(IncentivePolicy::for($month)->policy()->paymentDay());
    }
}
