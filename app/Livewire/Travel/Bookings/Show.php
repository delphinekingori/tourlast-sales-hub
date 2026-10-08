<?php

namespace App\Livewire\Travel\Bookings;

use App\Actions\Travel\Bookings\ChangeBookingStatus;
use App\Actions\Travel\Bookings\ManageRefund;
use App\Actions\Travel\Bookings\RequestCancellation;
use App\Actions\Travel\Bookings\SaveBookingGuests;
use App\Actions\Travel\Bookings\ToggleChecklistItem;
use App\Actions\Travel\Resources\AssignTripResources;
use App\Enums\Travel\ResourceStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Livewire\Travel\Bookings\Concerns\EditsGuestRows;
use App\Models\AuditEvent;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\PackageBooking;
use App\Support\Travel\PreTripChecklist;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One package booking: client, travelers and guest details, money, who runs the trip, the
 * pre-trip checklist, cancellations, refunds and payments.
 */
class Show extends Component
{
    use CapturesActionErrors;
    use EditsGuestRows;

    #[Locked]
    public int $bookingId;

    public bool $showCancel = false;

    public bool $showRefund = false;

    public bool $showAssign = false;

    public bool $showGuests = false;

    /** @var array<int, array<string, mixed>> */
    public array $guests = [];

    /** @var array<string, string> */
    public array $cancel = ['reason' => '', 'refund_amount' => '0'];

    /** @var array<string, string> */
    public array $refund = ['refund_amount' => '', 'refund_reason' => ''];

    /** @var array<string, string|bool> */
    public array $assign = ['driver_id' => '', 'guide_id' => '', 'override_conflict' => false];

    public function mount(int|string $booking): void
    {
        $user = Auth::user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user), 403);

        $this->bookingId = PackageBooking::query()->visibleTo($user)->findOrFail($booking)->id;
    }

    public function confirm(ChangeBookingStatus $change): void
    {
        if ($this->attempt(fn () => $change->confirm(Auth::user(), $this->booking()), '')) {
            $this->dispatch('toast', message: 'Booking confirmed.');
        }
    }

    public function complete(ChangeBookingStatus $change): void
    {
        if ($this->attempt(fn () => $change->complete(Auth::user(), $this->booking()), '')) {
            $this->dispatch('toast', message: 'Booking marked completed.');
        }
    }

    public function noShow(ChangeBookingStatus $change): void
    {
        if ($this->attempt(fn () => $change->noShow(Auth::user(), $this->booking()), '')) {
            $this->dispatch('toast', message: 'Booking marked no-show.');
        }
    }

    public function toggleItem(string $item, ToggleChecklistItem $toggle): void
    {
        $this->attempt(fn () => $toggle->handle(Auth::user(), $this->booking(), $item), '');
    }

    public function openAssign(): void
    {
        $booking = $this->booking();
        abort_unless($booking->isWorkableBy(Auth::user()), 403);
        $this->resetErrorBag();
        $this->assign = ['driver_id' => (string) ($booking->driver_id ?? ''), 'guide_id' => (string) ($booking->guide_id ?? ''), 'override_conflict' => false];
        $this->showAssign = true;
    }

    public function saveAssign(AssignTripResources $assign): void
    {
        $done = $this->attempt(fn () => $assign->forBooking(
            Auth::user(),
            $this->booking(),
            $this->assign['driver_id'] !== '' ? (int) $this->assign['driver_id'] : null,
            $this->assign['guide_id'] !== '' ? (int) $this->assign['guide_id'] : null,
            (bool) $this->assign['override_conflict'],
        ) ?? true, 'assign');

        if ($done) {
            $this->showAssign = false;
            $this->dispatch('toast', message: 'Trip assignment saved.');
        }
    }

    public function openGuests(): void
    {
        $booking = $this->booking();
        abort_unless($booking->isWorkableBy(Auth::user()), 403);
        $this->resetErrorBag();
        $this->guests = $this->guestRowsFor($booking);
        $this->showGuests = true;
    }

    public function saveGuests(SaveBookingGuests $save): void
    {
        $this->resetErrorBag();
        $done = $this->attempt(fn () => $save->handle(Auth::user(), $this->booking(), $this->guests) ?? true, '');

        if ($done) {
            $this->showGuests = false;
            $this->guests = [];
            $this->dispatch('toast', message: 'Guest details saved.');
        }
    }

    public function openCancel(): void
    {
        abort_unless($this->booking()->isWorkableBy(Auth::user()), 403);
        $this->resetErrorBag();
        $this->cancel = ['reason' => '', 'refund_amount' => (string) round((float) $this->booking()->amount_paid - (float) $this->booking()->amount_refunded, 2)];
        $this->showCancel = true;
    }

    public function requestCancellation(RequestCancellation $request): void
    {
        $done = $this->attempt(fn () => $request->handle(Auth::user(), $this->booking(), $this->cancel['reason'], (float) $this->cancel['refund_amount']), 'cancel');

        if ($done) {
            $this->showCancel = false;
            $this->dispatch('toast', message: 'Cancellation request sent for approval.');
        }
    }

    public function openRefund(): void
    {
        abort_unless($this->booking()->isWorkableBy(Auth::user()), 403);
        $this->resetErrorBag();
        $this->refund = ['refund_amount' => '', 'refund_reason' => ''];
        $this->showRefund = true;
    }

    public function requestRefund(ManageRefund $refunds): void
    {
        $done = $this->attempt(fn () => $refunds->request(Auth::user(), $this->booking(), (float) $this->refund['refund_amount'], $this->refund['refund_reason']), 'refund');

        if ($done) {
            $this->showRefund = false;
            $this->dispatch('toast', message: 'Refund request sent for approval.');
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $booking = $this->booking()->load([
            'package.provider:id,name', 'package.owner:id,name', 'package.driver', 'package.guide',
            'version', 'departure.driver', 'departure.guide', 'client', 'guests', 'salesperson:id,name', 'driver', 'guide',
            'influencerCode.influencer:id,name', 'checklistItems.completer:id,name',
            'cancellations.requester:id,name', 'cancellations.decider:id,name',
            'refunds.requester:id,name', 'refunds.approver:id,name', 'refunds.processor:id,name',
        ]);

        return view('livewire.travel.bookings.show', [
            'booking' => $booking,
            'canWork' => $booking->isWorkableBy($user),
            'managesAll' => TravelAccess::managesAll($user),
            'seesContact' => $booking->canSeeClientContact($user),
            'checklist' => PreTripChecklist::items($booking),
            'needsAction' => PreTripChecklist::needsAction($booking),
            'drivers' => Driver::query()->where('status', ResourceStatus::Active)->orderBy('name')->get(['id', 'name']),
            'guides' => Guide::query()->where('status', ResourceStatus::Active)->orderBy('name')->get(['id', 'name']),
            'audit' => AuditEvent::query()->where('subject_type', $booking->getMorphClass())->where('subject_id', $booking->id)->with('user:id,name')->latest('created_at')->latest('id')->limit(30)->get(),
        ])->title($booking->reference);
    }

    private function booking(): PackageBooking
    {
        return PackageBooking::query()->visibleTo(Auth::user())->findOrFail($this->bookingId);
    }
}
