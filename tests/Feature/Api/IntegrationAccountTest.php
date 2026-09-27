<?php

namespace Tests\Feature\Api;

use App\Actions\IssueApiToken;
use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class IntegrationAccountTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_the_integration_account_can_only_push_provider_records(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john)->code;

        Artisan::call('hub:create-integration-account');
        $account = User::query()->where('email', 'integration@tourlast.com')->sole();
        $this->assertNull($account->role());
        $this->assertTrue($account->can('push-provider-records'));
        $this->assertFalse($account->can('manage-integration'));
        $this->assertSame(['integration:push'], $account->tokens()->sole()->scopes());

        $this->api($account, ['integration:push'])->postJson('/api/v1/integrations/tourlast/providers', ['provider' => [
            'property_id' => 'TL-9001', 'ref_code' => $code, 'property_name' => 'Kijani Lodge', 'property_type' => 'lodge',
            'status' => 'submitted', 'submitted_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]])->assertOk();

        $this->assertSame($john->id, Onboarding::query()->where('tourlast_property_id', 'TL-9001')->value('user_id'));

        // Even with extra scopes, the account has no other permissions.
        $this->api($account)->postJson('/api/v1/integrations/tourlast/sync')->assertForbidden();
        $this->api($account)->getJson('/api/v1/registry?per_page=1')->assertForbidden();
        $this->api($account)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_rotating_revokes_old_tokens(): void
    {
        Artisan::call('hub:create-integration-account');
        $account = User::query()->where('email', 'integration@tourlast.com')->sole();
        $old = $account->tokens()->sole();

        Artisan::call('hub:create-integration-account', ['--rotate' => true]);

        $this->assertModelMissing($old);
        $this->assertSame(1, $account->tokens()->count());
    }

    public function test_ordinary_staff_cannot_push_even_with_the_scope(): void
    {
        $hr = User::factory()->withRole(Role::Hr)->create();
        $token = app(IssueApiToken::class)->handle($hr, 'x', ['integration:push'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/integrations/tourlast/providers', ['provider' => ['property_id' => 'X']])->assertForbidden();
    }
}
