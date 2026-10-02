<?php

namespace App\Enums;

/**
 * What an API token may be used for. A request needs the token's scope AND
 * the token owner's permission in the Hub, so a scope never grants more than
 * the person could do in the web app.
 */
enum ApiScope: string
{
    case Profile = 'profile';
    case LeadsRead = 'leads:read';
    case LeadsWrite = 'leads:write';
    case ScheduleRead = 'schedule:read';
    case ScheduleWrite = 'schedule:write';
    case RegistryRead = 'registry:read';
    case RegistryWrite = 'registry:write';
    case OnboardingsRead = 'onboardings:read';
    case OnboardingsWrite = 'onboardings:write';
    case IncentivesRead = 'incentives:read';
    case IncentivesWrite = 'incentives:write';
    case ClaimsRead = 'claims:read';
    case ClaimsWrite = 'claims:write';
    case TeamRead = 'team:read';
    case TeamWrite = 'team:write';
    case NotificationsRead = 'notifications:read';
    case NotificationsWrite = 'notifications:write';
    case ReportsRead = 'reports:read';
    case IntegrationRead = 'integration:read';
    case IntegrationPush = 'integration:push';

    public function label(): string
    {
        return match ($this) {
            self::Profile => 'Own profile, target, payment details and dashboard',
            self::LeadsRead => 'Read leads and activities',
            self::LeadsWrite => 'Create and update leads, log activities, mark lost, transfer',
            self::ScheduleRead => 'Read the schedule and calendar',
            self::ScheduleWrite => 'Schedule, edit and complete calls, meetings and visits',
            self::RegistryRead => 'Search and read the Property Engagement Registry',
            self::RegistryWrite => 'Add and change registry records (managers)',
            self::OnboardingsRead => 'Read tourlast.com onboardings',
            self::OnboardingsWrite => 'Assign unattributed signups (admins)',
            self::IncentivesRead => 'Read earnings, partner accounts and statements',
            self::IncentivesWrite => 'Verify accounts, approve and pay statements',
            self::ClaimsRead => 'Read claims and approvals',
            self::ClaimsWrite => 'Submit, approve, reject and disburse claims',
            self::TeamRead => 'Read people, team performance and targets',
            self::TeamWrite => 'Invite, suspend, fire, reinstate and delete accounts',
            self::NotificationsRead => 'Read notifications and announcements',
            self::NotificationsWrite => 'Mark notifications read, publish announcements',
            self::ReportsRead => 'Insights and Excel/PDF exports',
            self::IntegrationRead => 'Read the tourlast.com sync log',
            self::IntegrationPush => 'Run a tourlast.com sync now (Hub admin)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope) => $scope->value, self::cases());
    }
}
