<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteUser
{
    /**
     * Records that make an account part of Tourlast's history. Deleting such
     * a user would remove (or be blocked by) sales, pay and audit records, so
     * those people are fired instead.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const History = [
        'leads' => ['leads', ['user_id']],
        'activities' => ['activities', ['user_id']],
        'scheduled items' => ['follow_ups', ['user_id']],
        'onboardings' => ['onboardings', ['user_id']],
        'point entries' => ['point_entries', ['user_id']],
        'payout statements' => ['payout_statements', ['user_id']],
        'expense claims' => ['expense_claims', ['user_id']],
        'incentive agreements' => ['incentive_agreements', ['user_id']],
        'claim approvals' => ['claim_approvals', ['user_id']],
        'registry properties' => ['property_engagement_reps', ['user_id']],
        'lead transfers' => ['lead_transfers', ['from_user_id', 'to_user_id', 'transferred_by']],
        'credit changes' => ['attribution_changes', ['from_user_id', 'to_user_id', 'changed_by']],
        'announcements' => ['announcements', ['user_id']],
        'invitations sent' => ['invitations', ['invited_by']],
    ];

    /**
     * What stops this account being deleted, e.g. ["12 leads", "3 payout statements"].
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach (self::History as $label => [$table, $columns]) {
            $count = DB::table($table)->where(function ($query) use ($columns, $user): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, $user->id);
                }
            })->count();

            if ($count > 0) {
                $blockers[] = $count.' '.$label;
            }
        }

        $clicks = DB::table('referral_clicks')->whereIn('referral_code_id', $user->referralCodes()->select('id'))->count();

        if ($clicks > 0) {
            $blockers[] = $clicks.' referral link clicks';
        }

        return $blockers;
    }

    /**
     * Permanently remove an account that has no business history.
     *
     * @return bool False when the account has history (nothing is deleted).
     */
    public function handle(User $user): bool
    {
        if ($this->blockers($user) !== []) {
            return false;
        }

        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->notifications()->delete();
            $user->referralCodes()->delete();
            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();
        });

        return true;
    }
}
