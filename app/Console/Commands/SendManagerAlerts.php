<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Mail\ManagerDailyAlert;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Support\Period;
use App\Support\SalesMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('hub:send-manager-alerts')]
#[Description('Email managers who is inactive, which signups are stalled, and who is behind target')]
class SendManagerAlerts extends Command
{
    public function handle(SalesMetrics $metrics): int
    {
        $period = Period::named('month');
        $team = $metrics->team($period);

        $alert = [
            'inactive' => $team->filter(fn (array $row): bool => $row['status']['tone'] === 'danger')
                ->map(fn (array $row): array => ['name' => $row['user']->name, 'label' => $row['status']['label']])->values()->all(),
            'behind' => $team->filter(fn (array $row): bool => $row['status']['label'] === 'Behind')
                ->map(fn (array $row): array => ['name' => $row['user']->name, 'onboarded' => $row['metrics']['onboarded'], 'target' => $row['metrics']['target']])->values()->all(),
            'noTarget' => $team->filter(fn (array $row): bool => $row['metrics']['target'] === null)->map(fn (array $row): string => $row['user']->name)->values()->all(),
            'stalled' => Onboarding::query()->awaitingApproval()->where('submitted_at', '<', now()->subDays(config('hub.stalled_after_days')))->with('user')->oldest('submitted_at')->limit(15)->get()
                ->map(fn (Onboarding $onboarding): array => ['property' => $onboarding->property_name, 'salesperson' => $onboarding->user?->name ?? 'Unattributed', 'days' => (int) $onboarding->submitted_at->diffInDays(now())])->all(),
            'unattributed' => Onboarding::query()->unattributed()->count(),
            'verificationBacklog' => PartnerAccount::query()->current()->whereNotNull('activation_date')->where('qualification_status', 'pending')->whereNull('review_failed_at')->count(),
            'reviewWarnings' => PartnerAccount::query()->current()->whereNotNull('review_warning_at')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays(14))->pluck('legal_name')->all(),
            'nearRetainer' => $team->filter(fn (array $row): bool => ($row['metrics']['points'] >= 15 && $row['metrics']['points'] < 18) || ($row['metrics']['points'] >= 26 && $row['metrics']['points'] < 30))
                ->map(fn (array $row): array => ['name' => $row['user']->name, 'points' => $row['metrics']['points'], 'next' => $row['metrics']['points'] < 18 ? 18 : 30])->values()->all(),
            'teamOnboarded' => $team->sum(fn (array $row): int => $row['metrics']['onboarded']),
        ];

        if (! array_filter([$alert['inactive'], $alert['behind'], $alert['stalled'], $alert['unattributed'], $alert['noTarget'], $alert['verificationBacklog'], $alert['reviewWarnings'], $alert['nearRetainer']])) {
            $this->components->info('Nothing needs attention today. No emails sent.');

            return self::SUCCESS;
        }

        $managers = User::query()->active()->permission(Permission::ViewTeamPerformance->value)->get();

        foreach ($managers as $manager) {
            Mail::to($manager->email)->queue(new ManagerDailyAlert($manager, $alert));
        }

        $this->components->info("Daily alert sent to {$managers->count()} managers.");

        return self::SUCCESS;
    }
}
