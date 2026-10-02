<?php

namespace Tests\Feature\Api;

use App\Actions\IssueReferralCode;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Models\Onboarding;
use App\Models\ReferralCode;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_tourlast_can_push_providers_and_they_are_credited_by_ref_code(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john)->code;

        $this->sharedToken('shared-secret')->postJson('/api/v1/integrations/tourlast/providers', ['providers' => [
            ['property_id' => 'TL-00842', 'ref_code' => strtolower($code), 'property_name' => 'ABC Hotel', 'property_type' => 'hotel', 'status' => 'approved', 'submitted_at' => now()->subDays(3)->toIso8601String(), 'approved_at' => now()->toIso8601String()],
            ['ref_code' => $code, 'property_name' => 'No ID'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.0.result', 'created')
            ->assertJsonPath('data.1.error', 'A provider record is missing its property_id.')
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('meta.failed', 1);

        $onboarding = Onboarding::query()->where('tourlast_property_id', 'TL-00842')->sole();
        $this->assertSame($john->id, $onboarding->user_id);
        $this->assertSame(OnboardingStatus::Approved, $onboarding->status);

        // A later status change for the same property updates it rather than duplicating it.
        $this->sharedToken('shared-secret')->postJson('/api/v1/integrations/tourlast/providers', ['provider' => [
            'property_id' => 'TL-00842', 'ref_code' => $code, 'property_name' => 'ABC Hotel', 'property_type' => 'hotel', 'status' => 'active',
            'active_at' => now()->toIso8601String(),
        ]])->assertOk()->assertJsonPath('data.0.result', 'updated');

        $this->assertSame(1, Onboarding::count());
        $this->assertSame(OnboardingStatus::Active, $onboarding->fresh()->status);
    }

    public function test_push_needs_the_shared_token_and_no_user_account(): void
    {
        $payload = ['provider' => ['property_id' => 'TL-1', 'status' => 'submitted']];
        $url = '/api/v1/integrations/tourlast/providers';

        // No token at all.
        config(['tourlast.api.token' => 'shared-secret']);
        $this->app['auth']->forgetGuards();
        $this->postJson($url, $payload)->assertUnauthorized();

        // Wrong token.
        $this->withToken('wrong-secret')->postJson($url, $payload)->assertUnauthorized();

        // A personal Sanctum token — even a Super Admin's with every scope — is not the shared token.
        $this->api(User::factory()->withRole(Role::SuperAdmin)->create())->postJson($url, $payload)->assertUnauthorized();

        $this->assertSame(0, Onboarding::count());

        // The right token still validates the payload.
        $this->sharedToken('shared-secret')->postJson($url, [])->assertUnprocessable();
        $this->assertSame(0, Onboarding::count());
    }

    public function test_push_explains_that_the_hub_has_no_shared_token_yet(): void
    {
        config(['tourlast.api.token' => '']);
        $this->app['auth']->forgetGuards();

        $this->withToken('anything')->postJson('/api/v1/integrations/tourlast/providers', ['provider' => ['property_id' => 'TL-1']])
            ->assertStatus(503)
            ->assertJsonPath('message', 'This Hub has no shared sync token yet. Run php artisan hub:generate-token.');
    }

    public function test_source_apps_pull_the_active_ref_codes(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $johnCode = app(IssueReferralCode::class)->handle($john)->code;
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambui']);
        $maryCode = app(IssueReferralCode::class)->handle($mary)->code;
        ReferralCode::factory()->create(['code' => 'TL-OLD-1111', 'is_active' => false]);

        $this->sharedToken('shared-secret')->getJson('/api/v1/integrations/tourlast/ref-codes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', $johnCode)
            ->assertJsonPath('data.0.user_name', 'John Doe')
            ->assertJsonPath('data.1.code', $maryCode)
            ->assertJsonPath('data.1.user_name', 'Mary Wambui')
            ->assertJsonMissingPath('data.2');
    }

    public function test_ref_codes_need_the_shared_token(): void
    {
        User::factory()->withRole(Role::Salesperson)->create();

        $this->getJson('/api/v1/integrations/tourlast/ref-codes')->assertUnauthorized();
        $this->withToken('wrong-secret')->getJson('/api/v1/integrations/tourlast/ref-codes')->assertUnauthorized();

        // A personal Sanctum token is not the shared token either.
        $this->api(User::factory()->withRole(Role::SuperAdmin)->create())->getJson('/api/v1/integrations/tourlast/ref-codes')->assertUnauthorized();

        config(['tourlast.api.token' => '']);
        $this->withToken('anything')->getJson('/api/v1/integrations/tourlast/ref-codes')
            ->assertStatus(503)
            ->assertJsonPath('message', 'This Hub has no shared sync token yet. Run php artisan hub:generate-token.');
    }

    public function test_sync_log_and_manual_sync(): void
    {
        config(['tourlast.source' => 'sandbox']);
        $super = User::factory()->withRole(Role::SuperAdmin)->create();

        $this->api($super, ['integration:push'])->postJson('/api/v1/integrations/tourlast/sync', ['mode' => 'full'])
            ->assertOk()->assertJsonPath('data.mode', 'full')->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(1, SyncRun::count());

        $this->api($super, ['integration:read'])->getJson('/api/v1/integrations/tourlast/sync-runs')->assertOk()->assertJsonCount(1, 'data');
        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['integration:read'])->getJson('/api/v1/integrations/tourlast/sync-runs')->assertForbidden();
    }

    /**
     * Send the feed requests as a source app would: only the shared .env token.
     */
    private function sharedToken(string $token): static
    {
        config(['tourlast.api.token' => $token]);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
