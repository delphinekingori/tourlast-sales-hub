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
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use App\Enums\Travel\TravelTargetMetric;
use App\Enums\Travel\TripStatus;
use App\Models\Announcement;
use App\Models\LeadTransfer;
use App\Models\Package;
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
            'travel' => [
                'provider_types' => $options(TravelProviderType::cases()),
                'provider_statuses' => $options(TravelProviderStatus::cases()),
                'contract_statuses' => $options(ContractStatus::cases()),
                'package_types' => $pairs(Package::Types),
                'package_statuses' => $options(PackageStatus::cases()),
                'package_version_statuses' => $options(PackageVersionStatus::cases()),
                'approval_decisions' => $options(ApprovalDecision::cases()),
                'departure_statuses' => $options(DepartureStatus::cases()),
                'trip_statuses' => $options(TripStatus::cases()),
                'booking_statuses' => $options(TravelBookingStatus::cases()),
                'booking_payment_statuses' => $options(BookingPaymentStatus::cases()),
                'payment_methods' => $options(PaymentMethod::cases()),
                'payment_statuses' => $options(PaymentStatus::cases()),
                'activity_types' => $options(ActivityType::forTravel()),
                'target_metrics' => $options(TravelTargetMetric::cases()),
            ],
            'termination_reasons' => $pairs(AccountStatus::terminationReasons()),
            'announcement_audiences' => collect(Announcement::Audiences)->map(fn (array $group, string $key) => ['value' => $key, 'label' => $group['label']])->values(),
        ]]);
    }
}
