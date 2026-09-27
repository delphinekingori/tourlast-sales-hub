<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Support\EarningsSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Any salesperson's pay for a month (HR, Accounts, Sales Admin), or your own.
 */
class EarningsController extends ApiController
{
    /**
     * GET /earnings/{userId}?month=YYYY-MM
     */
    public function show(Request $request, int $user, EarningsSummary $summary): JsonResponse
    {
        $subject = User::findOrFail($user);
        Gate::authorize('view-earnings', $subject);
        abort_unless((bool) $subject->role()?->earnsReferrals(), 404, 'This person has no earnings.');

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'))
            : CarbonImmutable::now()->startOfMonth();

        return response()->json(['data' => $summary->for($subject, $month)]);
    }
}
