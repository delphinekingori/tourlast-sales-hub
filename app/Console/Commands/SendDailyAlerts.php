<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Models\IncentiveAgreement;
use App\Models\User;
use App\Support\Alerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:send-daily-alerts')]
#[Description('Smart Alerts for contracts expiring within 30 days and overdue follow-ups')]
class SendDailyAlerts extends Command
{
    public function handle(): int
    {
        $expiring = IncentiveAgreement::query()
            ->with('user')
            ->whereNull('expiry_alert_sent_at')
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->whereDate('ends_on', '<=', now()->addDays(30)->toDateString())
            ->get();

        foreach ($expiring as $agreement) {
            Alerts::send(
                'contract_expiring',
                'Contract expiring',
                "{$agreement->user->name}'s incentive agreement ends on {$agreement->ends_on->format('j M Y')}. Expansion points and incentives stop on that date unless it is renewed.",
                route('admin.incentives'),
                $agreement->user,
            );
            $agreement->update(['expiry_alert_sent_at' => now()]);
        }

        $overdue = FollowUp::query()->forLeads()->open()->where('due_at', '<', now()->startOfDay())
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        foreach ($overdue as $userId => $count) {
            $user = User::find($userId);

            if (! $user?->is_active) {
                continue;
            }

            Alerts::send(
                'follow_up_overdue',
                'Follow-up overdue',
                "{$user->name} has {$count} overdue ".str('follow-up')->plural($count).'.',
                route('activities.index'),
                $user,
            );
        }

        $this->components->info("{$expiring->count()} expiring contracts and {$overdue->count()} salespeople with overdue follow-ups alerted.");

        return self::SUCCESS;
    }
}
