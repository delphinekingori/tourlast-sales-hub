<?php

namespace App\Livewire\Insights;

use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Why properties say no to Tourlast: objections, competitors, who is losing
 * what, and which lost properties are coming back round.
 */
#[Title('Lost & objections')]
class Objections extends Component
{
    public const Periods = ['90' => 'Last 90 days', 'quarter' => 'This quarter', 'year' => 'This year', 'all' => 'All time'];

    #[Url]
    public string $period = 'year';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewTeamPerformance->value) || Auth::user()->can(Permission::ManageEngagementRegistry->value), 403);

        if (! array_key_exists($this->period, self::Periods)) {
            $this->period = 'year';
        }
    }

    /**
     * Limit everything to one salesperson (null = the whole team).
     */
    protected function scopeUserId(): ?int
    {
        return null;
    }

    protected function viewName(): string
    {
        return 'livewire.insights.objections';
    }

    public function render(): View
    {
        $since = match ($this->period) {
            '90' => CarbonImmutable::now()->subDays(90)->startOfDay(),
            'quarter' => CarbonImmutable::now()->startOfQuarter(),
            'year' => CarbonImmutable::now()->startOfYear(),
            default => null,
        };

        $losses = $this->losses($since);
        $explained = $losses->filter(fn (array $loss) => $loss['objection'] !== null);
        $byObjection = $explained->countBy(fn (array $loss) => $loss['objection']->value)->sortDesc();
        $competitorLosses = $explained->filter(fn (array $loss) => $loss['objection']->needsCompetitor() || $loss['competitor']);

        return view($this->viewName(), [
            'losses' => $losses,
            'byObjection' => $byObjection,
            'byCompetitor' => $explained->filter(fn (array $loss) => $loss['competitor'])->countBy(fn (array $loss) => $loss['competitor'])->sortDesc(),
            'byRep' => $losses->groupBy(fn (array $loss) => $loss['rep'] ?? 'Unassigned')->map(fn (Collection $group) => [
                'count' => $group->count(),
                'top' => $group->filter(fn (array $loss) => $loss['objection'])->countBy(fn (array $loss) => $loss['objection']->label())->sortDesc()->keys()->first(),
            ])->sortByDesc('count'),
            'byType' => $losses->countBy(fn (array $loss) => $loss['type'])->sortDesc(),
            'unexplained' => $losses->count() - $explained->count(),
            'competitorShare' => $losses->isNotEmpty() ? (int) round($competitorLosses->count() / $losses->count() * 100) : 0,
            'topObjection' => $byObjection->keys()->first() ? Objection::from($byObjection->keys()->first()) : null,
            'upcoming' => $this->upcoming(),
        ]);
    }

    /**
     * Lost leads and lost/rejected registry records in the period. A lead
     * whose registry property is also counted is not counted twice.
     *
     * @return Collection<int, array{kind: string, name: string, type: string, rep: ?string, objection: ?Objection, competitor: ?string, notes: ?string, date: ?CarbonImmutable, url: string}>
     */
    private function losses(?CarbonImmutable $since): Collection
    {
        $registry = PropertyEngagement::query()
            ->with('salesRep:id,name')
            ->whereIn('status', [EngagementStatus::Lost, EngagementStatus::Rejected])
            ->when($this->scopeUserId(), fn ($query, $userId) => $query->where('sales_rep_id', $userId))
            ->when($since, fn ($query) => $query->where(fn ($inner) => $inner->where('closed_at', '>=', $since)->orWhere(fn ($legacy) => $legacy->whereNull('closed_at')->where('updated_at', '>=', $since))))
            ->get()
            ->map(fn (PropertyEngagement $engagement) => [
                'kind' => 'registry',
                'id' => $engagement->id,
                'name' => $engagement->name,
                'type' => $engagement->propertyTypeLabel(),
                'rep' => $engagement->salesRep?->name,
                'objection' => $engagement->objection,
                'competitor' => $engagement->competitor,
                'notes' => $engagement->outcome_notes,
                'date' => $engagement->closed_at?->toImmutable() ?? $engagement->updated_at->toImmutable(),
                'url' => route('registry.show', $engagement->id),
            ]);

        $counted = $registry->pluck('id')->all();

        $leads = Lead::query()
            ->with('user:id,name')
            ->where('status', LeadStatus::Lost)
            ->when($this->scopeUserId(), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($since, fn ($query) => $query->where(fn ($inner) => $inner->where('lost_at', '>=', $since)->orWhere(fn ($legacy) => $legacy->whereNull('lost_at')->where('updated_at', '>=', $since))))
            ->where(fn ($query) => $query->whereNull('property_engagement_id')->orWhereNotIn('property_engagement_id', $counted))
            ->get()
            ->map(fn (Lead $lead) => [
                'kind' => 'lead',
                'id' => $lead->id,
                'name' => $lead->business_name,
                'type' => $lead->propertyTypeLabel(),
                'rep' => $lead->user->name,
                'objection' => $lead->objection,
                'competitor' => $lead->competitor,
                'notes' => $lead->lost_notes ?? ($lead->objection ? null : $lead->lost_reason),
                'date' => $lead->lost_at?->toImmutable() ?? $lead->updated_at->toImmutable(),
                'url' => route('leads.show', $lead),
            ]);

        return $registry->concat($leads)->sortByDesc(fn (array $loss) => $loss['date']?->getTimestamp())->values();
    }

    /**
     * Lost or paused properties with a re-engage date in the next 120 days.
     *
     * @return Collection<int, array{name: string, rep: ?string, objection: ?Objection, date: CarbonImmutable, lost: ?CarbonImmutable, url: string}>
     */
    private function upcoming(): Collection
    {
        $until = now()->addDays(120)->toDateString();

        $registry = PropertyEngagement::query()->with('salesRep:id,name')
            ->when($this->scopeUserId(), fn ($query, $userId) => $query->where('sales_rep_id', $userId))
            ->whereNotNull('reengage_on')->whereDate('reengage_on', '<=', $until)
            ->get()
            ->map(fn (PropertyEngagement $engagement) => [
                'name' => $engagement->name, 'rep' => $engagement->salesRep?->name, 'objection' => $engagement->objection,
                'date' => $engagement->reengage_on->toImmutable(), 'lost' => $engagement->closed_at?->toImmutable(), 'url' => route('registry.show', $engagement->id),
            ]);

        $leads = Lead::query()->with('user:id,name')
            ->when($this->scopeUserId(), fn ($query, $userId) => $query->where('user_id', $userId))
            ->where('status', LeadStatus::Lost)->whereNotNull('reengage_on')->whereDate('reengage_on', '<=', $until)
            ->get()
            ->map(fn (Lead $lead) => [
                'name' => $lead->business_name, 'rep' => $lead->user->name, 'objection' => $lead->objection,
                'date' => $lead->reengage_on->toImmutable(), 'lost' => $lead->lost_at?->toImmutable(), 'url' => route('leads.show', $lead),
            ]);

        return $registry->concat($leads)->sortBy(fn (array $item) => $item['date']->getTimestamp())->values();
    }
}
