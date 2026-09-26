<?php

namespace App\Livewire\Team;

use App\Actions\ChangeAccountStatus;
use App\Actions\DeleteUser;
use App\Actions\IssueReferralCode;
use App\Actions\SendInvitation;
use App\Enums\AccountStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users & Invites')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'people';

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showInvite = false;

    /** @var array{name: string, email: string, phone: string, role: string, region: string} */
    public array $invite = ['name' => '', 'email' => '', 'phone' => '', 'role' => '', 'region' => ''];

    public bool $showEdit = false;

    public ?int $editingUserId = null;

    /** @var array{role: string, region: string} */
    public array $edit = ['role' => '', 'region' => ''];

    #[Url]
    public string $status = '';

    public bool $showAccount = false;

    #[Locked]
    public ?int $accountUserId = null;

    /** suspend, terminate, reinstate or delete */
    #[Locked]
    public string $accountAction = '';

    /** @var array{reason: string, notes: string, until: string, confirm: bool} */
    public array $account = ['reason' => '', 'notes' => '', 'until' => '', 'confirm' => false];

    public function mount(): void
    {
        abort_unless($this->actor()->can(Permission::InviteSalespeople->value), 403);

        $this->tab = in_array($this->tab, ['people', 'invitations'], true) ? $this->tab : 'people';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function openInvite(): void
    {
        $this->resetValidation();
        $roles = Role::assignableBy($this->actor());
        $this->invite = ['name' => '', 'email' => '', 'phone' => '', 'role' => count($roles) === 1 ? $roles[0]->value : Role::Salesperson->value, 'region' => ''];
        $this->showInvite = true;
    }

    public function sendInvite(SendInvitation $sendInvitation): void
    {
        $this->invite['email'] = strtolower(trim($this->invite['email']));

        $this->validate([
            'invite.name' => ['required', 'string', 'max:120'],
            'invite.email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'invite.phone' => ['nullable', 'string', 'max:32'],
            'invite.role' => ['required', Rule::in($this->assignableRoleValues())],
            'invite.region' => ['nullable', 'string', 'max:100'],
        ], [
            'invite.email.unique' => 'Someone with this email already has an account.',
        ], [
            'invite.name' => 'full name',
            'invite.email' => 'email',
            'invite.role' => 'role',
        ]);

        $invitation = $sendInvitation->handle($this->actor(), [
            'name' => $this->invite['name'],
            'email' => $this->invite['email'],
            'phone' => $this->invite['phone'] ?: null,
            'role' => Role::from($this->invite['role']),
            'region' => $this->invite['region'] ?: null,
        ]);

        $this->showInvite = false;
        $this->tab = 'invitations';
        $this->dispatch('toast', message: "Invitation sent to {$invitation->email}.");
    }

    public function resendInvite(int $invitationId, SendInvitation $sendInvitation): void
    {
        $invitation = $this->manageableInvitation($invitationId);

        if ($invitation->accepted_at !== null) {
            return;
        }

        $sendInvitation->resend($invitation);
        $this->dispatch('toast', message: "A new link was sent to {$invitation->email}.");
    }

    public function revokeInvite(int $invitationId): void
    {
        $invitation = $this->manageableInvitation($invitationId);

        if ($invitation->isUsable()) {
            $invitation->update(['revoked_at' => now()]);
            $this->dispatch('toast', message: "Invitation for {$invitation->email} cancelled.");
        }
    }

    public function openEdit(int $userId): void
    {
        $user = $this->editableUser($userId);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->edit = [
            'role' => $user->role()?->value ?? '',
            'region' => (string) $user->region,
        ];
        $this->showEdit = true;
    }

    public function saveEdit(IssueReferralCode $issueReferralCode): void
    {
        $user = $this->editableUser((int) $this->editingUserId);

        $this->validate([
            'edit.role' => ['required', Rule::in($this->assignableRoleValues())],
            'edit.region' => ['nullable', 'string', 'max:100'],
        ], [], ['edit.role' => 'role']);

        $user->update([
            'region' => $this->edit['region'] ?: null,
        ]);

        if ($user->role()?->value !== $this->edit['role']) {
            $user->syncRoles([$this->edit['role']]);
            $issueReferralCode->handle($user->refresh());
        }

        $this->showEdit = false;
        $this->dispatch('toast', message: "{$user->name} updated.");
    }

    /**
     * Open the suspend / fire / reinstate / delete panel for a person.
     */
    public function openAccountAction(int $userId, string $action): void
    {
        abort_unless(in_array($action, ['suspend', 'terminate', 'reinstate', 'delete'], true), 404);
        $user = User::findOrFail($userId);
        Gate::authorize($action, $user);

        $this->resetValidation();
        $this->accountUserId = $user->id;
        $this->accountAction = $action;
        $this->account = ['reason' => '', 'notes' => '', 'until' => '', 'confirm' => false];
        $this->showAccount = true;
    }

    public function saveAccountAction(ChangeAccountStatus $change, DeleteUser $deleteUser): void
    {
        $user = User::findOrFail((int) $this->accountUserId);
        Gate::authorize($this->accountAction, $user);
        $actor = $this->actor();

        match ($this->accountAction) {
            'suspend' => $this->validate([
                'account.reason' => ['required', Rule::in(array_keys(AccountStatus::suspensionReasons()))],
                'account.until' => ['nullable', 'date', 'after:today'],
                'account.notes' => [Rule::requiredIf($this->account['reason'] === 'other'), 'nullable', 'string', 'max:1000'],
            ], [], ['account.reason' => 'reason', 'account.until' => 'end date', 'account.notes' => 'notes']),
            'terminate' => $this->validate([
                'account.reason' => ['required', Rule::in(array_keys(AccountStatus::terminationReasons()))],
                'account.notes' => [Rule::requiredIf($this->account['reason'] === 'other'), 'nullable', 'string', 'max:1000'],
                'account.confirm' => ['accepted'],
            ], ['account.confirm.accepted' => 'Confirm that you want to fire this person.'], ['account.reason' => 'reason', 'account.notes' => 'notes']),
            'delete' => $this->validate(['account.confirm' => ['accepted']], ['account.confirm.accepted' => 'Confirm that you want to permanently delete this account.']),
            default => null,
        };

        $name = $user->name;
        $notes = $this->account['notes'] ?: null;

        switch ($this->accountAction) {
            case 'suspend':
                $change->suspend($user, $actor, $this->account['reason'], $notes, filled($this->account['until']) ? CarbonImmutable::parse($this->account['until']) : null);
                $message = "{$name} is suspended and has been signed out.";
                break;
            case 'terminate':
                $change->terminate($user, $actor, $this->account['reason'], $notes);
                $message = "{$name} has been fired. Their records are kept.";
                break;
            case 'reinstate':
                $change->reinstate($user, $actor, $notes);
                $message = "{$name} is active again.";
                break;
            default:
                if (! $deleteUser->handle($user)) {
                    $this->addError('account.confirm', "{$name} can't be deleted because they have history: ".implode(', ', $deleteUser->blockers($user)).'. Fire them instead, so these records are kept.');

                    return;
                }
                $message = "{$name}'s account was permanently deleted.";
        }

        $this->showAccount = false;
        $this->dispatch('toast', message: $message);
    }

    public function render(): View
    {
        $actor = $this->actor();

        return view('livewire.team.index', [
            'people' => $this->tab === 'people' ? $this->people() : null,
            'invitations' => $this->tab === 'invitations' ? $this->invitations() : null,
            'assignableRoles' => Role::assignableBy($actor),
            'canManageUsers' => $actor->can(Permission::ManageUsers->value),
            'pendingCount' => Invitation::query()->pending()->count(),
            'editingUser' => $this->editingUserId ? User::find($this->editingUserId) : null,
            'accountUser' => $accountUser = ($this->showAccount && $this->accountUserId ? User::with('statusChanges.changer:id,name')->find($this->accountUserId) : null),
            'handover' => $accountUser && in_array($this->accountAction, ['suspend', 'terminate'], true) ? [
                'leads' => Lead::query()->where('user_id', $accountUser->id)->open()->count(),
                'properties' => PropertyEngagement::query()->where('sales_rep_id', $accountUser->id)->count(),
            ] : null,
            'deleteBlockers' => $accountUser && $this->accountAction === 'delete' ? app(DeleteUser::class)->blockers($accountUser) : [],
        ]);
    }

    private function people(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'referralCode'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('region', 'like', "%{$this->search}%")))
            ->when(in_array($this->status, ['active', 'suspended', 'terminated'], true), fn ($query) => $query->where('account_status', $this->status))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(15);
    }

    private function invitations(): LengthAwarePaginator
    {
        return Invitation::query()
            ->with('inviter')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->latest()
            ->paginate(15);
    }

    private function actor(): User
    {
        return Auth::user();
    }

    /**
     * @return list<string>
     */
    private function assignableRoleValues(): array
    {
        return array_map(fn (Role $role): string => $role->value, Role::assignableBy($this->actor()));
    }

    /**
     * Only people with "manage users" can change accounts, never their own,
     * and only a Super Admin can change another Super Admin.
     */
    private function editableUser(int $userId): User
    {
        $actor = $this->actor();
        $user = User::findOrFail($userId);

        abort_unless($actor->can(Permission::ManageUsers->value), 403);
        abort_if($user->is($actor), 403, 'You cannot change your own account here.');
        abort_if($user->hasRole(Role::SuperAdmin->value) && ! $actor->hasRole(Role::SuperAdmin->value), 403);

        return $user;
    }

    /**
     * Admins can manage every invitation. Sales Managers only the salesperson invitations.
     */
    private function manageableInvitation(int $invitationId): Invitation
    {
        $invitation = Invitation::findOrFail($invitationId);

        abort_unless(in_array($invitation->role, Role::assignableBy($this->actor()), true), 403);

        return $invitation;
    }
}
