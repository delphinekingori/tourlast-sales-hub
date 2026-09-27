<?php

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_directory_is_for_presence_viewers_and_inviters_only(): void
    {
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['team:read'])->getJson('/api/v1/users')
            ->assertOk()->assertJsonFragment(['name' => 'John Doe']);
        $this->api(User::factory()->withRole(Role::Salesperson)->create(), ['team:read'])->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_directory_filters_by_status_and_shows_history(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $fired = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Fired Person', 'is_active' => false, 'account_status' => AccountStatus::Terminated]);
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'Working Person']);

        $this->api($admin, ['team:read'])->getJson('/api/v1/users?status=terminated')
            ->assertOk()->assertJsonFragment(['name' => 'Fired Person'])->assertJsonMissing(['name' => 'Working Person']);

        $this->api($admin, ['team:read'])->getJson('/api/v1/users/'.$fired->id)
            ->assertOk()->assertJsonPath('data.account_status', 'terminated')->assertJsonStructure(['data' => ['status_history']]);
    }

    public function test_only_admins_change_roles_and_role_change_issues_a_referral_code(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $hr = User::factory()->withRole(Role::Hr)->create(['name' => 'Peter Kamau']);

        $this->api($manager, ['team:write'])->patchJson('/api/v1/users/'.$hr->id, ['region' => 'Coast'])->assertForbidden();
        $this->api($admin, ['team:write'])->patchJson('/api/v1/users/'.$hr->id, ['role' => 'salesperson', 'region' => 'Coast'])
            ->assertOk()->assertJsonPath('data.role', 'salesperson')->assertJsonPath('data.region', 'Coast');

        $this->assertStringStartsWith('TL-PETER-', $hr->fresh()->referralCode->code);
        $this->api($admin, ['team:write'])->patchJson('/api/v1/users/'.$admin->id, ['region' => 'X'])->assertForbidden();
    }

    public function test_a_manager_suspends_and_fires_salespeople_only(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();

        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$john->id}/suspend", ['reason' => 'investigation', 'until' => now()->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('data.account_status', 'suspended');
        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$john->id}/reinstate")->assertOk()->assertJsonPath('data.account_status', 'active');

        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$john->id}/terminate", ['reason' => 'misconduct'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$john->id}/terminate", ['reason' => 'misconduct', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.account_status', 'terminated');

        // Only admins bring back someone who was fired; managers cannot touch non-salespeople.
        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$john->id}/reinstate")->assertForbidden();
        $this->api($manager, ['team:write'])->postJson("/api/v1/users/{$hr->id}/suspend", ['reason' => 'investigation'])->assertForbidden();
    }

    public function test_hr_cannot_suspend_fire_or_delete(): void
    {
        $hr = User::factory()->withRole(Role::Hr)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();

        $this->api($hr, ['team:write'])->postJson("/api/v1/users/{$john->id}/suspend", ['reason' => 'investigation'])->assertForbidden();
        $this->api($hr, ['team:write'])->postJson("/api/v1/users/{$john->id}/terminate", ['reason' => 'misconduct', 'confirm' => true])->assertForbidden();
        $this->api($hr, ['team:write'])->deleteJson("/api/v1/users/{$john->id}", ['confirm' => true])->assertForbidden();
    }

    public function test_only_admins_delete_and_history_blocks_deletion(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $mistake = User::factory()->withRole(Role::Salesperson)->create();
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->count(2)->create();

        $this->api($manager, ['team:write'])->deleteJson("/api/v1/users/{$mistake->id}", ['confirm' => true])->assertForbidden();

        $this->api($admin, ['team:write'])->deleteJson("/api/v1/users/{$john->id}", ['confirm' => true])
            ->assertStatus(409)->assertJsonPath('blockers', ['2 leads']);
        $this->assertModelExists($john);

        $this->api($admin, ['team:write'])->deleteJson("/api/v1/users/{$mistake->id}", ['confirm' => true])->assertOk();
        $this->assertModelMissing($mistake);
    }

    public function test_invitations_respect_role_limits(): void
    {
        Mail::fake();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->api($manager, ['team:write'])->postJson('/api/v1/invitations', ['name' => 'Lucy', 'email' => 'lucy@tourlast.test', 'role' => 'hr'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->api($manager, ['team:write'])->postJson('/api/v1/invitations', ['name' => 'Lucy', 'email' => 'Lucy@Tourlast.test', 'role' => 'salesperson'])
            ->assertCreated()->assertJsonPath('data.email', 'lucy@tourlast.test')->assertJsonPath('data.status', 'pending');

        $hrInvite = Invitation::factory()->for($admin, 'inviter')->create(['role' => Role::Hr]);
        $this->api($manager, ['team:write'])->deleteJson('/api/v1/invitations/'.$hrInvite->id)->assertForbidden();
        $this->api($admin, ['team:write'])->deleteJson('/api/v1/invitations/'.$hrInvite->id)->assertOk();
        $this->assertNotNull($hrInvite->fresh()->revoked_at);

        $this->api($manager, ['team:read'])->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_team_performance_and_targets_are_for_managers(): void
    {
        User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['team:read'])->getJson('/api/v1/team/performance?period=month')
            ->assertOk()->assertJsonFragment(['name' => 'John Doe'])->assertJsonPath('meta.pay_visible', false)
            ->assertJsonStructure(['data' => [['user', 'metrics', 'status', 'progress', 'expected_pay']], 'meta' => ['totals']]);
        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['team:read'])->getJson('/api/v1/team/targets?month='.now()->format('Y-m'))
            ->assertOk()->assertJsonFragment(['name' => 'John Doe', 'target' => null])->assertJsonPath('meta.month', now()->format('Y-m'));

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['team:read'])->getJson('/api/v1/team/performance')->assertForbidden();
    }

    public function test_missing_scope_is_rejected(): void
    {
        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['team:read'])
            ->postJson('/api/v1/invitations', [])->assertForbidden()->assertJsonPath('required_scopes', ['team:write']);
    }

    public function test_admins_issue_and_revoke_tokens_but_not_for_super_admins(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $super = User::factory()->withRole(Role::SuperAdmin)->create();
        $service = User::factory()->withRole(Role::Salesperson)->create();

        $response = $this->api($admin, ['team:write'])->postJson('/api/v1/admin/tokens', ['user_id' => $service->id, 'name' => 'BI export', 'scopes' => ['reports:read'], 'expires_in_days' => 30])
            ->assertCreated()->assertJsonPath('data.scopes', ['reports:read'])->assertJsonPath('data.owner.id', $service->id)->assertJsonPath('data.issued_by.id', $admin->id);
        $this->assertNotEmpty($response->json('token'));

        $this->api($admin, ['team:write'])->postJson('/api/v1/admin/tokens', ['user_id' => $super->id, 'name' => 'x', 'scopes' => ['profile']])->assertForbidden();
        $this->api($admin, ['team:write'])->getJson('/api/v1/admin/tokens?user_id='.$service->id)->assertOk()->assertJsonCount(1, 'data');

        $id = PersonalAccessToken::query()->where('tokenable_id', $service->id)->value('id');
        $this->api($admin, ['team:write'])->deleteJson('/api/v1/admin/tokens/'.$id)->assertOk();
        $this->assertSame(0, $service->tokens()->count());

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['team:write'])->getJson('/api/v1/admin/tokens')->assertForbidden();
    }
}
