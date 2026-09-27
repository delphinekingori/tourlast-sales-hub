<?php

namespace Tests\Feature;

use App\Actions\IssueApiToken;
use App\Enums\Role;
use App\Livewire\Admin\ApiTokens;
use App\Livewire\Profile;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sales_admin_issues_a_scoped_token_shown_once(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $service = User::factory()->withRole(Role::Hr)->create(['name' => 'tourlast.com service']);

        $component = Livewire::actingAs($admin)->test(ApiTokens::class)
            ->call('openCreate')
            ->set('form.user_id', (string) $service->id)
            ->call('applyPreset', 'tourlast')
            ->set('form.expires', 'never')
            ->call('create')
            ->assertHasNoErrors();

        $token = PersonalAccessToken::sole();
        $this->assertSame(['integration:push'], $token->scopes());
        $this->assertSame($admin->id, $token->issued_by);
        $this->assertNull($token->expires_at);
        $this->assertStringContainsString('|', (string) $component->get('plainToken'));
    }

    public function test_only_token_managers_can_open_the_page(): void
    {
        foreach ([Role::SalesManager, Role::Salesperson, Role::Hr, Role::Accounts] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->get(route('admin.api-tokens'))->assertForbidden();
        }

        $this->actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->get(route('admin.api-tokens'))->assertOk();
    }

    public function test_a_sales_admin_cannot_issue_or_revoke_super_admin_tokens(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $super = User::factory()->withRole(Role::SuperAdmin)->create();

        Livewire::actingAs($admin)->test(ApiTokens::class)
            ->call('openCreate')->set('form.user_id', (string) $super->id)->set('form.name', 'x')->set('form.scopes', ['profile'])
            ->call('create')->assertForbidden();

        $existing = app(IssueApiToken::class)->handle($super, 'Super phone', ['profile']);
        Livewire::actingAs($admin)->test(ApiTokens::class)->call('revoke', $existing->accessToken->id)->assertForbidden();
        $this->assertModelExists($existing->accessToken);
    }

    public function test_people_see_and_revoke_their_own_tokens_on_their_profile(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $token = app(IssueApiToken::class)->handle($john, 'John phone', ['profile']);

        Livewire::actingAs($john)->test(Profile::class)
            ->assertSee('John phone')
            ->call('revokeToken', $token->accessToken->id);

        $this->assertModelMissing($token->accessToken);
    }
}
