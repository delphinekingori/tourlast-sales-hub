<?php

namespace App\Livewire\Admin;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Issue and revoke Sales Hub API tokens (Super Admin and Sales Admin).
 */
#[Title('API tokens')]
class ApiTokens extends Component
{
    use WithPagination;

    /**
     * Ready-made scope sets for the common integrations.
     */
    public const Presets = [
        'tourlast' => ['label' => 'tourlast.com sync (Hub admin)', 'scopes' => ['integration:push']],
        'reporting' => ['label' => 'Reporting / BI (read only)', 'scopes' => ['registry:read', 'onboardings:read', 'incentives:read', 'team:read', 'reports:read']],
        'mobile' => ['label' => 'Mobile app (salesperson)', 'scopes' => ['profile', 'leads:read', 'leads:write', 'schedule:read', 'schedule:write', 'registry:read', 'onboardings:read', 'incentives:read', 'claims:read', 'claims:write', 'notifications:read', 'notifications:write']],
    ];

    #[Url]
    public string $owner = '';

    public bool $showCreate = false;

    /** @var array{user_id: string, name: string, scopes: list<string>, expires: string} */
    public array $form = ['user_id' => '', 'name' => '', 'scopes' => [], 'expires' => '90'];

    /** Shown once, straight after creation. */
    public ?string $plainToken = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageApiTokens->value), 403);
    }

    public function updatingOwner(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->form = ['user_id' => '', 'name' => '', 'scopes' => [], 'expires' => '90'];
        $this->plainToken = null;
        $this->showCreate = true;
    }

    public function applyPreset(string $preset): void
    {
        abort_unless(isset(self::Presets[$preset]), 404);
        $this->form['scopes'] = self::Presets[$preset]['scopes'];
        $this->form['name'] = $this->form['name'] ?: self::Presets[$preset]['label'];
    }

    public function create(IssueApiToken $issueApiToken): void
    {
        abort_unless(Auth::user()->can(Permission::ManageApiTokens->value), 403);

        $this->validate([
            'form.user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
            'form.name' => ['required', 'string', 'max:100'],
            'form.scopes' => ['required', 'array', 'min:1'],
            'form.scopes.*' => [Rule::in(ApiScope::values())],
            'form.expires' => ['required', Rule::in(['30', '90', '365', 'never'])],
        ], ['form.scopes.required' => 'Choose at least one scope.'], ['form.user_id' => 'person', 'form.name' => 'token name']);

        $owner = User::findOrFail((int) $this->form['user_id']);
        abort_if($owner->hasRole(Role::SuperAdmin->value) && ! Auth::user()->hasRole(Role::SuperAdmin->value), 403, 'Only a Super Admin can issue tokens for a Super Admin.');

        $token = $issueApiToken->handle(
            $owner,
            $this->form['name'],
            array_values($this->form['scopes']),
            $this->form['expires'] === 'never' ? null : CarbonImmutable::now()->addDays((int) $this->form['expires']),
            Auth::user(),
        );

        $this->plainToken = $token->plainTextToken;
        $this->dispatch('toast', message: "Token created for {$owner->name}. Copy it now: it won't be shown again.");
    }

    public function revoke(int $tokenId): void
    {
        abort_unless(Auth::user()->can(Permission::ManageApiTokens->value), 403);
        $token = PersonalAccessToken::query()->with('tokenable')->findOrFail($tokenId);
        abort_if($token->tokenable instanceof User && $token->tokenable->hasRole(Role::SuperAdmin->value) && ! Auth::user()->hasRole(Role::SuperAdmin->value), 403);

        $token->delete();
        $this->dispatch('toast', message: 'Token revoked. Apps using it stop working immediately.');
    }

    public function render(): View
    {
        return view('livewire.admin.api-tokens', [
            'tokens' => PersonalAccessToken::query()
                ->with(['tokenable', 'issuer:id,name'])
                ->where('tokenable_type', (new User)->getMorphClass())
                ->when($this->owner !== '', fn ($query) => $query->where('tokenable_id', (int) $this->owner))
                ->latest()
                ->paginate(25),
            'people' => User::query()->active()->orderBy('name')->get(['id', 'name', 'email']),
            'scopes' => ApiScope::cases(),
        ]);
    }
}
