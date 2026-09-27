<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SendInvitation;
use App\Enums\InvitationStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Resources\V1\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Invitations: admins invite anyone (Sales Admins not Super Admins),
 * Sales Managers invite salespeople only.
 */
class InvitationController extends ApiController
{
    /**
     * GET /invitations?status=&q=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, Permission::InviteSalespeople);

        $search = trim((string) $request->query('q', ''));
        $status = InvitationStatus::tryFrom((string) $request->query('status', ''));

        $invitations = Invitation::query()
            ->with('inviter')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($status === InvitationStatus::Pending, fn ($query) => $query->pending())
            ->when($status === InvitationStatus::Accepted, fn ($query) => $query->whereNotNull('accepted_at'))
            ->when($status === InvitationStatus::Revoked, fn ($query) => $query->whereNull('accepted_at')->whereNotNull('revoked_at'))
            ->when($status === InvitationStatus::Expired, fn ($query) => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()))
            ->latest()
            ->paginate($this->perPage($request));

        return InvitationResource::collection($invitations);
    }

    /**
     * POST /invitations — sends the single-use link by email.
     */
    public function store(Request $request, SendInvitation $sendInvitation): JsonResponse
    {
        $this->requirePermission($request, Permission::InviteSalespeople);
        $actor = $this->user($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in($this->assignableRoles($actor))],
            'region' => ['nullable', 'string', 'max:100'],
        ], ['email.unique' => 'Someone with this email already has an account.']);

        $invitation = $sendInvitation->handle($actor, [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => Role::from($data['role']),
            'region' => $data['region'] ?? null,
        ]);

        return (new InvitationResource($invitation->load('inviter')))->response()->setStatusCode(201);
    }

    /**
     * POST /invitations/{id}/resend — a new link; the old one stops working.
     */
    public function resend(Request $request, Invitation $invitation, SendInvitation $sendInvitation): InvitationResource
    {
        $this->manageable($request, $invitation);
        abort_if($invitation->accepted_at !== null, 422, 'This invitation has already been accepted.');

        return new InvitationResource($sendInvitation->resend($invitation)->load('inviter'));
    }

    /**
     * DELETE /invitations/{id} — cancel a pending invitation.
     */
    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->manageable($request, $invitation);
        abort_unless($invitation->isUsable(), 422, 'Only pending invitations can be cancelled.');

        $invitation->update(['revoked_at' => now()]);

        return response()->json(['message' => "Invitation for {$invitation->email} cancelled."]);
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(User $actor): array
    {
        return array_map(fn (Role $role): string => $role->value, Role::assignableBy($actor));
    }

    /**
     * Admins manage every invitation; Sales Managers only salesperson invitations.
     */
    private function manageable(Request $request, Invitation $invitation): void
    {
        $this->requirePermission($request, Permission::InviteSalespeople);
        abort_unless(in_array($invitation->role, Role::assignableBy($this->user($request)), true), 403, 'Your account is not allowed to do this.');
    }
}
