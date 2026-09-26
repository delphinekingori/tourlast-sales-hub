<?php

namespace App\Livewire;

use App\Actions\IssueReferralCode;
use App\Actions\SetTarget;
use App\Enums\Permission;
use App\Enums\Role;
use App\Incentives\MonthlyEarnings;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\Target;
use App\Models\User;
use App\Support\Period;
use App\Support\SalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Home page. Salespeople see My Progress; managers can open the same view for
 * any salesperson from Team Performance. Other roles see a team overview.
 */
#[Title('Home')]
class Dashboard extends Component
{
    #[Locked]
    public ?int $subjectId = null;

    #[Url]
    public string $period = 'month';

    public bool $showTarget = false;

    public string $targetMonth = '';

    public ?int $targetValue = null;

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            abort_unless(Auth::user()->can(Permission::ViewTeamPerformance->value) || $user->is(Auth::user()), 403);
            abort_unless($user->role()?->earnsReferrals(), 404);
            $this->subjectId = $user->id;
        }

        $this->period = array_key_exists($this->period, Period::options()) ? $this->period : 'month';
    }

    public function openTarget(): void
    {
        abort_unless($this->isOwnProgress(), 403);

        $this->resetValidation();
        $options = $this->targetMonthOptions();
        $this->targetMonth = array_key_first($options) ?? '';
        $this->targetValue = $this->targetMonth
            ? Target::query()->where('user_id', Auth::id())->whereDate('month', $this->targetMonth)->value('target')
            : null;
        $this->showTarget = true;
    }

    public function updatedTargetMonth(): void
    {
        $this->targetValue = Target::query()->where('user_id', Auth::id())->whereDate('month', $this->targetMonth)->value('target');
    }

    public function saveTarget(SetTarget $setTarget): void
    {
        abort_unless($this->isOwnProgress(), 403);

        $this->validate([
            'targetMonth' => ['required', 'in:'.implode(',', array_keys($this->targetMonthOptions()))],
            'targetValue' => ['required', 'integer', 'min:1', 'max:500'],
        ], [], ['targetValue' => 'target', 'targetMonth' => 'month']);

        $month = CarbonImmutable::parse($this->targetMonth);
        $setTarget->handle(Auth::user(), $month, (int) $this->targetValue);

        $this->showTarget = false;
        $this->dispatch('toast', message: 'Your target for '.$month->format('F').' is '.$this->targetValue.'.');
    }

    public function render(SalesMetrics $metrics, IssueReferralCode $issueReferralCode, MonthlyEarnings $monthlyEarnings): View
    {
        $viewer = Auth::user();
        $subject = $this->subjectId ? User::findOrFail($this->subjectId) : $viewer;

        if (! $subject->role()?->earnsReferrals()) {
            return $this->home($viewer);
        }

        $period = Period::named($this->period);
        $currentMonth = CarbonImmutable::now()->startOfMonth();

        return view('livewire.progress', [
            'subject' => $subject,
            'isOwn' => $subject->is($viewer),
            'greeting' => $this->greeting(),
            'referralCode' => $issueReferralCode->handle($subject),
            'range' => $period,
            'metrics' => $metrics->forUser($subject, $period),
            'expected' => Gate::allows('view-earnings', $subject) && $monthlyEarnings->hasAgreement($subject, CarbonImmutable::now())
                ? $monthlyEarnings->for($subject, CarbonImmutable::now())->total()
                : null,
            'trend' => $metrics->monthlyTrend($subject),
            'currentTarget' => Target::query()->where('user_id', $subject->id)->whereDate('month', $currentMonth->toDateString())->value('target'),
            'targetLocked' => Target::isLockedFor($currentMonth),
            'lockDate' => Target::lockDateFor($currentMonth),
            'targetMonthOptions' => $this->targetMonthOptions(),
            'needsAttention' => $this->needsAttention($subject),
            'pipeline' => Lead::query()->where('user_id', $subject->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'today' => $this->todayCounts($subject),
            'schedule' => FollowUp::query()
                ->where('user_id', $subject->id)
                ->where(fn ($query) => $query
                    ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])
                    ->orWhere(fn ($overdue) => $overdue->whereNull('completed_at')->where('due_at', '<', now()->startOfDay())))
                ->with('lead:id,business_name,location')
                ->chronological()
                ->limit(12)
                ->get(),
            'approvedInPeriod' => Onboarding::query()->where('user_id', $subject->id)->whereBetween('approved_at', [$period->from, $period->to])->count(),
            'recentActivities' => Activity::query()->where('user_id', $subject->id)->with('lead')->latest('happened_at')->limit(6)->get(),
        ])->title($subject->is($viewer) ? 'My Progress' : $subject->name);
    }

    #[On('schedule-saved')]
    public function refreshSchedule(): void
    {
        // Re-render with the updated schedule.
    }

    /**
     * The "Today" strip: what is due today, meetings, anything overdue and
     * signups still going through onboarding.
     *
     * @return array{follow_ups: int, meetings: int, overdue: int, onboardings: int}
     */
    private function todayCounts(User $subject): array
    {
        $today = FollowUp::query()->open()->where('user_id', $subject->id)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
        $meetingTypes = array_map(fn ($type) => $type->value, FollowUp::meetingTypes());

        return [
            'follow_ups' => (clone $today)->whereNotIn('type', $meetingTypes)->count(),
            'meetings' => (clone $today)->whereIn('type', $meetingTypes)->count(),
            'overdue' => FollowUp::query()->open()->where('user_id', $subject->id)->where('due_at', '<', now()->startOfDay())->count(),
            'onboardings' => Onboarding::query()->where('user_id', $subject->id)->awaitingApproval()->count(),
        ];
    }

    /**
     * Months a salesperson may still set: this month until the lock day, and next month.
     *
     * @return array<string, string>
     */
    private function targetMonthOptions(): array
    {
        $options = [];

        foreach ([CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->startOfMonth()->addMonth()] as $month) {
            if (! Target::isLockedFor($month)) {
                $options[$month->toDateString()] = $month->format('F Y');
            }
        }

        return $options;
    }

    private function isOwnProgress(): bool
    {
        return $this->subjectId === null || $this->subjectId === Auth::id();
    }

    /**
     * Signups stuck in review and approved properties not yet live.
     *
     * @return Collection<int, Onboarding>
     */
    private function needsAttention(User $user): Collection
    {
        $stalled = Onboarding::query()
            ->where('user_id', $user->id)
            ->awaitingApproval()
            ->where('submitted_at', '<', now()->subDays(config('hub.stalled_after_days')))
            ->oldest('submitted_at')
            ->limit(5)
            ->get();

        $approved = Onboarding::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->oldest('approved_at')
            ->limit(5)
            ->get();

        return $stalled->concat($approved)->take(6);
    }

    private function home(User $viewer): View
    {
        $canSeeTeam = $viewer->can(Permission::InviteSalespeople->value);

        return view('livewire.dashboard', [
            'user' => $viewer,
            'greeting' => $this->greeting(),
            'canSeeTeam' => $canSeeTeam,
            'canSeePerformance' => $viewer->can(Permission::ViewTeamPerformance->value),
            'canSeePartnerRegister' => $viewer->can(Permission::ViewPartnerRegister->value),
            'teamCounts' => $canSeeTeam ? $this->teamCounts() : [],
            'pendingInvitations' => $canSeeTeam ? Invitation::query()->pending()->count() : 0,
            'monthOnboarded' => Onboarding::query()->creditedBetween(now()->startOfMonth(), now()->endOfMonth())->count(),
            'awaiting' => Onboarding::query()->awaitingApproval()->count(),
            'unattributed' => Onboarding::query()->unattributed()->count(),
        ]);
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Active users per role, in the order roles are listed.
     *
     * @return array<string, int>
     */
    private function teamCounts(): array
    {
        $counts = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('users.is_active', true)
            ->groupBy('roles.name')
            ->pluck(DB::raw('count(*)'), 'roles.name');

        $ordered = [];

        foreach (Role::cases() as $role) {
            $ordered[$role->label()] = (int) ($counts[$role->value] ?? 0);
        }

        return $ordered;
    }
}
