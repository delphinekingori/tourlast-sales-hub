<?php

namespace Database\Seeders;

use App\Incentives\Policy;
use App\Models\IncentivePolicy;
use Illuminate\Database\Seeder;

class IncentivePolicySeeder extends Seeder
{
    /**
     * Schedule 1 — Property and Experience Acquisition Incentive Policy, as version 1.
     * Safe to run again: it only creates the first version.
     */
    public function run(): void
    {
        IncentivePolicy::query()->firstOrCreate(
            ['name' => 'Schedule 1 — Property and Experience Acquisition Incentive Policy'],
            ['effective_from' => '2026-01-01', 'rules' => Policy::schedule1()],
        );
    }
}
