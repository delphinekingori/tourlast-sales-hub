<?php

namespace App\Livewire\Travel\Payments;

use App\Actions\Travel\Payments\AllocatePayment;
use App\Actions\Travel\Payments\ConfirmManualPayment;
use App\Enums\Role;
use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Integrations\Mpesa\MpesaGateway;
use App\Models\DarajaCallback;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use App\Support\Travel\PaymentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every package payment: M-Pesa prompts and paybill payments from Daraja,
 * cash/bank payments awaiting Accounts, unmatched paybill money to allocate
 * and the raw callbacks log. Salespeople see payments on their own bookings.
 */
#[Title('Payments')]
class Index extends Component
{
    use WithPagination;

    public const Tabs = [
        'all' => 'All payments',
        'awaiting' => 'Awaiting confirmation',
        'unmatched' => 'Unmatched M-Pesa',
        'callbacks' => 'Callbacks log',
    ];

    #[Url]
    public string $tab = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $salesperson = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $showAllocate = false;

    #[Locked]
    public ?int $allocatingId = null;

    public string $bookingSearch = '';

    public ?int $allocateBookingId = null;

    public bool $showReject = false;

    #[Locked]
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        abort_unless(PaymentAccess::opensPayments(Auth::user()), 403);

        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'all';
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'method', 'status', 'salesperson', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function updatedTab(): void
    {
        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'all';
        }
    }

    public function confirm(int $paymentId, ConfirmManualPayment $confirm): void
    {
        $confirm->confirm($this->visiblePayments()->findOrFail($paymentId), Auth::user());

        $this->dispatch('toast', message: 'Payment confirmed.');
    }

    public function openReject(int $paymentId): void
    {
        abort_unless(PaymentAccess::confirms(Auth::user()), 403);

        $this->visiblePayments()->findOrFail($paymentId);
        $this->resetValidation();
        $this->rejectingId = $paymentId;
        $this->rejectReason = '';
        $this->showReject = true;
    }

    public function reject(ConfirmManualPayment $confirm): void
    {
        abort_if($this->rejectingId === null, 404);

        $confirm->reject($this->visiblePayments()->findOrFail($this->rejectingId), $this->rejectReason, Auth::user());

        $this->showReject = false;
        $this->rejectingId = null;
        $this->dispatch('toast', message: 'Payment rejected.', tone: 'danger');
    }

    public function openAllocate(int $paymentId): void
    {
        abort_unless(PaymentAccess::confirms(Auth::user()), 403);

        $payment = TravelPayment::query()->unallocated()->findOrFail($paymentId);

        $this->resetValidation();
        $this->allocatingId = $payment->id;
        $this->bookingSearch = (string) $payment->account_reference;
        $this->allocateBookingId = null;
        $this->showAllocate = true;
    }

    public function allocate(AllocatePayment $allocate): void
    {
        abort_if($this->allocatingId === null, 404);
        $this->validate(['allocateBookingId' => ['required', 'integer']], ['allocateBookingId.required' => 'Choose the booking this payment is for.']);

        $allocate->handle(
            TravelPayment::query()->findOrFail($this->allocatingId),
            PackageBooking::query()->findOrFail($this->allocateBookingId),
            Auth::user(),
        );

        $this->showAllocate = false;
        $this->allocatingId = null;
        $this->dispatch('toast', message: 'Payment allocated to the booking.');
    }

    public function render(MpesaGateway $gateway): View
    {
        $user = Auth::user();
        $seesAll = PaymentAccess::seesAll($user);

        return view('livewire.travel.payments.index', [
            'tabs' => $this->tabs(),
            'seesAll' => $seesAll,
            'confirms' => PaymentAccess::confirms($user),
            'simulated' => $gateway->isSimulated(),
            'summary' => $this->summary(),
            'payments' => $this->tab === 'callbacks' ? null : $this->paymentsQuery()->paginate(25),
            'callbacks' => $this->tab === 'callbacks' ? DarajaCallback::query()->with('payment:id,mpesa_receipt,package_booking_id')->latest('id')->paginate(25) : null,
            'salespeople' => $seesAll ? User::query()->role(Role::TravelSalesperson->value)->orderBy('name')->get(['id', 'name']) : collect(),
            'methods' => PaymentMethod::cases(),
            'statuses' => PaymentStatus::cases(),
            'bookingOptions' => $this->showAllocate ? $this->bookingOptions() : collect(),
            'allocating' => $this->showAllocate && $this->allocatingId ? TravelPayment::query()->find($this->allocatingId) : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        $tabs = self::Tabs;

        if (! PaymentAccess::seesAll(Auth::user())) {
            unset($tabs['unmatched'], $tabs['callbacks']);
        }

        return $tabs;
    }

    /**
     * @return Builder<TravelPayment>
     */
    private function visiblePayments(): Builder
    {
        $user = Auth::user();

        return TravelPayment::query()->when(
            ! PaymentAccess::seesAll($user),
            fn (Builder $query) => $query->whereHas('booking', fn (Builder $booking) => $booking->visibleTo($user)),
        );
    }

    /**
     * @return Builder<TravelPayment>
     */
    private function paymentsQuery(): Builder
    {
        $search = trim($this->search);

        return $this->visiblePayments()
            ->with(['booking:id,reference,package_id,travel_client_id,salesperson_id', 'booking.client:id,name', 'booking.package:id,name', 'booking.salesperson:id,name', 'recorder:id,name', 'confirmer:id,name'])
            ->when($this->tab === 'awaiting', fn (Builder $query) => $query->where('channel', PaymentChannel::Manual)->where('status', PaymentStatus::Completed)->whereNull('confirmed_at'))
            ->when($this->tab === 'unmatched', fn (Builder $query) => $query->unallocated())
            ->when(PaymentMethod::tryFrom($this->method), fn (Builder $query, PaymentMethod $method) => $query->where('method', $method))
            ->when(PaymentStatus::tryFrom($this->status), fn (Builder $query, PaymentStatus $status) => $query->where('status', $status))
            ->when($this->salesperson !== '' && PaymentAccess::seesAll(Auth::user()), fn (Builder $query) => $query->whereHas('booking', fn (Builder $booking) => $booking->where('salesperson_id', (int) $this->salesperson)))
            ->when($this->from !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('mpesa_receipt', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('account_reference', 'like', "%{$search}%")
                ->orWhere('payer_name', 'like', "%{$search}%")
                ->orWhereHas('booking', fn (Builder $booking) => $booking->search($search))))
            ->latest('id');
    }

    /**
     * @return array{collected: float, pending: int, awaiting: int, unmatched: int, unmatched_amount: float}
     */
    private function summary(): array
    {
        $unmatched = PaymentAccess::seesAll(Auth::user()) ? TravelPayment::query()->unallocated() : null;

        return [
            'collected' => (float) $this->visiblePayments()->counted()->whereNotNull('package_booking_id')
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            'pending' => $this->visiblePayments()->where('channel', PaymentChannel::Stk)->where('status', PaymentStatus::Pending)->count(),
            'awaiting' => $this->visiblePayments()->where('channel', PaymentChannel::Manual)->where('status', PaymentStatus::Completed)->whereNull('confirmed_at')->count(),
            'unmatched' => $unmatched ? (clone $unmatched)->count() : 0,
            'unmatched_amount' => $unmatched ? (float) (clone $unmatched)->sum('amount') : 0.0,
        ];
    }

    /**
     * Open bookings matching the allocation search.
     *
     * @return Collection<int, PackageBooking>
     */
    private function bookingOptions(): Collection
    {
        $term = trim($this->bookingSearch);

        return PackageBooking::query()
            ->with(['client:id,name', 'package:id,name'])
            ->whereNotIn('status', [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow])
            ->when($term !== '', fn (Builder $query) => $query->search($term))
            ->latest('id')
            ->limit(8)
            ->get();
    }
}
