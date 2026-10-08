<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueReferralCode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The team directory (People and Users & Invites) and editing role/region.
 */
class UserController extends ApiController
{
    /**
     * GET /users — people with "view presence" (admins, Sales Managers, HR, Finance)
     * or "invite salespeople".
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, Permission::ViewPresence, Permission::InviteSalespeople);

        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));
        $role = Role::tryFrom((string) $request->query('role', ''));

        $users = User::query()
            ->with(['roles', 'referralCode'])
            ->visibleTo($this->user($request))
            ->when($role, fn ($query) => $query->role($role->value))
            ->when(in_array($status, ['active', 'suspended', 'terminated'], true), fn ($query) => $query->where('account_status', $status))
            ->when($request->boolean('online'), fn ($query) => $query->where('last_seen_at', '>', now()->subMinutes(5)))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%")
                ->orWhere('region', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return UserResource::collection($users);
    }

    /**
     * GET /users/{id} — one person, with account history for directory viewers.
     */
    public function show(Request $request, User $user): UserResource
    {
        $viewer = $this->user($request);
        $canSeeTeam = $viewer->can(Permission::ViewPresence->value) || $viewer->can(Permission::InviteSalespeople->value);
        abort_unless($canSeeTeam || $user->is($viewer), 403, 'Your account is not allowed to do this.');
        abort_unless($user->isVisibleTo($viewer), 404);

        $user->load(['roles', 'referralCode']);

        if ($canSeeTeam) {
            $user->load('statusChanges.changer:id,name');
        }

        return new UserResource($user);
    }

    /**
     * PATCH /users/{id} — change role and/or region (Sales Admin, Super Admin).
     */
    public function update(Request $request, User $user, IssueReferralCode $issueReferralCode): UserResource
    {
        $actor = $this->user($request);

        abort_unless($actor->can(Permission::ManageUsers->value), 403, 'Your account is not allowed to do this.');
        abort_if($user->is($actor), 403, 'You cannot change your own account here.');
        abort_if($user->hasRole(Role::SuperAdmin->value) && ! $actor->hasRole(Role::SuperAdmin->value), 403, 'Only a Super Admin can change a Super Admin.');

        $data = $request->validate([
            'role' => ['sometimes', 'required', Rule::in(array_map(fn (Role $role) => $role->value, Role::assignableBy($actor)))],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if (array_key_exists('region', $data)) {
            $user->update(['region' => $data['region'] ?: null]);
        }

        if (isset($data['role']) && $user->role()?->value !== $data['role']) {
            $user->syncRoles([$data['role']]);
            $issueReferralCode->handle($user->refresh());
        }

        return new UserResource($user->fresh(['roles', 'referralCode', 'statusChanges.changer']));
    }
}
