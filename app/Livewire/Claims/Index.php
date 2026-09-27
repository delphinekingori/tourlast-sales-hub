<?php

namespace App\Livewire\Claims;

use App\Incentives\Claims;
use App\Models\ExpenseClaim;
use App\Models\IncentivePolicy;
use App\Models\Lead;
use App\Models\PartnerAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * A salesperson's airtime claims, transport reimbursements and transport requests.
 */
#[Title('Claims')]
class Index extends Component
{
    use WithFileUploads, WithPagination;

    public bool $showForm = false;

    public string $type = 'transport_reimbursement';

    /** @var array<string, string> */
    public array $form = [];

    /** @var array<int, mixed> */
    public array $receipts = [];

    /** @var array<int, mixed> */
    public array $rideDetails = [];

    public ?int $viewingId = null;

    public bool $showDetail = false;

    /** @var array<int, mixed> */
    public array $moreReceipts = [];

    /** @var array<int, mixed> */
    public array $moreRideDetails = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->role()?->earnsReferrals(), 403);
        $this->resetForm();
    }

    public function open(string $type): void
    {
        abort_unless(array_key_exists($type, ExpenseClaim::Types), 404);
        $this->resetValidation();
        $this->resetForm();
        $this->type = $type;
        $this->form['travel_date'] = $type === 'transport_request' ? now()->addDay()->toDateString() : now()->toDateString();
        $this->showForm = true;
    }

    public function submit(Claims $claims): void
    {
        $this->validate($this->rules(), $this->messages(), $this->attributes());

        $details = [
            'type' => $this->type,
            'amount' => (float) $this->form['amount'],
            'description' => $this->form['description'],
            'travel_date' => $this->type === 'airtime' ? now()->toDateString() : $this->form['travel_date'],
        ];

        if ($this->type !== 'airtime') {
            $details += [
                'ride_provider' => $this->form['ride_provider'],
                'trip_reference' => $this->form['trip_reference'] ?: null,
                'pickup' => $this->form['pickup'],
                'dropoff' => $this->form['dropoff'],
                'distance_km' => $this->form['distance_km'] !== '' ? (float) $this->form['distance_km'] : null,
                'partner_account_id' => $this->form['partner_account_id'] ?: null,
                'lead_id' => $this->form['lead_id'] ?: null,
            ];
        }

        $claim = $claims->submit(Auth::user(), $details, [
            'receipt' => array_values(array_filter($this->receipts)),
            'ride_details' => array_values(array_filter($this->rideDetails)),
        ]);

        $this->showForm = false;
        $this->reset('receipts', 'rideDetails');
        $this->dispatch('toast', message: $claim->typeLabel().' sent to '.(ExpenseClaim::StepLabels[$claim->current_step] ?? 'approval').'.');
    }

    public function view(int $claimId): void
    {
        $this->viewingId = ExpenseClaim::query()->where('user_id', Auth::id())->findOrFail($claimId)->id;
        $this->reset('moreReceipts', 'moreRideDetails');
        $this->showDetail = true;
    }

    public function addAttachments(Claims $claims): void
    {
        $claim = ExpenseClaim::query()->where('user_id', Auth::id())->findOrFail($this->viewingId);
        abort_if(in_array($claim->status, ['rejected'], true) || ($claim->status === 'paid' && ! $claim->isRequest()), 422);

        $this->validate([
            'moreReceipts.*' => $this->fileRule(),
            'moreRideDetails.*' => $this->fileRule(),
        ]);

        $claims->attach($claim, ['receipt' => array_values(array_filter($this->moreReceipts)), 'ride_details' => array_values(array_filter($this->moreRideDetails))]);

        $this->reset('moreReceipts', 'moreRideDetails');
        $this->dispatch('toast', message: 'Files added to the claim.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $month = now()->startOfMonth();
        $airtimeUsed = ExpenseClaim::query()->where('user_id', $user->id)->where('type', 'airtime')->whereDate('month', $month->toDateString())->whereIn('status', ['approved', 'paid'])->get()->sum(fn (ExpenseClaim $claim): float => $claim->payableAmount());

        return view('livewire.claims.index', [
            'claims' => ExpenseClaim::query()->where('user_id', $user->id)->with('attachments')->latest()->paginate(15),
            'viewing' => $this->viewingId ? ExpenseClaim::with(['attachments', 'approvals.user', 'partnerAccount', 'lead'])->find($this->viewingId) : null,
            'airtimeCap' => IncentivePolicy::for($month)->policy()->airtimeCap(),
            'airtimeUsed' => $airtimeUsed,
            'accounts' => PartnerAccount::query()->current()->where('user_id', $user->id)->orderBy('legal_name')->get(['id', 'legal_name']),
            'leads' => Lead::query()->where('user_id', $user->id)->open()->orderBy('business_name')->get(['id', 'business_name']),
            'usesRideApp' => in_array($this->form['ride_provider'] ?? '', ExpenseClaim::AppProviders, true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'form.amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'form.description' => ['required', 'string', 'max:1000'],
            'receipts.*' => $this->fileRule(),
            'rideDetails.*' => $this->fileRule(),
        ];

        if ($this->type === 'airtime') {
            return $rules;
        }

        $isRequest = $this->type === 'transport_request';
        $rideApp = in_array($this->form['ride_provider'] ?? '', ExpenseClaim::AppProviders, true);

        return $rules + [
            'form.travel_date' => ['required', 'date', $isRequest ? 'after_or_equal:today' : 'before_or_equal:today'],
            'form.ride_provider' => ['required', Rule::in(array_keys(ExpenseClaim::RideProviders))],
            'form.pickup' => ['required', 'string', 'max:190'],
            'form.dropoff' => ['required', 'string', 'max:190'],
            'form.distance_km' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'form.trip_reference' => [! $isRequest && $rideApp ? 'required' : 'nullable', 'string', 'max:100'],
            'form.partner_account_id' => ['nullable', Rule::exists('partner_accounts', 'id')->where('user_id', Auth::id())],
            'form.lead_id' => ['nullable', Rule::exists('leads', 'id')->where('user_id', Auth::id())],
            'receipts' => [! $isRequest && ! $rideApp && ($this->form['ride_provider'] ?? '') !== 'matatu' ? 'required' : 'nullable', 'array'],
            'rideDetails' => [! $isRequest && $rideApp ? 'required' : 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'form.trip_reference.required' => 'Enter the trip ID shown in the Bolt or Uber app.',
            'rideDetails.required' => 'Upload the ride details from the Bolt or Uber app (trip receipt screenshot or the emailed PDF).',
            'receipts.required' => 'Upload a receipt for this trip.',
            'form.travel_date.after_or_equal' => 'A transport request is for a trip that hasn\'t happened yet. For past trips, claim a reimbursement.',
            'form.travel_date.before_or_equal' => 'Reimbursements are for trips already taken. For future trips, send a transport request.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'form.amount' => 'amount', 'form.description' => 'purpose', 'form.travel_date' => 'travel date',
            'form.ride_provider' => 'how you travelled', 'form.pickup' => 'pickup', 'form.dropoff' => 'drop-off',
            'form.distance_km' => 'distance', 'receipts.*' => 'receipt', 'rideDetails.*' => 'ride details file',
        ];
    }

    /**
     * @return list<string>
     */
    private function fileRule(): array
    {
        return ['file', 'mimes:'.implode(',', config('incentives.upload_mimes')), 'max:'.config('incentives.upload_max_kb')];
    }

    private function resetForm(): void
    {
        $this->form = [
            'amount' => '', 'description' => '', 'travel_date' => now()->toDateString(), 'ride_provider' => 'bolt',
            'trip_reference' => '', 'pickup' => '', 'dropoff' => '', 'distance_km' => '', 'partner_account_id' => '', 'lead_id' => '',
        ];
        $this->receipts = [];
        $this->rideDetails = [];
    }
}
