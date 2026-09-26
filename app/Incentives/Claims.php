<?php

namespace App\Incentives;

use App\Enums\Permission;
use App\Models\ClaimAttachment;
use App\Models\ExpenseClaim;
use App\Models\IncentivePolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Airtime and transport claims, and their approval chain (Manager → HR → Finance).
 */
class Claims
{
    public const StepPermissions = [
        'manager' => Permission::ApproveClaimsManager,
        'hr' => Permission::ApproveClaimsHr,
        'finance' => Permission::ApproveClaimsFinance,
    ];

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, list<UploadedFile>>  $files  keyed by attachment kind
     */
    public function submit(User $user, array $details, array $files = []): ExpenseClaim
    {
        return DB::transaction(function () use ($user, $details, $files): ExpenseClaim {
            $claim = new ExpenseClaim([...$details, 'user_id' => $user->id, 'status' => 'pending']);
            $claim->month = CarbonImmutable::parse($details['travel_date'] ?? now())->startOfMonth()->toDateString();
            $claim->current_step = $claim->steps()[0];
            $claim->save();

            $this->attach($claim, $files);

            return $claim;
        });
    }

    /**
     * @param  array<string, list<UploadedFile>>  $files
     */
    public function attach(ExpenseClaim $claim, array $files): void
    {
        foreach ($files as $kind => $uploads) {
            foreach ($uploads as $upload) {
                $claim->attachments()->create([
                    'kind' => array_key_exists($kind, ClaimAttachment::Kinds) ? $kind : 'other',
                    'path' => $upload->store('claims/'.$claim->id, 'local'),
                    'original_name' => mb_substr($upload->getClientOriginalName(), 0, 250),
                    'mime' => $upload->getMimeType(),
                    'size' => $upload->getSize(),
                ]);
            }
        }
    }

    public function canDecide(User $user, ExpenseClaim $claim): bool
    {
        if ($claim->status !== 'pending' || ! $claim->current_step || $claim->user_id === $user->id) {
            return false;
        }

        return $user->can(self::StepPermissions[$claim->current_step]->value);
    }

    public function approve(ExpenseClaim $claim, User $approver, ?string $note = null, ?float $amount = null): ExpenseClaim
    {
        $this->guard($claim, $approver);

        return DB::transaction(function () use ($claim, $approver, $note, $amount): ExpenseClaim {
            $amount = $amount ?? $claim->approved_amount ?? $claim->amount;

            if ($claim->type === 'airtime' && $claim->current_step === 'finance') {
                $amount = min($amount, $this->airtimeRemaining($claim));

                if ($amount <= 0) {
                    throw ValidationException::withMessages(['decisionAmount' => 'The KES '.$this->airtimeCap($claim).' airtime allowance for this month is already used.']);
                }
            }

            $claim->approvals()->create([
                'step' => $claim->current_step,
                'user_id' => $approver->id,
                'decision' => 'approved',
                'amount' => $amount,
                'note' => $note,
            ]);

            $steps = $claim->steps();
            $next = $steps[array_search($claim->current_step, $steps, true) + 1] ?? null;

            $claim->update([
                'approved_amount' => $amount,
                'current_step' => $next,
                'status' => $next ? 'pending' : 'approved',
            ]);

            return $claim;
        });
    }

    public function reject(ExpenseClaim $claim, User $approver, string $note): ExpenseClaim
    {
        $this->guard($claim, $approver);

        $claim->approvals()->create([
            'step' => $claim->current_step,
            'user_id' => $approver->id,
            'decision' => 'rejected',
            'note' => $note,
        ]);

        $claim->update(['status' => 'rejected', 'current_step' => null]);

        return $claim;
    }

    /**
     * Finance releases the money for an approved transport request.
     */
    public function disburse(ExpenseClaim $claim, User $finance, string $reference): ExpenseClaim
    {
        if (! $claim->isRequest() || $claim->status !== 'approved' || ! $finance->can(Permission::ApproveClaimsFinance->value)) {
            throw ValidationException::withMessages(['paymentReference' => 'Only approved transport requests can be disbursed by Finance.']);
        }

        $claim->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $finance->id, 'payment_reference' => $reference]);

        return $claim;
    }

    public function airtimeCap(ExpenseClaim $claim): int
    {
        return IncentivePolicy::for($claim->month)->policy()->airtimeCap();
    }

    public function airtimeRemaining(ExpenseClaim $claim): float
    {
        $used = ExpenseClaim::query()
            ->where('user_id', $claim->user_id)
            ->where('type', 'airtime')
            ->whereDate('month', $claim->month->toDateString())
            ->whereIn('status', ['approved', 'paid'])
            ->whereKeyNot($claim->id)
            ->get()
            ->sum(fn (ExpenseClaim $other): float => $other->payableAmount());

        return max(0, $this->airtimeCap($claim) - $used);
    }

    private function guard(ExpenseClaim $claim, User $approver): void
    {
        if (! $this->canDecide($approver, $claim)) {
            abort(403, 'This claim is not waiting for your approval.');
        }
    }
}
