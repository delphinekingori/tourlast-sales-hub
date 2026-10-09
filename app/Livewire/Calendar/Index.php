<?php

namespace App\Livewire\Calendar;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\DepartureStatus;
use App\Models\FollowUp;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Day, week and month view of scheduled calls, meetings and visits.
 * Salespeople see their own; managers see the team. Travel salespeople see
 * their own items plus departures of their packages; Sales Managers never see
 * travel salespeople (Travel managers do).
 */
#[Title('Calendar')]
class Index extends Component
{
    #[Url]
    public string $view = 'week';

    #[Url]
    public string $date = '';

    /** '' = mine (salespeople) or everyone (managers), 'team', or a user id. */
    #[Url]
    public string $rep = '';

    public function mount(): void
    {
        abort_unless($this->sells() || $this->seesTeam() || $this->travels(), 403);

        if (! in_array($this->view, ['day', 'week', 'month'], true)) {
            $this->view = 'week';
        }

        if ($this->date === '' || ! strtotime($this->date)) {
            $this->date = now()->toDateString();
        }
    }

    public function move(int $steps): void
    {
        $date = CarbonImmutable::parse($this->date);

        $moved = match ($this->view) {
            'day' => $date->addDays($steps),
            'month' => $date->addMonthsNoOverflow($steps),
            default => $date->addWeeks($steps),
        };

        $this->date = $moved->toDateString();
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
    }

    public function showDay(string $date): void
    {
        $this->view = 'day';
        $this->date = CarbonImmutable::parse($date)->toDateString();
    }

    #[On('schedule-saved')]
    public function refreshSchedule(): void
    {
        // Re-render with the saved item.
    }

    public function render(): View
    {
        [$from, $to] = $this->range();
        $items = $this->items($from, $to);
        $departures = $this->departures($from, $to);

        return view('livewire.calendar.index', [
            'from' => $from,
            'to' => $to,
            'anchor' => CarbonImmutable::parse($this->date),
            'days' => collect(range(0, (int) $from->diffInDays($to)))->map(fn (int $offset) => $from->addDays($offset)),
            'itemsByDay' => $items->groupBy(fn (FollowUp $item) => $item->due_at->toDateString()),
            'departuresByDay' => $departures->groupBy(fn (PackageDeparture $departure) => $departure->starts_on->toDateString()),
            'total' => $items->count(),
            'meetings' => $items->filter(fn (FollowUp $item) => $item->isMeeting())->count(),
            'salespeople' => $this->seesTeam() ? $this->teamQuery()->active()->orderBy('name')->get(['id', 'name']) : collect(),
            'sells' => $this->sells(),
            'travels' => $this->travels(),
            'travelView' => $this->travels() || $departures->isNotEmpty(),
            'seesTeam' => $this->seesTeam(),
            'showOwner' => $this->scopeUserId() === null,
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(): array
    {
        $date = CarbonImmutable::parse($this->date);

        return match ($this->view) {
            'day' => [$date->startOfDay(), $date->startOfDay()],
            'month' => [$date->startOfMonth()->startOfWeek(), $date->endOfMonth()->endOfWeek()->startOfDay()],
            default => [$date->startOfWeek(), $date->endOfWeek()->startOfDay()],
        };
    }

    /**
     * @return Collection<int, FollowUp>
     */
    private function items(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $userId = $this->scopeUserId();

        return FollowUp::query()
            ->with(['lead:id,business_name,location,property_engagement_id', 'user:id,name,avatar_path', 'subject'])
            ->whereBetween('due_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when(! $userId, fn ($query) => $query->whereIn('user_id', $this->teamQuery()->select('id')))
            ->chronological()
            ->get();
    }

    /**
     * Package departures for travel calendars: the scoped travel salesperson's
     * packages, or every package for a Travel manager viewing the whole team.
     *
     * @return Collection<int, PackageDeparture>
     */
    private function departures(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $userId = $this->scopeUserId();
        $viewer = Auth::user();
        $scoped = $userId ? User::find($userId) : null;

        $show = match (true) {
            $scoped !== null => $scoped->isTravelSalesperson() && ($scoped->is($viewer) || TravelAccess::managesAll($viewer)),
            default => TravelAccess::managesAll($viewer),
        };

        if (! $show) {
            return collect();
        }

        return PackageDeparture::query()
            ->with('package:id,name,owner_id')
            ->withSlotCounts()
            ->where('status', '!=', DepartureStatus::Cancelled)
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->when($userId, fn ($query) => $query->whereHas('package', fn ($package) => $package->where('owner_id', $userId)))
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * People whose calendars managers can open: property sellers, plus travel
     * salespeople for Travel managers (never for Sales Managers).
     *
     * @return Builder<User>
     */
    private function teamQuery(): Builder
    {
        return User::query()->where(fn (Builder $query) => $query
            ->whereIn('id', User::query()->sellers()->select('id'))
            ->when(TravelAccess::managesAll(Auth::user()), fn (Builder $query) => $query->orWhereIn('id', User::query()->role(Role::TravelSalesperson->value)->select('id'))));
    }

    /**
     * Whose calendar is shown: a user id, or null for the whole team.
     */
    private function scopeUserId(): ?int
    {
        if (! $this->seesTeam()) {
            return Auth::id();
        }

        return match (true) {
            $this->rep === 'team' => null,
            $this->rep === '' => $this->sells() || $this->travels() ? Auth::id() : null,
            $this->teamQuery()->whereKey((int) $this->rep)->exists() => (int) $this->rep,
            default => Auth::id(),
        };
    }

    private function sells(): bool
    {
        return (bool) Auth::user()->role()?->earnsReferrals();
    }

    private function travels(): bool
    {
        return Auth::user()->isTravelSalesperson();
    }

    private function seesTeam(): bool
    {
        return Auth::user()->can(Permission::ViewTeamPerformance->value);
    }
}
