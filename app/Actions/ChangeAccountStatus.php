<?php

namespace App\Actions;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\Alerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChangeAccountStatus
{
    /**
     * Suspend an account: signed out and blocked from signing in, nothing
     * removed. With an end date it is lifted automatically that morning.
     */
    public function suspend(User $user, User $by, string $reason, ?string $notes = null, ?CarbonImmutable $until = null): void
    {
        $this->change($user, $by, AccountStatus::Suspended, $reason, $notes, $until);

        Alerts::send('account_status', 'Account suspended', "{$user->name} was suspended by {$by->name}".($until ? ' until '.$until->format('j M Y') : '').'. Reason: '.AccountStatus::reasonLabel($reason).'.', route('people.show', $user));
    }

    /**
     * Fire (terminate) an account. Their leads, history, points and pay
     * records are all kept; only a Sales Admin can reinstate them.
     */
    public function terminate(User $user, User $by, string $reason, ?string $notes = null): void
    {
        $this->change($user, $by, AccountStatus::Terminated, $reason, $notes);

        Alerts::send('account_status', 'Account terminated', "{$user->name} was fired by {$by->name}. Reason: ".AccountStatus::reasonLabel($reason).'. Their open leads and properties need a new owner.', route('people.show', $user));
    }

    public function reinstate(User $user, ?User $by, ?string $notes = null): void
    {
        $this->change($user, $by, AccountStatus::Active, null, $notes);
    }

    private function change(User $user, ?User $by, AccountStatus $to, ?string $reason, ?string $notes, ?CarbonImmutable $until = null): void
    {
        DB::transaction(function () use ($user, $by, $to, $reason, $notes, $until): void {
            $user->statusChanges()->create([
                'from_status' => $user->accountStatus(),
                'to_status' => $to,
                'reason' => $reason,
                'notes' => $notes,
                'suspended_until' => $until?->toDateString(),
                'changed_by' => $by?->id,
            ]);

            $user->forceFill([
                'account_status' => $to,
                'is_active' => $to === AccountStatus::Active,
                'suspended_until' => $to === AccountStatus::Suspended ? $until?->toDateString() : null,
                'status_changed_at' => now(),
                'last_seen_at' => $to === AccountStatus::Active ? $user->last_seen_at : null,
            ])->save();

            if ($to !== AccountStatus::Active) {
                // Sign them out everywhere straight away.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->forceFill(['remember_token' => null])->save();
            }
        });
    }
}
