<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

class Navigation
{
    /**
     * The sidebar sections the user is allowed to see.
     *
     * @return list<array{label: string, items: list<array{label: string, icon: string, route: string, params: array<string, string>, active: bool}>}>
     */
    public static function for(User $user): array
    {
        $sections = [];
        $sells = (bool) $user->role()?->earnsReferrals();

        if ($sells) {
            $sections[] = ['label' => 'Me', 'items' => [
                self::item('My Progress', 'chart', 'dashboard'),
                self::item('My Earnings', 'target', 'earnings.mine'),
                self::item('My Accounts', 'building', 'accounts.*', activeOn: ['accounts.*']),
                self::item('My Onboardings', 'register', 'onboardings.mine'),
                self::item('Leads', 'users', 'leads.*'),
                self::item('My losses', 'chart', 'insights.mine'),
                self::item('Calendar', 'calendar', 'calendar.index'),
                self::item('Activities', 'activity', 'activities.index'),
                self::item('Claims', 'clipboard', 'claims.index'),
                self::item('Notifications', 'mail', 'notifications.index'),
            ]];
        } else {
            $sections[] = ['label' => 'Overview', 'items' => [
                self::item('Home', 'home', 'dashboard'),
                self::item('Notifications', 'mail', 'notifications.index'),
            ]];
        }

        $acquisition = [];

        if ($user->can(Permission::ViewEngagementRegistry->value)) {
            $acquisition[] = self::item('Property Engagement Registry', 'building', 'registry.*', activeOn: ['registry.*']);
        }

        if ($sells) {
            $acquisition[] = self::item('Referral Center', 'link', 'referrals.index');
        }

        if ($acquisition !== []) {
            $sections[] = ['label' => 'Property acquisition', 'items' => $acquisition];
        }

        $management = [];

        if ($user->can(Permission::ViewTeamPerformance->value)) {
            $management[] = self::item('Team Performance', 'users', 'team.performance', activeOn: ['team.performance', 'team.member']);
            $management[] = self::item('Targets', 'target', 'team.targets');

            if (! $sells) {
                $management[] = self::item('Leads', 'clipboard', 'leads.*');
                $management[] = self::item('Team calendar', 'calendar', 'calendar.index');
            }
        }

        if ($user->can(Permission::ViewTeamPerformance->value) || $user->can(Permission::ManageEngagementRegistry->value)) {
            $management[] = self::item('Lost & objections', 'chart', 'insights.objections');
        }

        if ($user->can(Permission::VerifyAccounts->value)) {
            $management[] = self::item('Verification', 'check-circle', 'accounts.index', ['tab' => 'verify'], activeOn: []);
        }

        if (! $sells && ($user->can(Permission::ViewTeamPerformance->value) || $user->can(Permission::ViewTeamEarnings->value))) {
            $management[] = self::item('Partner Accounts', 'building', 'accounts.*');
        }

        if ($user->can(Permission::ViewPresence->value)) {
            $management[] = self::item('People', 'users', 'people.*', activeOn: ['people.*']);
        }

        if ($user->can(Permission::ManageUsers->value)) {
            $management[] = self::item('Unattributed', 'alert', 'onboardings.unattributed');
        }

        if ($management !== []) {
            $sections[] = ['label' => 'Management', 'items' => $management];
        }

        $finance = [];

        if ($user->can(Permission::ApproveClaimsManager->value) || $user->can(Permission::ApproveClaimsHr->value) || $user->can(Permission::ApproveClaimsFinance->value)) {
            $finance[] = self::item('Claim approvals', 'check', 'claims.approvals');
        }

        if ($user->can(Permission::ViewTeamEarnings->value)) {
            $finance[] = self::item('Payouts', 'register', 'payouts.index');
        }

        if ($user->can(Permission::ViewPaymentDetails->value)) {
            $finance[] = self::item('Payment details', 'lock', 'payment-details.index');
        }

        if ($user->can(Permission::ViewPartnerRegister->value)) {
            $finance[] = self::item('Partner Register', 'register', 'partners.index');
        }

        if ($finance !== []) {
            $sections[] = ['label' => 'Partners & pay', 'items' => $finance];
        }

        $admin = [];

        if ($user->can(Permission::InviteSalespeople->value)) {
            $admin[] = self::item('Users & Invites', 'user', 'team.index');
        }

        if ($user->can(Permission::ManageAgreements->value)) {
            $admin[] = self::item('Incentives', 'cog', 'admin.incentives');
        }

        if ($user->can(Permission::ManageIntegration->value)) {
            $admin[] = self::item('Integration', 'link', 'admin.integration');
        }

        if ($user->can(Permission::ManageApiTokens->value)) {
            $admin[] = self::item('API tokens', 'lock', 'admin.api-tokens');
        }

        if ($admin !== []) {
            $sections[] = ['label' => 'Admin', 'items' => $admin];
        }

        return $sections;
    }

    /**
     * Section and item label of the page being viewed, for the top-bar breadcrumb.
     *
     * @param  list<array{label: string, items: list<array{label: string, active: bool}>}>  $sections
     * @return array{section: string, page: string}|null
     */
    public static function crumb(array $sections): ?array
    {
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    return ['section' => $section['label'], 'page' => $item['label']];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $params
     * @param  list<string>|null  $activeOn
     * @return array{label: string, icon: string, route: string, params: array<string, string>, active: bool}
     */
    private static function item(string $label, string $icon, string $route, array $params = [], ?array $activeOn = null): array
    {
        $target = str_ends_with($route, '.*') ? str_replace('.*', '.index', $route) : $route;
        $patterns = $activeOn ?? [$route];

        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $target,
            'params' => $params,
            'active' => $patterns !== [] && request()->routeIs(...$patterns),
        ];
    }
}
