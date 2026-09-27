<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\ActivityType;
use App\Enums\ApiScope;
use App\Enums\EngagementEventType;
use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\LeadTransfer;
use App\Support\Period;
use Illuminate\Http\JsonResponse;

/**
 * Every option list the Hub uses, so apps can build forms and filters without
 * hard-coding values.
 */
class MetaController extends ApiController
{
    /**
     * GET /meta
     */
    public function __invoke(): JsonResponse
    {
        $options = fn (array $cases, ?callable $extra = null) => collect($cases)->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()] + ($extra ? $extra($case) : []))->values();
        $pairs = fn (array $map) => collect($map)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values();

        return response()->json(['data' => [
            'api_version' => 'v1',
            'currency' => 'KES',
            'timezone' => config('app.timezone'),
            'scopes' => $options(ApiScope::cases()),
            'roles' => $options(Role::cases()),
            'periods' => $pairs(Period::options()),
            'property_types' => $pairs(config('hub.property_types')),
            'accommodation_types' => config('hub.accommodation_types'),
            'star_ratings' => $pairs(config('hub.star_ratings')),
            'contact_titles' => config('hub.contact_titles'),
            'competitors' => config('hub.competitors'),
            'lead_statuses' => $options(LeadStatus::cases(), fn (LeadStatus $status) => ['manual' => in_array($status, LeadStatus::manual(), true)]),
            'activity_types' => $options(ActivityType::cases()),
            'onboarding_statuses' => $options(OnboardingStatus::cases()),
            'engagement_stages' => $options(EngagementStage::cases(), fn (EngagementStage $stage) => ['order' => $stage->order()]),
            'engagement_statuses' => $options(EngagementStatus::cases(), fn (EngagementStatus $status) => ['open' => $status->isOpen()]),
            'engagement_sources' => $options(EngagementSource::cases()),
            'engagement_log_types' => $options(EngagementEventType::interactions()),
            'objections' => $options(Objection::cases(), fn (Objection $objection) => ['needs_competitor' => $objection->needsCompetitor()]),
            'transfer_reasons' => $pairs(LeadTransfer::Reasons),
            'account_statuses' => $options(AccountStatus::cases()),
            'suspension_reasons' => $pairs(AccountStatus::suspensionReasons()),
            'termination_reasons' => $pairs(AccountStatus::terminationReasons()),
            'announcement_audiences' => collect(Announcement::Audiences)->map(fn (array $group, string $key) => ['value' => $key, 'label' => $group['label']])->values(),
        ]]);
    }
}
