<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_managers_see_team_objections(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->count(2)->create(['status' => LeadStatus::Lost, 'objection' => Objection::Commission, 'lost_at' => now()]);
        Lead::factory()->for($mary)->create(['status' => LeadStatus::Lost, 'objection' => Objection::OtherOta, 'competitor' => 'Booking.com', 'lost_at' => now(), 'reengage_on' => now()->addMonth()->toDateString()]);

        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['reports:read'])->getJson('/api/v1/insights/objections?period=year')
            ->assertOk()
            ->assertJsonPath('data.summary.properties_lost', 3)
            ->assertJsonPath('data.summary.top_objection.value', 'commission')
            ->assertJsonPath('data.by_objection.0.label', 'Commission')
            ->assertJsonPath('data.by_competitor.0.name', 'Booking.com')
            ->assertJsonCount(1, 'data.upcoming_reengagements')
            ->assertJsonCount(3, 'data.losses');

        $this->api($john, ['reports:read'])->getJson('/api/v1/insights/objections')->assertForbidden();
    }

    public function test_a_salesperson_sees_only_their_own_losses(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        Lead::factory()->for($john)->create(['business_name' => 'Johns Loss', 'status' => LeadStatus::Lost, 'objection' => Objection::Timing, 'lost_at' => now()]);
        Lead::factory()->for($mary)->create(['business_name' => 'Marys Loss', 'status' => LeadStatus::Lost, 'objection' => Objection::Timing, 'lost_at' => now()]);
        PropertyEngagement::factory()->forRep($john)->create(['name' => 'Represented Lodge', 'status' => 'lost', 'objection' => Objection::HasPms, 'closed_at' => now()]);

        $this->api($john, ['reports:read'])->getJson('/api/v1/me/losses')
            ->assertOk()
            ->assertJsonPath('data.summary.properties_lost', 2)
            ->assertJsonFragment(['name' => 'Johns Loss'])
            ->assertJsonFragment(['name' => 'Represented Lodge'])
            ->assertJsonMissing(['name' => 'Marys Loss']);

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['reports:read'])->getJson('/api/v1/me/losses')->assertForbidden();
    }

    public function test_partner_register_list_and_exports(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        Onboarding::factory()->forSalesperson($john)->active()->count(2)->create();
        $accounts = User::factory()->withRole(Role::Accounts)->create();

        $this->api($accounts, ['reports:read'])->getJson('/api/v1/partners?status=onboarded')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonStructure(['data' => [['property_name', 'status', 'salesperson', 'onboarded_at']]]);

        $excel = $this->api($accounts, ['reports:read'])->get('/api/v1/reports/partner-register.xlsx?status=onboarded')->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $this->api($accounts, ['reports:read'])->get('/api/v1/reports/partner-register.pdf?status=onboarded')->assertOk();
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));

        $this->api($john, ['reports:read'])->getJson('/api/v1/partners')->assertForbidden();
        $this->api($john, ['reports:read'])->getJson('/api/v1/reports/partner-register.xlsx')->assertForbidden();
    }

    public function test_registry_exports_follow_the_registry_policy(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        PropertyEngagement::factory()->count(2)->create(['first_engaged_on' => now()->startOfMonth()]);

        $excel = $this->api($manager, ['reports:read'])->get('/api/v1/reports/registry.xlsx')->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $this->api($manager, ['reports:read'])->get('/api/v1/reports/registry.pdf?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())->assertOk();
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));

        $this->api(User::factory()->withRole(Role::Salesperson)->create(), ['reports:read'])->getJson('/api/v1/reports/registry.xlsx')->assertForbidden();
    }

    public function test_reports_need_the_reports_scope(): void
    {
        $this->api(User::factory()->withRole(Role::SalesManager)->create(), ['team:read'])->getJson('/api/v1/insights/objections')
            ->assertForbidden()->assertJsonPath('required_scopes', ['reports:read']);
    }
}
