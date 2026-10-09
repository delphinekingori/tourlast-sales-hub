<?php

namespace App\Livewire\Travel\Cancellations;

use App\Actions\Travel\Bookings\DecideCancellation;
use App\Actions\Travel\Bookings\ManageRefund;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\RefundStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Models\PackageCancellation;
use App\Models\TravelRefund;
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
 * Package cancellation and refund requests. Sales Admins decide them;
 * Accounts pays refunds out; salespeople follow their own.
 */
#[Title('Cancellations & refunds')]
class Index extends Component
{
    use CapturesActionErrors;
    use WithPagination;

    #[Url]
    public string $tab = 'cancellations';

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showDecision = false;

    #[Locked]
    public ?string $decisionType = null;

    #[Locked]
    public ?int $decisionId = null;

    public string $decisionNote = '';

    public bool $showPayout = false;

    #[Locked]
    public ?int $payoutId = null;

    /** @var array<string, string> */
    public array $payout = ['outcome' => 'completed', 'method' => 'mpesa', 'reference' => '', 'reason' => ''];

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user) || $user->can(Permission::ApproveTravelRefunds->value), 403);
        $this->tab = in_array($this->tab, ['cancellations', 'refunds'], true) ? $this->tab : 'cancellations';
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'status', 'search'], true)) {
            $this->resetPage();
        }

        if ($property === 'tab') {
            $this->status = '';
        }
    }

    public function openDecision(string $type, int $id): void
    {
        abort_unless(Auth::user()->can(Permission::ApproveTravelRefunds->value), 403);
        abort_unless(in_array($type, ['cancellation', 'refund'], true), 404);

        $this->decisionType = $type;
        $this->decisionId = ($type === 'cancellation' ? PackageCancellation::query() : TravelRefund::query())->findOrFail($id)->id;
        $this->decisionNote = '';
        $this->resetErrorBag();
        $this->showDecision = true;
    }

    public function decide(bool $approve, DecideCancellation $cancellations, ManageRefund $refunds): void
    {
        $user = Auth::user();

        $done = $this->attempt(fn () => $this->decisionType === 'cancellation'
            ? $cancellations->handle($user, PackageCancellation::query()->findOrFail($this->decisionId), $approve, $this->decisionNote ?: null)
            : $refunds->decide($user, TravelRefund::query()->findOrFail($this->decisionId), $approve, $this->decisionNote ?: null), '');

        if ($done) {
            $this->showDecision = false;
            $this->dispatch('toast', message: ucfirst((string) $this->decisionType).($approve ? ' approved.' : ' rejected.'));
        }
    }

    public function openPayout(int $id): void
    {
        abort_unless(TravelAccess::handlesPayments(Auth::user()), 403);
        $this->payoutId = TravelRefund::query()->findOrFail($id)->id;
        $this->payout = ['outcome' => 'completed', 'method' => 'mpesa', 'reference' => '', 'reason' => ''];
        $this->resetErrorBag();
        $this->showPayout = true;
    }

    public function savePayout(ManageRefund $refunds): void
    {
        $done = $this->attempt(fn () => $refunds->process(
            Auth::user(),
            TravelRefund::query()->findOrFail($this->payoutId),
            $this->payout['outcome'],
            $this->payout['method'] ?: null,
            $this->payout['reference'] ?: null,
            $this->payout['reason'] ?: null,
        ), 'payout');

        if ($done) {
            $this->showPayout = false;
            $this->dispatch('toast', message: 'Refund updated.');
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $seesAll = TravelAccess::managesAll($user) || TravelAccess::handlesPayments($user) || $user->can(Permission::ApproveTravelRefunds->value);
        $visibleBookings = fn (Builder $bookings) => $bookings->visibleTo($user);
        $search = fn (Builder $query) => $query->whereHas('booking', fn (Builder $booking) => $booking->search($this->search));

        $cancellations = PackageCancellation::query()
            ->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))
            ->when($this->search !== '', $search)
            ->when($this->tab === 'cancellations' && CancellationStatus::tryFrom($this->status), fn (Builder $query) => $query->where('status', $this->status))
            ->with(['booking.client:id,name', 'booking.package:id,name', 'booking.departure:id,starts_on,ends_on', 'booking.salesperson:id,name', 'requester:id,name', 'decider:id,name', 'processor:id,name', 'refunds:id,package_cancellation_id,status'])
            ->latest();

        $refunds = TravelRefund::query()
            ->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))
            ->when($this->search !== '', $search)
            ->when($this->tab === 'refunds' && RefundStatus::tryFrom($this->status), fn (Builder $query) => $query->where('status', $this->status))
            ->with(['booking.client:id,name', 'booking.package:id,name', 'requester:id,name', 'approver:id,name', 'processor:id,name'])
            ->latest();

        return view('livewire.travel.cancellations.index', [
            'rows' => $this->tab === 'cancellations' ? $cancellations->paginate(25) : $refunds->paginate(25),
            'summary' => [
                'pendingCancellations' => PackageCancellation::query()->where('status', CancellationStatus::Pending)->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))->count(),
                'requestedRefunds' => TravelRefund::query()->where('status', RefundStatus::Requested)->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))->count(),
                'toPayOut' => TravelRefund::query()->whereIn('status', [RefundStatus::Approved, RefundStatus::Processing])->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))->sum('amount'),
                'paidOut' => TravelRefund::query()->where('status', RefundStatus::Completed)->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', $visibleBookings))->sum('amount'),
            ],
            'canDecide' => $user->can(Permission::ApproveTravelRefunds->value),
            'canPayOut' => TravelAccess::handlesPayments($user),
            'userId' => $user->id,
            'isSuperAdmin' => $user->hasRole(Role::SuperAdmin->value),
        ]);
    }
}
