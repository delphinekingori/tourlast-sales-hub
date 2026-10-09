<?php

namespace App\Livewire\Travel\Influencers;

use App\Actions\Travel\Influencers\ChangeInfluencerCodeStatus;
use App\Actions\Travel\Influencers\MarkCommissionsPaid;
use App\Actions\Travel\Influencers\SaveInfluencer;
use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Livewire\Travel\Influencers\Concerns\InfluencerForms;
use App\Models\FlightBooking;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\InfluencerCommission;
use App\Models\PackageBooking;
use App\Support\Travel\InfluencerAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One influencer: profile, payout details, codes and the commission ledger.
 */
#[Title('Influencer')]
class Show extends Component
{
    use InfluencerForms, WithPagination;

    #[Locked]
    public int $influencerId;

    #[Url]
    public string $ledger = '';

    public bool $showEdit = false;

    /** @var array<string, mixed> */
    public array $influencerForm = [];

    /** @var list<int|string> */
    public array $selected = [];

    public bool $showPay = false;

    public string $payReference = '';

    public function mount(int|string $influencer): void
    {
        $record = Influencer::query()->findOrFail($influencer);
        abort_unless(InfluencerAccess::canView(Auth::user()), 403);
        abort_unless(InfluencerAccess::canSee(Auth::user(), $record), 404);

        $this->influencerId = $record->id;
    }

    public function updatingLedger(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function edit(): void
    {
        $influencer = $this->influencer();
        abort_unless(InfluencerAccess::canManage(Auth::user(), $influencer), 403);

        $this->resetValidation();
        $this->influencerForm = self::blankInfluencer($influencer);
        $this->showEdit = true;
    }

    public function save(SaveInfluencer $save): void
    {
        $this->validate(self::prefixed('influencerForm', SaveInfluencer::rules()), self::prefixed('influencerForm', SaveInfluencer::messages()));
        $save->handle(Auth::user(), $this->influencerForm, $this->influencer());

        $this->showEdit = false;
        $this->dispatch('toast', message: 'Influencer updated.');
    }

    public function setCodeStatus(int $codeId, string $status, ChangeInfluencerCodeStatus $change): void
    {
        $code = InfluencerCode::query()->where('influencer_id', $this->influencerId)->findOrFail($codeId);
        $change->handle(Auth::user(), $code, InfluencerCodeStatus::from($status));

        $this->dispatch('toast', message: "Code {$code->code}: ".InfluencerCodeStatus::from($status)->label().'.');
    }

    public function openPay(): void
    {
        abort_unless(InfluencerAccess::canMarkPaid(Auth::user()), 403);

        $this->resetValidation();

        if ($this->selected === []) {
            $this->addError('selected', 'Select the payable lines you paid.');

            return;
        }

        $this->payReference = '';
        $this->showPay = true;
    }

    public function markPaid(MarkCommissionsPaid $mark): void
    {
        $this->resetValidation();
        $count = $mark->handle(Auth::user(), $this->influencer(), array_map('intval', $this->selected), $this->payReference);

        $this->showPay = false;
        $this->selected = [];
        $this->dispatch('toast', message: $count.' commission '.str('line')->plural($count).' marked paid.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $influencer = $this->influencer()->load(['owner:id,name', 'platforms']);
        $live = fn (Builder $query) => $query->where('status', '!=', CommissionEntryStatus::Cancelled);

        $codes = $influencer->codes()
            ->with('creator:id,name')
            ->withCount(['commissions as bookings_used' => $live])
            ->withSum(['commissions as revenue_generated' => $live], 'booking_amount')
            ->withSum(['commissions as commission_earned' => $live], 'commission_amount')
            ->latest('id')
            ->get();

        $lines = InfluencerCommission::query()
            ->where('influencer_id', $influencer->id)
            ->with([
                'code:id,code',
                'payer:id,name',
                'bookable' => fn (MorphTo $morph) => $morph->morphWith([PackageBooking::class => ['client:id,name', 'package:id,name']]),
            ])
            ->when(CommissionEntryStatus::tryFrom($this->ledger), fn (Builder $query, CommissionEntryStatus $status) => $query->where('status', $status))
            ->latest('earned_at')
            ->latest('id')
            ->paginate(25);

        $totals = InfluencerCommission::query()->where('influencer_id', $influencer->id)
            ->selectRaw('status, sum(commission_amount) as total')->groupBy('status')->pluck('total', 'status');

        return view('livewire.travel.influencers.show', [
            'influencer' => $influencer,
            'codes' => $codes,
            'lines' => $lines,
            'totals' => $totals,
            'canManage' => InfluencerAccess::canManage($user, $influencer),
            'canPay' => InfluencerAccess::canMarkPaid($user),
            'seesPayout' => InfluencerAccess::seesPayoutDetails($user, $influencer),
        ])->title($influencer->name);
    }

    /**
     * Booking reference, client and kind for a ledger line.
     *
     * @return array{kind: string, reference: string, client: ?string, url: ?string}
     */
    public static function describe(InfluencerCommission $line): array
    {
        $bookable = $line->bookable;

        return match (true) {
            $bookable instanceof PackageBooking => [
                'kind' => 'Package',
                'reference' => $bookable->reference,
                'client' => trim(($bookable->client?->name ?? '').($bookable->package ? ' · '.$bookable->package->name : ''), ' ·'),
                'url' => Route::has('travel.bookings.show') ? route('travel.bookings.show', $bookable) : null,
            ],
            $bookable instanceof FlightBooking => [
                'kind' => 'Flight',
                'reference' => $bookable->booking_reference ?? $bookable->external_id,
                'client' => trim(($bookable->customer_name ?? '').' · '.$bookable->route(), ' ·'),
                'url' => Route::has('travel.flights.show') ? route('travel.flights.show', $bookable) : null,
            ],
            default => ['kind' => '—', 'reference' => '#'.$line->bookable_id, 'client' => null, 'url' => null],
        };
    }

    private function influencer(): Influencer
    {
        return Influencer::query()->findOrFail($this->influencerId);
    }
}
