<?php

namespace Tests\Feature\Api;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    public function test_a_salesperson_schedules_a_meeting_on_their_lead(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel']);

        $this->api($john, ['schedule:write'])->postJson('/api/v1/schedule', [
            'lead_id' => $lead->id, 'type' => 'meeting', 'title' => 'Proposal discussion',
            'date' => now()->addDays(2)->toDateString(), 'time' => '11:30', 'duration_minutes' => 60,
            'contact_name' => 'John Wambua', 'contact_role' => 'General Manager',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'meeting')
            ->assertJsonPath('data.time_label', '11:30–12:30')
            ->assertJsonPath('data.lead.business_name', 'ABC Hotel')
            ->assertJsonPath('data.is_meeting', true);
    }

    public function test_scheduling_is_only_on_your_own_leads_and_for_sellers(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $marysLead = Lead::factory()->for(User::factory()->withRole(Role::Salesperson))->create();

        $this->api($john, ['schedule:write'])->postJson('/api/v1/schedule', [
            'lead_id' => $marysLead->id, 'type' => 'call', 'title' => 'Call', 'date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('lead_id');

        $this->api(User::factory()->withRole(Role::Hr)->create(), ['schedule:write'])->postJson('/api/v1/schedule', [
            'lead_id' => $marysLead->id, 'type' => 'call', 'title' => 'Call', 'date' => now()->toDateString(),
        ])->assertForbidden();

        $this->api($john, ['schedule:read'])->postJson('/api/v1/schedule', [])->assertForbidden()->assertJsonPath('required_scopes', ['schedule:write']);
    }

    public function test_only_the_owner_edits_completes_or_removes_an_item(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();
        $item = FollowUp::factory()->for($john)->for($lead)->create(['type' => ActivityType::Meeting, 'task' => 'Proposal', 'due_at' => now()->addDay()->setTime(9, 0), 'has_time' => true]);

        $this->api($mary, ['schedule:write'])->patchJson("/api/v1/schedule/{$item->id}", ['title' => 'Hijack'])->assertForbidden();
        $this->api($mary, ['schedule:write'])->deleteJson("/api/v1/schedule/{$item->id}")->assertForbidden();

        $this->api($john, ['schedule:write'])->patchJson("/api/v1/schedule/{$item->id}", ['title' => 'Proposal discussion', 'time' => '14:00'])
            ->assertOk()->assertJsonPath('data.title', 'Proposal discussion')->assertJsonPath('data.type', 'meeting');
        $this->assertSame('14:00', $item->fresh()->due_at->format('H:i'));
    }

    public function test_completing_logs_the_outcome_and_books_the_next_step(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();
        $item = FollowUp::factory()->for($john)->for($lead)->create(['type' => ActivityType::Meeting, 'task' => 'Proposal discussion', 'due_at' => now()->subHour(), 'has_time' => true]);

        $this->api($john, ['schedule:write'])->postJson("/api/v1/schedule/{$item->id}/complete", [
            'outcome' => 'GM requested a commercial proposal.', 'next_action' => 'Send proposal',
            'next_date' => now()->addDays(3)->toDateString(), 'next_time' => '10:00',
        ])->assertOk()->assertJsonPath('data.completed_at', fn ($value) => $value !== null);

        $this->assertStringContainsString('GM requested a commercial proposal.', Activity::sole()->notes);
        $next = FollowUp::query()->whereNull('completed_at')->sole();
        $this->assertSame('Send proposal', $next->task);
        $this->assertSame('10:00', $next->due_at->format('H:i'));

        $this->api($john, ['schedule:write'])->postJson("/api/v1/schedule/{$item->id}/complete", [])->assertStatus(422);
        $this->api($john, ['schedule:write'])->deleteJson("/api/v1/schedule/{$item->id}")->assertStatus(422);
        $this->api($john, ['schedule:write'])->deleteJson("/api/v1/schedule/{$next->id}")->assertOk();
    }

    public function test_calendar_scoping_matches_the_web_calendar(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        FollowUp::factory()->for($john)->for(Lead::factory()->for($john)->create(['business_name' => 'Johns Lodge']))->create(['due_at' => now()->setTime(10, 0), 'has_time' => true]);
        FollowUp::factory()->for($mary)->for(Lead::factory()->for($mary)->create(['business_name' => 'Marys Villa']))->create(['due_at' => now()->setTime(12, 0), 'has_time' => true]);

        $this->api($john, ['schedule:read'])->getJson('/api/v1/schedule')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lead.business_name', 'Johns Lodge');

        // A salesperson can't look at a colleague's calendar by changing the filter.
        $this->api($john, ['schedule:read'])->getJson('/api/v1/schedule?user='.$mary->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.lead.business_name', 'Johns Lodge');

        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $this->api($manager, ['schedule:read'])->getJson('/api/v1/schedule?user=team')->assertJsonCount(2, 'data')->assertJsonPath('meta.user', 'team');
        $this->api($manager, ['schedule:read'])->getJson('/api/v1/schedule?user='.$mary->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.lead.business_name', 'Marys Villa');

        $this->api(User::factory()->withRole(Role::Accounts)->create(), ['schedule:read'])->getJson('/api/v1/schedule')->assertForbidden();
    }

    public function test_date_range_filter(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();
        FollowUp::factory()->for($john)->for($lead)->create(['due_at' => now()->addMonth()->startOfDay()]);

        $this->api($john, ['schedule:read'])->getJson('/api/v1/schedule')->assertJsonCount(0, 'data');
        $this->api($john, ['schedule:read'])->getJson('/api/v1/schedule?from='.now()->addMonth()->subDay()->toDateString().'&to='.now()->addMonth()->addDay()->toDateString())->assertJsonCount(1, 'data');
        $this->api($john, ['schedule:read'])->getJson('/api/v1/schedule?from=2026-01-01&to=2026-12-31')->assertStatus(422);
    }
}
