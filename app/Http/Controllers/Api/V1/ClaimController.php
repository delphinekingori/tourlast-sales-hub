<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ClaimResource;
use App\Incentives\Claims;
use App\Models\ClaimAttachment;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Airtime claims, transport reimbursements and transport requests, and their
 * approval chain (Sales Manager → HR → Finance).
 */
class ClaimController extends ApiController
{
    /**
     * GET /claims — your own claims.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireSeller($request);

        return ClaimResource::collection(
            ExpenseClaim::query()->where('user_id', $this->user($request)->id)->with('attachments')->latest()->paginate($this->perPage($request)),
        );
    }

    /**
     * POST /claims — multipart when files are attached (receipts[], ride_details[]).
     */
    public function store(Request $request, Claims $claims): ClaimResource
    {
        $this->requireSeller($request);
        $user = $this->user($request);
        $type = (string) $request->input('type');
        $file = ['file', 'mimes:'.implode(',', config('incentives.upload_mimes')), 'max:'.config('incentives.upload_max_kb')];

        $rules = [
            'type' => ['required', Rule::in(array_keys(ExpenseClaim::Types))],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'description' => ['required', 'string', 'max:1000'],
            'receipts' => ['nullable', 'array'],
            'receipts.*' => $file,
            'ride_details' => ['nullable', 'array'],
            'ride_details.*' => $file,
        ];

        if (in_array($type, ['transport_reimbursement', 'transport_request'], true)) {
            $isRequest = $type === 'transport_request';
            $rideApp = in_array($request->input('ride_provider'), ExpenseClaim::AppProviders, true);

            $rules += [
                'travel_date' => ['required', 'date', $isRequest ? 'after_or_equal:today' : 'before_or_equal:today'],
                'ride_provider' => ['required', Rule::in(array_keys(ExpenseClaim::RideProviders))],
                'pickup' => ['required', 'string', 'max:190'],
                'dropoff' => ['required', 'string', 'max:190'],
                'distance_km' => ['nullable', 'numeric', 'min:0', 'max:5000'],
                'trip_reference' => [! $isRequest && $rideApp ? 'required' : 'nullable', 'string', 'max:100'],
                'partner_account_id' => ['nullable', Rule::exists('partner_accounts', 'id')->where('user_id', $user->id)],
                'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('user_id', $user->id)],
            ];
            $rules['receipts'] = [! $isRequest && ! $rideApp && $request->input('ride_provider') !== 'matatu' ? 'required' : 'nullable', 'array'];
            $rules['ride_details'] = [! $isRequest && $rideApp ? 'required' : 'nullable', 'array'];
        }

        $data = $request->validate($rules, [
            'trip_reference.required' => 'Enter the trip ID shown in the Bolt or Uber app.',
            'ride_details.required' => 'Upload the ride details from the Bolt or Uber app (trip receipt screenshot or the emailed PDF).',
            'receipts.required' => 'Upload a receipt for this trip.',
            'travel_date.after_or_equal' => 'A transport request is for a trip that hasn\'t happened yet. For past trips, claim a reimbursement.',
            'travel_date.before_or_equal' => 'Reimbursements are for trips already taken. For future trips, send a transport request.',
        ]);

        $details = [
            'type' => $type,
            'amount' => (float) $data['amount'],
            'description' => $data['description'],
            'travel_date' => $type === 'airtime' ? now()->toDateString() : $data['travel_date'],
        ];

        if ($type !== 'airtime') {
            $details += [
                'ride_provider' => $data['ride_provider'],
                'trip_reference' => $data['trip_reference'] ?? null,
                'pickup' => $data['pickup'],
                'dropoff' => $data['dropoff'],
                'distance_km' => isset($data['distance_km']) ? (float) $data['distance_km'] : null,
                'partner_account_id' => $data['partner_account_id'] ?? null,
                'lead_id' => $data['lead_id'] ?? null,
            ];
        }

        $claim = $claims->submit($user, $details, [
            'receipt' => array_values($request->file('receipts', [])),
            'ride_details' => array_values($request->file('ride_details', [])),
        ]);

        return new ClaimResource($claim->load('attachments', 'approvals.user'));
    }

    /**
     * GET /claims/{id}
     */
    public function show(Request $request, int $claim): ClaimResource
    {
        $record = ExpenseClaim::with(['user', 'attachments', 'approvals.user'])->findOrFail($claim);
        Gate::authorize('view-claim', $record);

        return new ClaimResource($record);
    }

    /**
     * GET /claims/approvals?tab=mine|disburse|all — claims waiting for your approval step.
     */
    public function approvals(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $steps = $this->steps($user);
        abort_if($steps === [], 403, 'Your account is not allowed to do this.');

        $query = ExpenseClaim::query()->with(['user', 'attachments'])->latest();
        $query = match ($request->query('tab')) {
            'disburse' => $query->where('type', 'transport_request')->where('status', 'approved'),
            'all' => $query,
            default => $query->where('status', 'pending')->whereIn('current_step', $steps)->where('user_id', '!=', $user->id),
        };

        return ClaimResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /claims/{id}/approve — note optional; Finance sets the approved amount.
     */
    public function approve(Request $request, int $claim, Claims $claims): ClaimResource
    {
        $record = ExpenseClaim::findOrFail($claim);
        $this->ensureCanDecide($request, $record, $claims);
        $amount = null;

        if ($record->current_step === 'finance') {
            $data = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:'.$record->amount], 'note' => ['nullable', 'string', 'max:1000']], ['amount.max' => 'Finance can approve up to the amount claimed.']);
            $amount = (float) $data['amount'];
        } else {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        }

        $this->rethrowAs('amount', fn () => $claims->approve($record, $this->user($request), $data['note'] ?? null, $amount));

        return new ClaimResource($record->fresh(['user', 'attachments', 'approvals.user']));
    }

    /**
     * POST /claims/{id}/reject — a reason is required.
     */
    public function reject(Request $request, int $claim, Claims $claims): ClaimResource
    {
        $record = ExpenseClaim::findOrFail($claim);
        $this->ensureCanDecide($request, $record, $claims);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']], ['note.required' => 'Tell the salesperson why the claim is rejected.']);

        $claims->reject($record, $this->user($request), $data['note']);

        return new ClaimResource($record->fresh(['user', 'attachments', 'approvals.user']));
    }

    /**
     * POST /claims/{id}/disburse — Finance releases an approved transport request.
     */
    public function disburse(Request $request, int $claim, Claims $claims): ClaimResource
    {
        $record = ExpenseClaim::findOrFail($claim);
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:100']]);

        $this->rethrowAs('payment_reference', fn () => $claims->disburse($record, $this->user($request), $data['payment_reference']));

        return new ClaimResource($record->fresh(['user', 'attachments', 'approvals.user']));
    }

    /**
     * GET /claims/{id}/attachments/{attachment} — download a receipt or ride details file.
     */
    public function attachment(int $claim, int $attachment): StreamedResponse
    {
        $file = ClaimAttachment::query()->where('expense_claim_id', $claim)->findOrFail($attachment);
        Gate::authorize('view-claim', $file->claim);

        return Storage::disk('local')->response($file->path, $file->original_name);
    }

    private function ensureCanDecide(Request $request, ExpenseClaim $claim, Claims $claims): void
    {
        abort_unless($claims->canDecide($this->user($request), $claim), 403, 'This claim is not waiting for your approval.');
    }

    /**
     * @return list<string>
     */
    private function steps(User $user): array
    {
        return array_values(array_filter(
            array_keys(Claims::StepPermissions),
            fn (string $step): bool => $user->can(Claims::StepPermissions[$step]->value),
        ));
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function rethrowAs(string $field, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => collect($exception->errors())->flatten()->all()]);
        }
    }
}
