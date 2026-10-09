<?php

namespace App\Livewire\Travel\Influencers;

use App\Actions\Travel\Influencers\ChangeInfluencerCodeStatus;
use App\Actions\Travel\Influencers\SaveInfluencer;
use App\Actions\Travel\Influencers\SaveInfluencerCode;
use App\Actions\Travel\Influencers\SuggestInfluencerCode;
use App\Enums\Role;
use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\InfluencerCommissionType;
use App\Enums\Travel\TravelBookingStatus;
use App\Livewire\Travel\Influencers\Concerns\InfluencerForms;
use App\Models\FlightBooking;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Travel\InfluencerAccess;
use App\Support\Travel\InfluencerCodeFilters;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Influencer referral codes: every code with its commission terms, bookings,
 * revenue and commission, plus adding influencers and generating codes.
 */
#[Title('Influencers')]
class Index extends Component
{
    use InfluencerForms, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $salesperson = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $platform = '';

    #[Url(as: 'applies_to')]
    public string $appliesTo = '';

    #[Url(as: 'active_from')]
    public string $activeFrom = '';

    #[Url(as: 'active_to')]
    public string $activeTo = '';

    public bool $showInfluencerForm = false;

    public bool $showCodeForm = false;

    /** @var array<string, mixed> */
    public array $influencerForm = [];

    /** @var array<string, mixed> */
    public array $codeForm = [];

    #[Locked]
    public ?int $editingCodeId = null;

    public function mount(): void
    {
        InfluencerAccess::abortUnlessCanView(Auth::user());
    }

    public function updating(string $property): void
    {
        if (! str_contains($property, 'Form')) {
            $this->resetPage();
        }
    }

    public function newInfluencer(): void
    {
        abort_unless(InfluencerAccess::canCreate(Auth::user()), 403);

        $this->resetValidation();
        $this->influencerForm = self::blankInfluencer();
        $this->showInfluencerForm = true;
    }

    public function saveInfluencer(SaveInfluencer $save): void
    {
        $this->validate(self::prefixed('influencerForm', SaveInfluencer::rules()), self::prefixed('influencerForm', SaveInfluencer::messages()));

        $influencer = $save->handle(Auth::user(), $this->influencerForm);

        $this->showInfluencerForm = false;
        $this->dispatch('toast', message: "{$influencer->name} added. Now generate a code for them.");
        $this->newCode($influencer->id);
    }

    public function newCode(?int $influencerId = null): void
    {
        abort_unless(InfluencerAccess::canCreate(Auth::user()), 403);

        $this->resetValidation();
        $this->editingCodeId = null;
        $influencer = $influencerId ? $this->influencers()->find($influencerId) : null;

        $this->codeForm = [
            'influencer_id' => $influencer ? (string) $influencer->id : '',
            'code' => $influencer ? app(SuggestInfluencerCode::class)->handle($influencer) : '',
            'commission_type' => InfluencerCommissionType::Percentage->value,
            'commission_value' => '',
            'applies_to' => InfluencerCodeScope::Packages->value,
            'max_bookings' => '',
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addMonths(3)->toDateString(),
        ];
        $this->showCodeForm = true;
    }

    /**
     * Picking an influencer in the form suggests a code for them.
     */
    public function updatedCodeFormInfluencerId(string $value): void
    {
        $influencer = $value !== '' ? $this->influencers()->find((int) $value) : null;

        if ($influencer && $this->editingCodeId === null) {
            $this->codeForm['code'] = app(SuggestInfluencerCode::class)->handle($influencer);
        }
    }

    public function editCode(int $codeId): void
    {
        $code = $this->findCode($codeId);
        abort_unless(InfluencerAccess::canManage(Auth::user(), $code->influencer), 403);

        $this->resetValidation();
        $this->editingCodeId = $code->id;
        $this->codeForm = [
            'influencer_id' => (string) $code->influencer_id,
            'code' => $code->code,
            'commission_type' => $code->commission_type->value,
            'commission_value' => (string) (float) $code->commission_value,
            'applies_to' => $code->applies_to->value,
            'max_bookings' => (string) ($code->max_bookings ?? ''),
            'starts_on' => $code->starts_on->toDateString(),
            'ends_on' => $code->ends_on?->toDateString() ?? '',
        ];
        $this->showCodeForm = true;
    }

    public function saveCode(SaveInfluencerCode $save): void
    {
        $this->validate(['codeForm.influencer_id' => ['required']], ['codeForm.influencer_id.required' => 'Choose the influencer.']);

        $influencer = $this->influencers()->findOrFail((int) $this->codeForm['influencer_id']);
        $code = $this->editingCodeId ? $this->findCode($this->editingCodeId) : null;

        $this->runForm('codeForm', fn () => $save->handle(Auth::user(), $influencer, $this->codeForm, $code));

        $this->showCodeForm = false;
        $this->dispatch('toast', message: $code ? 'Code terms updated.' : 'Code '.strtoupper((string) $this->codeForm['code']).' created. Share it with '.$influencer->name.'.');
    }

    public function setCodeStatus(int $codeId, string $status, ChangeInfluencerCodeStatus $change): void
    {
        $code = $this->findCode($codeId);
        $change->handle(Auth::user(), $code, InfluencerCodeStatus::from($status));

        $this->dispatch('toast', message: "Code {$code->code}: ".InfluencerCodeStatus::from($status)->label().'.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $filters = $this->filters();

        return view('livewire.travel.influencers.index', [
            'codes' => $filters->query($user)->paginate(25),
            'summary' => $this->summary($user),
            'filters' => $filters,
            'canCreate' => InfluencerAccess::canCreate($user),
            'seesAll' => InfluencerAccess::seesAll($user),
            'salespeople' => InfluencerAccess::seesAll($user)
                ? User::query()->role(Role::TravelSalesperson->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'myInfluencers' => $this->influencers()->orderBy('name')->get(['id', 'name', 'handle']),
            'editingLocked' => $this->editingCodeId !== null && InfluencerCommission::query()->where('influencer_code_id', $this->editingCodeId)->exists(),
        ]);
    }

    /**
     * Influencers the user may give codes to.
     *
     * @return Builder<Influencer>
     */
    private function influencers(): Builder
    {
        $user = Auth::user();

        return Influencer::query()->when(! TravelAccess::managesAll($user), fn (Builder $query) => $query->where('owner_id', $user->id));
    }

    private function findCode(int $codeId): InfluencerCode
    {
        $code = InfluencerCode::query()->with('influencer')->findOrFail($codeId);
        abort_unless(InfluencerAccess::canSee(Auth::user(), $code->influencer), 404);

        return $code;
    }

    private function filters(): InfluencerCodeFilters
    {
        return InfluencerCodeFilters::fromArray([
            'q' => $this->search,
            'salesperson' => $this->salesperson,
            'status' => $this->status,
            'platform' => $this->platform,
            'applies_to' => $this->appliesTo,
            'active_from' => $this->activeFrom,
            'active_to' => $this->activeTo,
        ]);
    }

    /**
     * @return array{active: int, bookings: int, revenue: float, payable: float, paidThisMonth: float}
     */
    private function summary(User $user): array
    {
        $visibleCodes = InfluencerCode::query()->whereHas('influencer', fn (Builder $query) => InfluencerAccess::scopeInfluencers($query, $user))->select('id');
        $month = [now()->startOfMonth(), now()->endOfMonth()];

        $packages = PackageBooking::query()->whereIn('influencer_code_id', $visibleCodes)
            ->whereNotIn('status', [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow])
            ->whereBetween('created_at', $month);
        $flights = FlightBooking::query()->whereIn('influencer_code_id', $visibleCodes)
            ->whereNull('cancellation_status')
            ->whereBetween('booked_at', $month);
        $commissions = InfluencerCommission::query()->whereIn('influencer_code_id', $visibleCodes);

        return [
            'active' => InfluencerCode::query()->whereIn('id', $visibleCodes)->where('status', InfluencerCodeStatus::Active)->count(),
            'bookings' => (clone $packages)->count() + (clone $flights)->count(),
            'revenue' => (float) (clone $packages)->sum('amount_total') + (float) (clone $flights)->sum('total_amount'),
            'payable' => (float) (clone $commissions)->where('status', CommissionEntryStatus::Payable)->sum('commission_amount'),
            'paidThisMonth' => (float) (clone $commissions)->where('status', CommissionEntryStatus::Paid)->whereBetween('paid_at', $month)->sum('commission_amount'),
        ];
    }
}
