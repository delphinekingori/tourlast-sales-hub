<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Exports\PayoutStatementsExport;
use App\Incentives\ChecklistItem;
use App\Incentives\Statements;
use App\Models\AccountChecklistItem;
use App\Models\Activity;
use App\Models\ClaimAttachment;
use App\Models\PartnerAccount;
use App\Models\PayoutStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private files and generated documents. Everything is checked against the viewer's access.
 */
class DownloadController extends Controller
{
    public function claimAttachment(ClaimAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view-claim', $attachment->claim);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name);
    }

    public function evidence(AccountChecklistItem $item): StreamedResponse
    {
        Gate::authorize('view-account', $item->partnerAccount);
        abort_unless($item->evidence_path, 404);

        return Storage::disk('local')->response($item->evidence_path, $item->evidence_name);
    }

    /**
     * The monthly statement with the Schedule 1 paragraph 13 report for every claimed Account.
     */
    public function statement(PayoutStatement $statement, Statements $statements): Response
    {
        Gate::authorize('view-earnings', $statement->user);

        $accounts = PartnerAccount::query()
            ->whereHas('pointEntries', fn ($query) => $query->where('user_id', $statement->user_id)->forMonth($statement->month))
            ->with(['onboardings', 'checklistItems', 'pointEntries' => fn ($query) => $query->where('user_id', $statement->user_id)])
            ->get();

        $followUps = $accounts->mapWithKeys(fn (PartnerAccount $account): array => [
            $account->id => Activity::query()
                ->where('user_id', $statement->user_id)
                ->whereHas('lead', fn ($query) => $query->whereIn('onboarding_id', $account->onboardings->pluck('id')))
                ->count(),
        ]);

        return Pdf::loadView('reports.statement', [
            'statement' => $statement->load('user'),
            'accounts' => $accounts,
            'followUps' => $followUps,
            'dueOn' => $statements->paymentDueOn($statement->month),
            'checklist' => ChecklistItem::cases(),
            'logo' => base64_encode((string) file_get_contents(public_path('images/tourlast-logo.png'))),
        ])->setPaper('a4')->download('statement-'.str($statement->user->name)->slug().'-'.$statement->month->format('Y-m').'.pdf');
    }

    public function payouts(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can(Permission::ViewTeamEarnings->value), 403);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : now()->startOfMonth()->subMonth()->toImmutable();

        return Excel::download(new PayoutStatementsExport($month), 'tourlast-payouts-'.$month->format('Y-m').'.xlsx');
    }
}
