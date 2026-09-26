<?php

namespace App\Console\Commands;

use App\Incentives\Statements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:generate-statements {month? : YYYY-MM, defaults to last month}')]
#[Description('Create or refresh draft incentive statements for a month')]
class GenerateStatements extends Command
{
    public function handle(Statements $statements): int
    {
        $month = $this->argument('month') && preg_match('/^\d{4}-\d{2}$/', $this->argument('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $this->argument('month'))
            : CarbonImmutable::now()->startOfMonth()->subMonth();

        $count = $statements->generate($month)->count();

        $this->components->info("{$count} draft statements ready for {$month->format('F Y')}. Payment due by {$statements->paymentDueOn($month)->format('j F Y')}.");

        return self::SUCCESS;
    }
}
