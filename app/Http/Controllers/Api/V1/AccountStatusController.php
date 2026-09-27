<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ChangeAccountStatus;
use App\Actions\DeleteUser;
use App\Enums\AccountStatus;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Suspend, fire, reinstate and delete accounts, under App\Policies\UserPolicy:
 * Sales Managers act on salespeople only; only Sales Admin / Super Admin
 * reinstate fired people and delete; nobody acts on their own account.
 */
class AccountStatusController extends ApiController
{
    /**
     * POST /users/{id}/suspend
     */
    public function suspend(Request $request, User $user, ChangeAccountStatus $change): UserResource
    {
        Gate::authorize('suspend', $user);

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(AccountStatus::suspensionReasons()))],
            'until' => ['nullable', 'date', 'after:today'],
            'notes' => [Rule::requiredIf($request->input('reason') === 'other'), 'nullable', 'string', 'max:1000'],
        ]);

        $change->suspend($user, $this->user($request), $data['reason'], $data['notes'] ?? null, filled($data['until'] ?? null) ? CarbonImmutable::parse($data['until']) : null);

        return $this->fresh($user);
    }

    /**
     * POST /users/{id}/terminate — fire; every record is kept.
     */
    public function terminate(Request $request, User $user, ChangeAccountStatus $change): UserResource
    {
        Gate::authorize('terminate', $user);

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(AccountStatus::terminationReasons()))],
            'notes' => [Rule::requiredIf($request->input('reason') === 'other'), 'nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that you want to fire this person by sending "confirm": true.']);

        $change->terminate($user, $this->user($request), $data['reason'], $data['notes'] ?? null);

        return $this->fresh($user);
    }

    /**
     * POST /users/{id}/reinstate
     */
    public function reinstate(Request $request, User $user, ChangeAccountStatus $change): UserResource
    {
        Gate::authorize('reinstate', $user);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $change->reinstate($user, $this->user($request), $data['notes'] ?? null);

        return $this->fresh($user);
    }

    /**
     * DELETE /users/{id} — only for accounts with no business history.
     */
    public function destroy(Request $request, User $user, DeleteUser $deleteUser): JsonResponse
    {
        Gate::authorize('delete', $user);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm the permanent deletion by sending "confirm": true.']);

        $blockers = $deleteUser->blockers($user);

        if ($blockers !== [] || ! $deleteUser->handle($user)) {
            return response()->json([
                'message' => "{$user->name} can't be deleted because they have history. Fire them instead, so these records are kept.",
                'blockers' => $blockers,
            ], 409);
        }

        return response()->json(['message' => 'Account permanently deleted.']);
    }

    private function fresh(User $user): UserResource
    {
        return new UserResource($user->fresh(['roles', 'referralCode', 'statusChanges.changer']));
    }
}
