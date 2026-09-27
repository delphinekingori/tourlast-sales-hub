<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Resources\V1\TokenResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Admin: issue and revoke API tokens for anyone (Sales Admin, Super Admin).
 * Only a Super Admin can issue or revoke a Super Admin's tokens.
 */
class AdminTokenController extends ApiController
{
    /**
     * GET /admin/tokens?user_id=
     */
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::ManageApiTokens);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->when($request->filled('user_id'), fn ($query) => $query->where('tokenable_id', $request->integer('user_id')))
            ->with(['issuer:id,name', 'tokenable'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($tokens->items())->map(fn (PersonalAccessToken $token) => $this->present($request, $token))->values(),
            'meta' => ['current_page' => $tokens->currentPage(), 'last_page' => $tokens->lastPage(), 'per_page' => $tokens->perPage(), 'total' => $tokens->total()],
        ]);
    }

    /**
     * POST /admin/tokens — the plain-text token is returned once, never again.
     */
    public function store(Request $request, IssueApiToken $issueApiToken): JsonResponse
    {
        $this->requirePermission($request, Permission::ManageApiTokens);

        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScope::values())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $owner = User::findOrFail($data['user_id']);
        $this->guardSuperAdmin($request, $owner);

        try {
            $token = $issueApiToken->handle(
                $owner,
                $data['name'],
                $data['scopes'],
                isset($data['expires_in_days']) ? CarbonImmutable::now()->addDays((int) $data['expires_in_days']) : null,
                $this->user($request),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'data' => $this->present($request, $token->accessToken->load(['issuer:id,name', 'tokenable'])),
        ], 201);
    }

    /**
     * DELETE /admin/tokens/{id}
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $this->requirePermission($request, Permission::ManageApiTokens);

        $record = PersonalAccessToken::query()->with('tokenable')->findOrFail($token);

        if ($record->tokenable instanceof User) {
            $this->guardSuperAdmin($request, $record->tokenable);
        }

        $record->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, PersonalAccessToken $token): array
    {
        $owner = $token->tokenable;

        return (new TokenResource($token))->toArray($request) + [
            'owner' => $owner instanceof User ? ['id' => $owner->id, 'name' => $owner->name, 'role' => $owner->role()?->value] : null,
        ];
    }

    private function guardSuperAdmin(Request $request, User $owner): void
    {
        abort_if(
            $owner->hasRole(Role::SuperAdmin->value) && ! $this->user($request)->hasRole(Role::SuperAdmin->value),
            403,
            'Only a Super Admin can manage a Super Admin\'s tokens.',
        );
    }
}
