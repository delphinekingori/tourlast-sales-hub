<?php

namespace App\Console\Commands;

use App\Actions\ChangeAccountStatus;
use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:reinstate-suspensions')]
#[Description('Lift suspensions whose end date has arrived')]
class ReinstateSuspensions extends Command
{
    public function handle(ChangeAccountStatus $change): int
    {
        $due = User::query()
            ->where('account_status', AccountStatus::Suspended)
            ->whereNotNull('suspended_until')
            ->whereDate('suspended_until', '<=', now()->toDateString())
            ->get();

        foreach ($due as $user) {
            $change->reinstate($user, null, 'Suspension ended automatically.');
        }

        $this->components->info("{$due->count()} suspension(s) lifted.");

        return self::SUCCESS;
    }
}
