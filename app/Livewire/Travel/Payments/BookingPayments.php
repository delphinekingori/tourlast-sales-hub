<?php

namespace App\Livewire\Travel\Payments;

use App\Actions\Travel\Payments\ConfirmManualPayment;
use App\Actions\Travel\Payments\RecordManualPayment;
use App\Actions\Travel\Payments\RequestMpesaPayment;
use App\Actions\Travel\Payments\SimulateMpesaCustomer;
use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentStatus;
use App\Integrations\Mpesa\MpesaGateway;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Support\Travel\PaymentAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Payments panel on a package booking: what has been paid, the balance,
 * "Request M-Pesa payment" (prompt to the client's phone), logging cash/bank
 * payments, and Accounts confirming them. Embed with
 * <livewire:travel.payments.booking-payments :booking-id="$booking->id" />.
 */
class BookingPayments extends Component
{
    #[Locked]
    public int $bookingId;

    public bool $showRequest = false;

    public string $phone = '';

    public string $amount = '';

    public bool $showManual = false;

    /** @var array{method: string, amount: string, reference: string, paid_on: string, notes: string} */
    public array $manual = ['method' => 'cash', 'amount' => '', 'reference' => '', 'paid_on' => '', 'notes' => ''];

    public bool $showReject = false;

    #[Locked]
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function mount(int $bookingId): void
    {
        $this->bookingId = $bookingId;

        abort_unless(PaymentAccess::canView(Auth::user(), $this->booking()), 403);
    }

    public function openRequest(): void
    {
        $booking = $this->booking();
        abort_unless(PaymentAccess::canCollect(Auth::user(), $booking), 403);

        $this->resetValidation();
        $this->phone = (string) $booking->client?->phone;
        $this->amount = (string) (int) ceil($booking->balance());
        $this->showRequest = true;
    }

    public function requestPayment(RequestMpesaPayment $request): void
    {
        $request->handle($this->booking(), $this->phone, $this->amount, Auth::user());

        $this->showRequest = false;
        $this->dispatch('toast', message: 'Payment prompt sent to the client\'s phone. Waiting for them to enter their M-Pesa PIN.');
    }

    public function openManual(): void
    {
        $booking = $this->booking();
        abort_unless(PaymentAccess::canCollect(Auth::user(), $booking), 403);

        $this->resetValidation();
        $this->manual = ['method' => 'cash', 'amount' => (string) $booking->balance(), 'reference' => '', 'paid_on' => today()->toDateString(), 'notes' => ''];
        $this->showManual = true;
    }

    public function logManual(RecordManualPayment $record): void
    {
        $this->withValidationPrefix('manual', fn () => $record->handle($this->booking(), $this->manual, Auth::user()));

        $this->showManual = false;
        $this->dispatch('toast', message: 'Payment logged. It counts once Accounts confirms it.');
    }

    public function confirm(int $paymentId, ConfirmManualPayment $confirm): void
    {
        $confirm->confirm($this->payment($paymentId), Auth::user());

        $this->dispatch('toast', message: 'Payment confirmed.');
    }

    public function openReject(int $paymentId): void
    {
        abort_unless(PaymentAccess::confirms(Auth::user()), 403);

        $this->payment($paymentId);
        $this->resetValidation();
        $this->rejectingId = $paymentId;
        $this->rejectReason = '';
        $this->showReject = true;
    }

    public function reject(ConfirmManualPayment $confirm): void
    {
        abort_if($this->rejectingId === null, 404);

        $confirm->reject($this->payment($this->rejectingId), $this->rejectReason, Auth::user());

        $this->showReject = false;
        $this->rejectingId = null;
        $this->dispatch('toast', message: 'Payment rejected.', tone: 'danger');
    }

    /**
     * Test mode: play the customer answering a prompt.
     */
    public function simulate(int $paymentId, bool $pays, SimulateMpesaCustomer $simulate): void
    {
        abort_unless(PaymentAccess::canCollect(Auth::user(), $this->booking()), 403);

        $simulate->answerPrompt($this->payment($paymentId), $pays);

        $this->dispatch('toast', message: $pays ? 'Test payment received.' : 'Test prompt cancelled by the customer.', tone: $pays ? 'success' : 'danger');
    }

    public function render(MpesaGateway $gateway): View
    {
        $user = Auth::user();
        $booking = $this->booking();
        $payments = TravelPayment::query()
            ->where('package_booking_id', $booking->id)
            ->with(['recorder:id,name', 'confirmer:id,name'])
            ->latest('id')
            ->get();

        return view('livewire.travel.payments.booking-payments', [
            'booking' => $booking,
            'payments' => $payments,
            'hasPending' => $payments->contains(fn (TravelPayment $payment) => $payment->channel === PaymentChannel::Stk && $payment->status === PaymentStatus::Pending),
            'canCollect' => PaymentAccess::canCollect($user, $booking) && $booking->balance() > 0,
            'confirms' => PaymentAccess::confirms($user),
            'simulated' => $gateway->isSimulated(),
            'methods' => RecordManualPayment::Methods,
        ]);
    }

    private function booking(): PackageBooking
    {
        return PackageBooking::query()->with(['package:id,owner_id', 'client'])->findOrFail($this->bookingId);
    }

    private function payment(int $paymentId): TravelPayment
    {
        return TravelPayment::query()->where('package_booking_id', $this->bookingId)->findOrFail($paymentId);
    }

    /**
     * Runs an action that validates plain keys and reports them under "manual.*".
     */
    private function withValidationPrefix(string $prefix, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->mapWithKeys(fn (array $errors, string $key) => [str_starts_with($key, $prefix.'.') ? $key : $prefix.'.'.$key => $errors])->all();

            throw ValidationException::withMessages($messages);
        }
    }
}
