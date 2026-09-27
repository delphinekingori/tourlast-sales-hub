<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Hub-wide duplicate rule: before anyone records a property, look for it
 * in the Property Engagement Registry, in every salesperson's leads and in
 * tourlast.com signups, so two people never unknowingly pursue the same business.
 */
class PropertyDuplicateCheck
{
    public function __construct(private DuplicateEngagementFinder $registry) {}

    /**
     * @param  array{name?: ?string, trading_name?: ?string, city?: ?string, phones?: list<?string>, emails?: list<?string>, website?: ?string, registration_number?: ?string, kra_pin?: ?string}  $input
     * @return Collection<int, array{kind: string, key: string, name: string, location: ?string, owner: ?string, ownerIsViewer: bool, stage: string, lastContacted: ?CarbonInterface, reasons: list<string>, viewUrl: ?string, engagementId: ?int, leadId: ?int, active: bool, archived: bool}>
     */
    public function find(array $input, User $viewer, ?int $exceptLeadId = null, ?int $exceptEngagementId = null, int $limit = 6): Collection
    {
        $keys = MatchKeys::from($input);

        if ($keys->isEmpty()) {
            return collect();
        }

        $registry = $this->registry->find($input, $exceptEngagementId, $limit)
            ->map(fn (array $match) => $this->fromEngagement($match['engagement'], $match['reasons'], $viewer));

        $engagementIds = $registry->pluck('engagementId')->filter()->all();

        $leads = $this->leads($keys, $viewer, $exceptLeadId)
            ->reject(fn (array $match) => $match['engagementId'] && in_array($match['engagementId'], $engagementIds, true));

        $onboardings = $this->onboardings($keys, $viewer)
            ->reject(fn (array $match) => $match['engagementId'] && in_array($match['engagementId'], $engagementIds, true));

        return $registry->concat($leads)->concat($onboardings)
            ->sortByDesc(fn (array $match) => MatchKeys::score($match['reasons']) + ($match['active'] ? 1 : 0))
            ->take($limit)
            ->values();
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function fromEngagement(PropertyEngagement $engagement, array $reasons, User $viewer): array
    {
        return [
            'kind' => 'registry',
            'key' => 'registry-'.$engagement->id,
            'name' => $engagement->name,
            'location' => trim($engagement->locationLabel().', '.$engagement->country, ', '),
            'owner' => $engagement->salesRep?->name,
            'ownerIsViewer' => $engagement->sales_rep_id === $viewer->id,
            'stage' => $engagement->stage->label().' · '.$engagement->status->label(),
            'lastContacted' => $engagement->last_engaged_on,
            'reasons' => $reasons,
            'viewUrl' => $viewer->can('view', $engagement) ? route('registry.show', $engagement->id) : null,
            'engagementId' => $engagement->trashed() ? null : $engagement->id,
            'leadId' => null,
            'active' => ! $engagement->trashed() && $engagement->status->isOpen(),
            'archived' => $engagement->trashed(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function leads(MatchKeys $keys, User $viewer, ?int $exceptLeadId): Collection
    {
        $canSeeTeam = $viewer->can(Permission::ViewTeamPerformance->value);

        return Lead::query()
            ->with('user:id,name')
            ->when($exceptLeadId, fn (Builder $query) => $query->whereKeyNot($exceptLeadId))
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys->nameSets as $tokens) {
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'name_key'));
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'trading_name'));
                }

                if ($keys->exactName !== null) {
                    $query->orWhere('name_key', $keys->exactName);
                }

                if ($keys->phones !== []) {
                    $query->orWhereIn('phone_key', $keys->phones);
                }

                if ($keys->emails !== []) {
                    $query->orWhereIn('contact_email', $keys->emails);
                }

                if ($keys->website) {
                    $query->orWhere('website_key', $keys->website);
                }

                if ($keys->registration) {
                    $query->orWhere('registration_number', $keys->registration);
                }

                if ($keys->kraPin) {
                    $query->orWhere('kra_pin', $keys->kraPin);
                }
            })
            ->latest('updated_at')
            ->limit(60)
            ->get()
            ->map(function (Lead $lead) use ($keys, $viewer, $canSeeTeam): array {
                $mine = $lead->user_id === $viewer->id;

                return [
                    'kind' => 'lead',
                    'key' => 'lead-'.$lead->id,
                    'name' => $lead->business_name,
                    'location' => $lead->location,
                    'owner' => $lead->user->name,
                    'ownerIsViewer' => $mine,
                    'stage' => 'Lead · '.$lead->status->label(),
                    'lastContacted' => $lead->last_contacted_at ?? $lead->created_at,
                    'reasons' => $keys->reasons(
                        name: $lead->business_name.' | '.$lead->trading_name,
                        city: (string) $lead->location,
                        phones: array_filter([$lead->phone_key]),
                        emails: array_filter([$lead->contact_email]),
                        website: $lead->website_key,
                        registration: $lead->registration_number,
                        kraPin: $lead->kra_pin,
                    ),
                    'viewUrl' => $mine || $canSeeTeam ? route('leads.show', $lead) : null,
                    'engagementId' => $lead->property_engagement_id,
                    'leadId' => $lead->id,
                    'active' => $lead->isOpen(),
                    'archived' => false,
                ];
            })
            ->filter(fn (array $match) => $match['reasons'] !== [])
            ->values();
    }

    /**
     * tourlast.com signups: the property is already listing (or trying to).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function onboardings(MatchKeys $keys, User $viewer): Collection
    {
        return Onboarding::query()
            ->with(['user:id,name', 'propertyEngagement'])
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys->nameSets as $tokens) {
                    $query->orWhere(fn (Builder $name) => $keys->matchName($name, $tokens, 'property_name'));
                }

                foreach ($keys->phones as $phone) {
                    $query->orWhere('contact_phone', 'like', '%'.$phone);
                }

                if ($keys->emails !== []) {
                    $query->orWhereIn('contact_email', $keys->emails);
                }
            })
            ->latest('submitted_at')
            ->limit(40)
            ->get()
            ->map(fn (Onboarding $onboarding): array => [
                'kind' => 'onboarding',
                'key' => 'onboarding-'.$onboarding->id,
                'name' => $onboarding->property_name,
                'location' => $onboarding->location,
                'owner' => $onboarding->user?->name,
                'ownerIsViewer' => $onboarding->user_id === $viewer->id,
                'stage' => 'tourlast.com signup · '.$onboarding->status->label(),
                'lastContacted' => $onboarding->submitted_at,
                'reasons' => $keys->reasons(
                    name: $onboarding->property_name,
                    city: (string) $onboarding->location,
                    phones: array_filter([$onboarding->contact_phone]),
                    emails: array_filter([$onboarding->contact_email]),
                ),
                'viewUrl' => $onboarding->propertyEngagement && $viewer->can('view', $onboarding->propertyEngagement)
                    ? route('registry.show', $onboarding->property_engagement_id)
                    : null,
                'engagementId' => $onboarding->property_engagement_id,
                'leadId' => null,
                'active' => $onboarding->status->isAwaitingApproval() || $onboarding->status->isOnboarded(),
                'archived' => false,
            ])
            ->filter(fn (array $match) => $match['reasons'] !== [])
            ->values();
    }
}
