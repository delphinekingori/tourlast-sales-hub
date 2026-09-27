<?php

namespace Tests\Feature\Api;

use App\Actions\IssueReferralCode;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Models\Onboarding;
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
        $service = User::factory()->withRole(Role::SuperAdmin)->create(['name' => 'tourlast.com integration']);

        $this->api($service, ['integration:push'])->postJson('/api/v1/integrations/tourlast/providers', ['providers' => [
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
        $this->api($service, ['integration:push'])->postJson('/api/v1/integrations/tourlast/providers', ['provider' => [
            'property_id' => 'TL-00842', 'ref_code' => $code, 'property_name' => 'ABC Hotel', 'property_type' => 'hotel', 'status' => 'active',
            'active_at' => now()->toIso8601String(),
        ]])->assertOk()->assertJsonPath('data.0.result', 'updated');

        $this->assertSame(1, Onboarding::count());
        $this->assertSame(OnboardingStatus::Active, $onboarding->fresh()->status);
    }

    public function test_push_needs_the_scope_and_the_integration_permission(): void
    {
        $payload = ['provider' => ['property_id' => 'TL-1', 'status' => 'submitted']];

        $this->api(User::factory()->withRole(Role::SuperAdmin)->create(), ['profile'])->postJson('/api/v1/integrations/tourlast/providers', $payload)
            ->assertForbidden()->assertJsonPath('required_scopes', ['integration:push']);
        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['integration:push'])->postJson('/api/v1/integrations/tourlast/providers', $payload)->assertForbidden();
        $this->api(User::factory()->withRole(Role::SuperAdmin)->create(), ['integration:push'])->postJson('/api/v1/integrations/tourlast/providers', [])->assertUnprocessable();
        $this->assertSame(0, Onboarding::count());
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
}
