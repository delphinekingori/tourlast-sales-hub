<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('hub:purge-demo-data {--dry-run : Only list what would be deleted (this is the default)} {--force : Actually delete the demo data}')]
#[Description('Remove sample data left by DemoSeeder: @tourlast.test users and TL-##### providers, with the rows that depend on them')]
class PurgeDemoData extends Command
{
    private const DEMO_EMAIL_SUFFIX = '@tourlast.test';

    private const DEMO_PROPERTY_PATTERN = '/^TL-\d{5}$/';

    public function handle(): int
    {
        $plan = $this->plan();
        $delete = $this->option('force') && ! $this->option('dry-run');

        $this->components->info($delete ? 'Deleting demo data' : 'Dry run: nothing will be deleted');
        $this->table(['Demo data', 'Rows'], collect($plan['counts'])->map(fn (int $count, string $label): array => [$label, $count])->values()->all());

        if ($plan['detached'] > 0) {
            $this->components->warn("{$plan['detached']} real onboarding(s) are attributed to a demo salesperson. They are kept and become unattributed.");
        }

        if (! $delete) {
            $this->components->warn('Re-run with --force to delete these rows. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->components->warn('This permanently deletes data. Take a database backup before running this against a real database.');

        DB::transaction(fn () => $this->delete($plan['ids']));

        $this->components->info('Demo data deleted.');

        if (! User::query()->exists()) {
            $this->components->warn('No users remain. Create the first admin with: php artisan hub:create-super-admin');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{ids: array<string, list<int>>, counts: array<string, int>, detached: int}
     */
    private function plan(): array
    {
        $userIds = DB::table('users')->where('email', 'like', '%'.self::DEMO_EMAIL_SUFFIX)->pluck('email', 'id')
            ->filter(fn (string $email): bool => str_ends_with(mb_strtolower($email), self::DEMO_EMAIL_SUFFIX))->keys()->all();

        $onboardingIds = $this->demoPropertyRows('onboardings', 'tourlast_property_id');
        $sandboxIds = $this->demoPropertyRows('sandbox_providers', 'property_id');

        $engagementIds = DB::table('property_engagements')
            ->where(fn ($query) => $query->whereIn('created_by', $userIds)->orWhereIn('id', $this->demoPropertyRows('property_engagements', 'tourlast_property_id')))
            ->pluck('id')->all();

        $ids = [
            'users' => $userIds,
            'invitations' => DB::table('invitations')->where('email', 'like', '%'.self::DEMO_EMAIL_SUFFIX)->pluck('id')->all(),
            'leads' => DB::table('leads')->whereIn('user_id', $userIds)->pluck('id')->all(),
            'property_engagements' => $engagementIds,
            'onboardings' => $onboardingIds,
            'partner_accounts' => DB::table('onboardings')->whereIn('id', $onboardingIds)->whereNotNull('partner_account_id')->pluck('partner_account_id')->unique()->values()->all(),
            'sandbox_providers' => $sandboxIds,
        ];

        $detached = DB::table('onboardings')->whereNull('deleted_at')->whereNotIn('id', $onboardingIds)->whereIn('user_id', $userIds)->count();

        return [
            'ids' => $ids,
            'counts' => [
                '@tourlast.test users' => count($ids['users']),
                'Invitations to @tourlast.test addresses' => count($ids['invitations']),
                'Leads of those users' => count($ids['leads']),
                'Registry properties' => count($ids['property_engagements']),
                'TL-##### onboardings' => count($ids['onboardings']),
                'Accounts created from those onboardings' => count($ids['partner_accounts']),
                'TL-##### sandbox providers' => count($ids['sandbox_providers']),
            ],
            'detached' => $detached,
        ];
    }

    /**
     * Ids of rows whose property id is exactly TL- followed by five digits, trashed rows included.
     *
     * @return list<int>
     */
    private function demoPropertyRows(string $table, string $column): array
    {
        return DB::table($table)->where($column, 'like', 'TL-%')->pluck($column, 'id')
            ->filter(fn (string $propertyId): bool => preg_match(self::DEMO_PROPERTY_PATTERN, $propertyId) === 1)->keys()->all();
    }

    /**
     * Children go before parents because several foreign keys restrict deletes.
     *
     * @param  array<string, list<int>>  $ids
     */
    private function delete(array $ids): void
    {
        $users = $ids['users'];
        $roleTables = config('permission.table_names');

        DB::table('lead_transfers')->where(fn ($query) => $query->whereIn('lead_id', $ids['leads'])->orWhereIn('from_user_id', $users)->orWhereIn('to_user_id', $users)->orWhereIn('transferred_by', $users))->delete();
        DB::table('activities')->where(fn ($query) => $query->whereIn('lead_id', $ids['leads'])->orWhereIn('user_id', $users))->delete();
        DB::table('follow_ups')->where(fn ($query) => $query->whereIn('lead_id', $ids['leads'])->orWhereIn('user_id', $users))->delete();
        DB::table('leads')->whereIn('id', $ids['leads'])->delete();

        DB::table('claim_approvals')->whereIn('user_id', $users)->delete();
        DB::table('expense_claims')->whereIn('user_id', $users)->delete();

        DB::table('property_engagement_reps')->where(fn ($query) => $query->whereIn('property_engagement_id', $ids['property_engagements'])->orWhereIn('user_id', $users))->delete();
        DB::table('property_engagements')->whereIn('id', $ids['property_engagements'])->delete();

        DB::table('attribution_changes')->where(fn ($query) => $query->whereIn('onboarding_id', $ids['onboardings'])->orWhereIn('to_user_id', $users)->orWhereIn('changed_by', $users))->delete();
        DB::table('onboardings')->whereIn('id', $ids['onboardings'])->delete();
        DB::table('partner_accounts')->whereIn('id', $ids['partner_accounts'])->whereNotIn('id', DB::table('onboardings')->whereNotNull('partner_account_id')->select('partner_account_id'))->delete();
        DB::table('sandbox_providers')->whereIn('id', $ids['sandbox_providers'])->delete();

        DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $users)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $users)->delete();
        DB::table($roleTables['model_has_roles'])->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $users)->delete();
        DB::table($roleTables['model_has_permissions'])->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $users)->delete();
        DB::table('referral_codes')->whereIn('user_id', $users)->delete();
        DB::table('invitations')->where(fn ($query) => $query->whereIn('id', $ids['invitations'])->orWhereIn('invited_by', $users))->delete();

        DB::table('users')->whereIn('id', $users)->delete();
    }
}
