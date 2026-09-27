<?php

namespace Tests\Feature\Api;

use App\Actions\ChangeAccountStatus;
use App\Actions\IssueApiToken;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_signing_in_returns_a_token_that_works(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['email' => 'john@tourlast.test']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'john@tourlast.test', 'password' => 'password', 'device_name' => 'John phone',
        ])->assertCreated()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('user.id', $john->id);

        $this->withToken($response->json('token'))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.email', 'john@tourlast.test')->assertJsonPath('data.role', 'salesperson');
    }

    public function test_wrong_password_and_inactive_accounts_cannot_sign_in(): void
    {
        User::factory()->withRole(Role::Salesperson)->create(['email' => 'john@tourlast.test']);
        User::factory()->withRole(Role::Salesperson)->inactive()->create(['email' => 'gone@tourlast.test']);

        $this->postJson('/api/v1/auth/tokens', ['email' => 'john@tourlast.test', 'password' => 'nope', 'device_name' => 'x'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/tokens', ['email' => 'gone@tourlast.test', 'password' => 'password', 'device_name' => 'x'])->assertUnprocessable();
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_token_only_works_within_its_scopes(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $token = app(IssueApiToken::class)->handle($john, 'Notifications only', ['notifications:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('required_scopes', ['profile']);
    }

    public function test_a_suspended_persons_token_stops_working_immediately(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $token = app(IssueApiToken::class)->handle($john, 'Phone', ['profile'])->plainTextToken;

        app(ChangeAccountStatus::class)->suspend($john, $admin, 'investigation');

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', 'This account is suspended. Contact your Sales Manager.');
    }

    public function test_people_can_list_and_revoke_their_own_tokens(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $first = app(IssueApiToken::class)->handle($john, 'Phone', ['profile']);
        $second = app(IssueApiToken::class)->handle($john, 'Tablet', ['profile']);

        $this->withToken($first->plainTextToken)->getJson('/api/v1/auth/tokens')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($first->plainTextToken)->deleteJson('/api/v1/auth/tokens/'.$second->accessToken->id)->assertOk();
        $this->withToken($first->plainTextToken)->deleteJson('/api/v1/auth/tokens/current')->assertOk();

        $this->assertSame(0, $john->tokens()->count());
    }

    public function test_me_endpoints_for_a_salesperson(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $token = app(IssueApiToken::class)->handle($john, 'Phone', ['profile'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/dashboard')->assertOk()->assertJsonStructure(['data' => ['today' => ['follow_ups', 'meetings', 'overdue', 'onboardings'], 'schedule', 'pipeline', 'metrics', 'trend']]);
        $this->withToken($token)->getJson('/api/v1/me/referral')->assertOk()->assertJsonStructure(['data' => ['code', 'link', 'funnel' => ['link_visits', 'applications', 'approved', 'live', 'active']]]);
        $this->withToken($token)->putJson('/api/v1/me/target', ['month' => now()->addMonth()->format('Y-m'), 'target' => 30])->assertOk()->assertJsonPath('data.target', 30);
        $this->withToken($token)->putJson('/api/v1/me/payment-details', ['method' => 'mpesa', 'mpesa_phone' => '0712345678', 'mpesa_name' => 'John Doe'])
            ->assertOk()->assertJsonPath('data.method', 'mpesa')->assertJsonPath('data.payee_name', 'JOHN DOE');
        $this->withToken($token)->getJson('/api/v1/me/earnings')->assertOk()->assertJsonStructure(['data' => ['month', 'approved' => ['points', 'total'], 'including_provisional']]);
        $this->withToken($token)->patchJson('/api/v1/me', ['job_title' => 'Senior Sales Executive'])->assertOk()->assertJsonPath('data.job_title', 'Senior Sales Executive');
    }

    public function test_meta_lists_every_option(): void
    {
        $token = app(IssueApiToken::class)->handle(User::factory()->withRole(Role::Hr)->create(), 'BI', ['reports:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/meta')->assertOk()
            ->assertJsonStructure(['data' => ['scopes', 'roles', 'property_types', 'lead_statuses', 'engagement_stages', 'objections', 'transfer_reasons']]);
    }

    public function test_announcements_need_publish_permission(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $payload = ['title' => 'Team meeting', 'body' => 'Friday 9am', 'audience' => ['everyone']];

        $this->api($john, ['notifications:write'])->postJson('/api/v1/announcements', $payload)->assertForbidden();
        $this->api($manager, ['notifications:write'])->postJson('/api/v1/announcements', $payload)->assertCreated();
    }
}
