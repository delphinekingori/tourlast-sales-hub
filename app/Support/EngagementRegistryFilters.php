<?php

namespace App\Support;

use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters shared by the Property Engagement Registry screen, its Excel export
 * and its management report.
 */
final readonly class EngagementRegistryFilters
{
    /**
     * Sortable columns and what they sort by.
     */
    public const Sorts = ['name', 'type', 'location', 'stage', 'status', 'rep', 'first', 'last'];

    /**
     * Work that needs a manager's eye. Each applies to properties still being worked.
     */
    public const Attention = [
        'overdue' => 'Next action overdue',
        'idle' => 'No contact for a while',
        'no_action' => 'No next action planned',
        'unassigned' => 'No salesperson',
    ];

    public function __construct(
        public string $search = '',
        public ?string $type = null,
        public ?string $country = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?int $salespersonId = null,
        public ?EngagementStage $stage = null,
        public ?EngagementStatus $status = null,
        public ?EngagementSource $source = null,
        public ?CarbonImmutable $firstFrom = null,
        public ?CarbonImmutable $firstTo = null,
        public ?CarbonImmutable $lastFrom = null,
        public ?CarbonImmutable $lastTo = null,
        public ?string $activity = null,
        public ?string $onboarded = null,
        public ?string $attention = null,
        public bool $archived = false,
        public string $sort = 'last',
        public string $direction = 'desc',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $date = fn ($value): ?CarbonImmutable => filled($value) && strtotime((string) $value) ? CarbonImmutable::parse($value) : null;
        $text = fn ($value): ?string => filled($value) ? trim((string) $value) : null;

        return new self(
            search: trim((string) ($input['q'] ?? '')),
            type: array_key_exists($input['type'] ?? '', config('hub.property_types')) ? $input['type'] : null,
            country: $text($input['country'] ?? null),
            region: $text($input['region'] ?? null),
            city: $text($input['city'] ?? null),
            salespersonId: filled($input['rep'] ?? null) ? (int) $input['rep'] : null,
            stage: EngagementStage::tryFrom((string) ($input['stage'] ?? '')),
            status: EngagementStatus::tryFrom((string) ($input['status'] ?? '')),
            source: EngagementSource::tryFrom((string) ($input['source'] ?? '')),
            firstFrom: $date($input['first_from'] ?? null)?->startOfDay(),
            firstTo: $date($input['first_to'] ?? null)?->endOfDay(),
            lastFrom: $date($input['last_from'] ?? null)?->startOfDay(),
            lastTo: $date($input['last_to'] ?? null)?->endOfDay(),
            activity: in_array($input['activity'] ?? null, ['active', 'inactive'], true) ? $input['activity'] : null,
            onboarded: in_array($input['onboarded'] ?? null, ['yes', 'no'], true) ? $input['onboarded'] : null,
            attention: array_key_exists($input['attention'] ?? '', self::Attention) ? $input['attention'] : null,
            archived: filter_var($input['archived'] ?? false, FILTER_VALIDATE_BOOL),
            sort: in_array($input['sort'] ?? null, self::Sorts, true) ? $input['sort'] : 'last',
            direction: ($input['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return Builder<PropertyEngagement>
     */
    public function query(): Builder
    {
        $query = PropertyEngagement::query()
            ->with(['salesRep:id,name,avatar_path', 'primaryContact'])
            ->when($this->archived, fn (Builder $query) => $query->onlyTrashed())
            ->search($this->search)
            ->when($this->type, fn (Builder $query) => $query->where('property_type', $this->type))
            ->when($this->country, fn (Builder $query) => $query->where('country', $this->country))
            ->when($this->region, fn (Builder $query) => $query->where('region', $this->region))
            ->when($this->city, fn (Builder $query) => $query->where('city', $this->city))
            ->when($this->salespersonId, fn (Builder $query) => $query->where('sales_rep_id', $this->salespersonId))
            ->when($this->stage, fn (Builder $query) => $query->where('stage', $this->stage))
            ->when($this->status, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->source, fn (Builder $query) => $query->where('source', $this->source))
            ->when($this->firstFrom, fn (Builder $query) => $query->where('first_engaged_on', '>=', $this->firstFrom->toDateString()))
            ->when($this->firstTo, fn (Builder $query) => $query->where('first_engaged_on', '<=', $this->firstTo->toDateString()))
            ->when($this->lastFrom, fn (Builder $query) => $query->where('last_engaged_on', '>=', $this->lastFrom->toDateString()))
            ->when($this->lastTo, fn (Builder $query) => $query->where('last_engaged_on', '<=', $this->lastTo->toDateString()))
            ->when($this->activity === 'active', fn (Builder $query) => $query->whereIn('status', self::openStatuses()))
            ->when($this->activity === 'inactive', fn (Builder $query) => $query->whereNotIn('status', self::openStatuses()))
            ->when($this->onboarded === 'yes', fn (Builder $query) => $query->where('stage', EngagementStage::Live))
            ->when($this->onboarded === 'no', fn (Builder $query) => $query->where('stage', '!=', EngagementStage::Live))
            ->when($this->attention, fn (Builder $query) => self::needingAttention($query, $this->attention));

        return $this->applySort($query);
    }

    /**
     * Whether any filter other than search and sort is applied.
     */
    public function hasFilters(): bool
    {
        return $this->type || $this->country || $this->region || $this->city || $this->salespersonId || $this->stage
            || $this->status || $this->source || $this->firstFrom || $this->firstTo || $this->lastFrom || $this->lastTo
            || $this->activity || $this->onboarded || $this->attention || $this->archived;
    }

    /**
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->search,
            'type' => $this->type,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'rep' => $this->salespersonId,
            'stage' => $this->stage?->value,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'first_from' => $this->firstFrom?->toDateString(),
            'first_to' => $this->firstTo?->toDateString(),
            'last_from' => $this->lastFrom?->toDateString(),
            'last_to' => $this->lastTo?->toDateString(),
            'activity' => $this->activity,
            'onboarded' => $this->onboarded,
            'attention' => $this->attention,
            'archived' => $this->archived ? 1 : null,
            'sort' => $this->sort,
            'dir' => $this->direction,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Narrow a query to the properties in one attention group.
     *
     * @param  Builder<PropertyEngagement>  $query
     * @return Builder<PropertyEngagement>
     */
    public static function needingAttention(Builder $query, string $group): Builder
    {
        $query->whereIn('status', self::openStatuses());

        return match ($group) {
            'overdue' => $query->whereDate('next_action_on', '<', today()),
            'idle' => $query->whereDate('last_engaged_on', '<=', today()->subDays(config('hub.stalled_after_days'))),
            'no_action' => $query->whereNull('next_action_on'),
            'unassigned' => $query->whereNull('sales_rep_id'),
            default => $query,
        };
    }

    /**
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return array_values(array_map(
            fn (EngagementStatus $status): string => $status->value,
            array_filter(EngagementStatus::cases(), fn (EngagementStatus $status): bool => $status->isOpen()),
        ));
    }

    /**
     * @param  Builder<PropertyEngagement>  $query
     * @return Builder<PropertyEngagement>
     */
    private function applySort(Builder $query): Builder
    {
        $direction = $this->direction;

        return match ($this->sort) {
            'name' => $query->orderBy('name', $direction),
            'type' => $query->orderBy('property_type', $direction)->orderBy('name'),
            'location' => $query->orderBy('city', $direction)->orderBy('name'),
            'stage' => $query->orderByRaw(self::stageOrderSql().' '.$direction)->orderBy('name'),
            'status' => $query->orderBy('status', $direction)->orderBy('name'),
            'rep' => $query->orderBy(User::query()->select('name')->whereColumn('users.id', 'property_engagements.sales_rep_id')->limit(1), $direction)->orderBy('name'),
            'first' => $query->orderBy('first_engaged_on', $direction)->orderBy('id', $direction),
            default => $query->orderBy('last_engaged_on', $direction)->orderBy('id', $direction),
        };
    }

    /**
     * CASE expression ordering stages by their place in the process.
     */
    private static function stageOrderSql(): string
    {
        $cases = collect(EngagementStage::cases())->map(fn (EngagementStage $stage) => "WHEN '{$stage->value}' THEN {$stage->order()}")->implode(' ');

        return "CASE stage {$cases} ELSE 99 END";
    }
}
