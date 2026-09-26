<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookAndClicksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_webhook_creates_and_credits_an_onboarding(): void
    {
        config(['tourlast.webhook_secret' => 'shh']);
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john)->code;

        $body = json_encode(['event_id' => 'evt_1', 'provider' => [
            'property_id' => 'TL-00842', 'ref_code' => $code, 'property_name' => 'ABC Hotel',
            'property_type' => 'hotel', 'status' => 'active', 'active_at' => '2026-09-24T10:12:00+03:00',
        ]]);

        $this->call('POST', '/webhooks/tourlast', [], [], [], $this->headers($body, 'shh'), $body)
            ->assertOk()->assertJson(['result' => 'created']);

        $this->call('POST', '/webhooks/tourlast', [], [], [], $this->headers($body, 'shh'), $body)
            ->assertOk()->assertJson(['result' => 'duplicate']);

        $onboarding = Onboarding::sole();
        $this->assertTrue($onboarding->user->is($john));
        $this->assertNotNull($onboarding->credited_at);
        $this->assertSame('webhook', $onboarding->statusChanges->first()->source);
    }

    public function test_webhooks_with_a_bad_signature_or_no_secret_are_refused(): void
    {
        $body = json_encode(['provider' => ['property_id' => 'X']]);

        $this->call('POST', '/webhooks/tourlast', [], [], [], $this->headers($body, 'anything'), $body)->assertNotFound();

        config(['tourlast.webhook_secret' => 'shh']);
        $this->call('POST', '/webhooks/tourlast', [], [], [], $this->headers($body, 'wrong'), $body)->assertUnauthorized();

        $this->assertSame(0, Onboarding::count());
    }

    public function test_clicks_on_a_tracked_link_are_counted_but_link_previews_are_not(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->get('/r/'.$code->code)->assertRedirect();
        $this->withHeader('User-Agent', 'WhatsApp/2.23.20')->get('/r/'.$code->code)->assertRedirect();

        $this->assertSame(1, ReferralClick::count());
        $this->assertNull(ReferralClick::sole()->lead_id);
    }

    public function test_a_lead_tag_is_recorded_only_for_the_code_owners_own_lead(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $code = app(IssueReferralCode::class)->handle($john);
        $johnsLead = Lead::factory()->for($john)->create();
        $someoneElsesLead = Lead::factory()->create();

        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/r/'.$code->code.'?l='.$johnsLead->id);
        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/r/'.$code->code.'?l='.$someoneElsesLead->id);

        $this->assertSame([$johnsLead->id, null], ReferralClick::orderBy('id')->pluck('lead_id')->all());
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $body, string $secret): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TOURLAST_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ];
    }
}
