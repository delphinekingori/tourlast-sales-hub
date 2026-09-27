<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\DownloadController;
use App\Http\Resources\V1\StatementResource;
use App\Incentives\Statements;
use App\Models\PayoutStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payout statements: salespeople see their own; HR, Accounts and the
 * Sales Admin see all. The Sales Admin confirms retainer conditions; Accounts
 * approves (figures freeze) and marks them paid.
 */
class StatementController extends ApiController
{
    /**
     * GET /statements?month=YYYY-MM&user_id=&status=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $seesAll = $this->user($request)->can(Permission::ViewTeamEarnings->value);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'))
            : null;

        $statements = PayoutStatement::query()
            ->with('user')
            ->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $this->user($request)->id))
            ->when($seesAll && $request->filled('user_id'), fn (Builder $query) => $query->where('user_id', $request->integer('user_id')))
            ->when($month, fn (Builder $query, CarbonImmutable $value) => $query->whereDate('month', $value->toDateString()))
            ->when(in_array($request->query('status'), ['draft', 'approved', 'paid'], true), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->orderByDesc('month')
            ->orderBy('user_id')
            ->paginate($this->perPage($request));

        return StatementResource::collection($statements);
    }

    /**
     * GET /statements/{id}
     */
    public function show(Request $request, int $statement): StatementResource
    {
        $record = PayoutStatement::with('user')->findOrFail($statement);
        Gate::authorize('view-earnings', $record->user);

        return new StatementResource($record);
    }

    /**
     * POST /statements/{id}/compliance — retainer conditions (Sales Admin).
     */
    public function compliance(Request $request, int $statement, Statements $statements): StatementResource
    {
        $this->requirePermission($request, Permission::VerifyAccounts);
        $record = PayoutStatement::findOrFail($statement);

        $data = $request->validate(['compliance' => ['required', 'array']] + collect(PayoutStatement::ComplianceItems)
            ->keys()->mapWithKeys(fn (string $key) => ['compliance.'.$key => ['sometimes', 'boolean']])->all());

        $items = array_map('boolval', array_merge(
            array_fill_keys(array_keys(PayoutStatement::ComplianceItems), false),
            array_intersect_key($data['compliance'], PayoutStatement::ComplianceItems),
        ));

        return new StatementResource($this->rethrowAs('compliance', fn () => $statements->setCompliance($record, $items))->load('user'));
    }

    /**
     * POST /statements/{id}/approve — freeze the figures (Accounts).
     */
    public function approve(Request $request, int $statement, Statements $statements): StatementResource
    {
        $this->requirePermission($request, Permission::ManagePayouts);
        $record = PayoutStatement::findOrFail($statement);

        return new StatementResource($this->rethrowAs('status', fn () => $statements->approve($record, $this->user($request)))->load('user'));
    }

    /**
     * POST /statements/{id}/pay — mark paid with the payment reference (Accounts).
     */
    public function pay(Request $request, int $statement, Statements $statements): StatementResource
    {
        $this->requirePermission($request, Permission::ManagePayouts);
        $record = PayoutStatement::findOrFail($statement);
        $data = $request->validate(['payment_reference' => ['required', 'string', 'max:100']]);

        return new StatementResource($this->rethrowAs('payment_reference', fn () => $statements->markPaid($record, $this->user($request), $data['payment_reference']))->load('user'));
    }

    /**
     * GET /statements/{id}/pdf — the statement with the paragraph 13 report.
     */
    public function pdf(int $statement, DownloadController $downloads, Statements $statements): Response
    {
        return $downloads->statement(PayoutStatement::findOrFail($statement), $statements);
    }

    /**
     * Engine validation errors use web form field names; report them under the API field.
     *
     * @param  callable(): PayoutStatement  $callback
     */
    private function rethrowAs(string $field, callable $callback): PayoutStatement
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => collect($exception->errors())->flatten()->all()]);
        }
    }
}
