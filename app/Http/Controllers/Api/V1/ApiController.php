<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Base for Sales Hub API v1 controllers.
 *
 * Conventions: routes name the token scope they need (routes/api/v1/*.php);
 * controllers then check the owner's Hub permission or policy with abortUnless
 * helpers or Gate::authorize, and do all work through the same actions the web
 * app uses. Responses use App\Http\Resources\V1 resources; lists are paginated.
 */
abstract class ApiController extends Controller
{
    /**
     * Page size from ?per_page= (default 25, maximum 100).
     */
    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * 403 unless the token owner holds the Hub permission.
     */
    protected function requirePermission(Request $request, Permission ...$permissions): void
    {
        $user = $this->user($request);

        foreach ($permissions as $permission) {
            if ($user->can($permission->value)) {
                return;
            }
        }

        abort(403, 'Your account is not allowed to do this.');
    }

    /**
     * 403 unless the token owner is a salesperson (has a referral code role).
     */
    protected function requireSeller(Request $request): void
    {
        abort_unless((bool) $this->user($request)->role()?->earnsReferrals(), 403, 'Only people who sell can do this.');
    }
}
