<?php

namespace Tests\Feature\Api;

use App\Actions\IssueReferralCode;
use App\Enums\OnboardingStatus;
use App\Enums\Role;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class OnboardingsApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    private User $john;

    private User $mary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->john = User::factory()->withRole(Role::Salesperson)->create();
        $this->mary = User::factory()->withRole(Role::Salesperson)->create();
        app(IssueReferralCode::class)->handle($this->john);
        app(IssueReferralCode::class)->handle($this->mary);
    }

    public function test_a_salesperson_only_sees_their_own_onboardings(): void
    {
        $mine = Onboarding::factory()->forSalesperson($this->john->fresh())->create(['property_name' => 'Kifaru Lodge']);
        $theirs = Onboarding::factory()->forSalesperson($this->mary->fresh())->create(['property_name' => 'Marys Villa']);

        $this->api($this->john, ['onboardings:read'])->getJson('/api/v1/onboardings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.property_name', 'Kifaru Lodge')
            ->assertJsonPath('data.0.progress.steps.2.state', 'current');

        $this->api($this->john, ['onboardings:read'])->getJson('/api/v1/onboardings/'.$mine->id)->assertOk()->assertJsonStructure(['data' => ['status_history', 'credit_changes', 'progress']]);
        $this->api($this->john, ['onboardings:read'])->getJson('/api/v1/onboardings/'.$theirs->id)->assertForbidden();
    }

    public function test_hr_and_managers_see_every_onboarding_with_filters(): void
    {
        Onboarding::factory()->forSalesperson($this->john->fresh())->active()->create(['property_name' => 'Live Hotel']);
        Onboarding::factory()->forSalesperson($this->mary->fresh())->create(['property_name' => 'Pending Villa']);

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['onboardings:read'])->getJson('/api/v1/onboardings')->assertOk()->assertJsonCount(2, 'data');

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['onboardings:read'])
            ->getJson('/api/v1/onboardings?status=onboarded')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property_name', 'Live Hotel')->assertJsonPath('data.0.status', 'active');

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['onboardings:read'])
            ->getJson('/api/v1/onboardings?user_id='.$this->mary->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.property_name', 'Pending Villa');
    }

    public function test_accounts_can_read_onboardings_and_a_missing_scope_is_refused(): void
    {
        $this->api(User::factory()->withRole(Role::Accounts)->create(['is_active' => true]), ['onboardings:read'])
            ->getJson('/api/v1/onboardings')
            ->assertOk();

        $this->api($this->john, ['profile'])->getJson('/api/v1/onboardings')->assertForbidden()->assertJsonPath('required_scopes', ['onboardings:read']);
    }

    public function test_only_admins_can_see_and_assign_unattributed_signups(): void
    {
        $orphan = Onboarding::factory()->create(['property_name' => 'No Code Resort', 'status' => OnboardingStatus::Approved]);
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->api($this->john, ['onboardings:read'])->getJson('/api/v1/onboardings/unattributed')->assertForbidden();
        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['onboardings:write'])
            ->postJson('/api/v1/onboardings/'.$orphan->id.'/assign', ['salesperson_id' => $this->john->id, 'reason' => 'John brought them in at the expo.'])
            ->assertForbidden();

        $this->api($admin, ['onboardings:read'])->getJson('/api/v1/onboardings/unattributed')->assertOk()->assertJsonPath('data.0.property_name', 'No Code Resort');

        $this->api($admin, ['onboardings:write'])->postJson('/api/v1/onboardings/'.$orphan->id.'/assign', ['salesperson_id' => $this->john->id, 'reason' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->api($admin, ['onboardings:write'])->postJson('/api/v1/onboardings/'.$orphan->id.'/assign', ['salesperson_id' => $this->john->id, 'reason' => 'John brought them in at the expo.'])
            ->assertOk()->assertJsonPath('data.salesperson.id', $this->john->id)->assertJsonPath('data.attribution', 'manual')
            ->assertJsonPath('data.credit_changes.0.reason', 'John brought them in at the expo.');

        $this->assertSame($this->john->id, $orphan->fresh()->user_id);
    }
}
