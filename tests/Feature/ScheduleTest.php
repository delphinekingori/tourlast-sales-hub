<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Livewire\Calendar\Index as Calendar;
use App\Livewire\Dashboard;
use App\Livewire\Registry\Show as RegistryShow;
use App\Livewire\Schedule\Editor;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesperson_schedules_a_meeting_with_a_time_and_contact(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel', 'contact_name' => 'John Wambua', 'contact_role' => 'General Manager']);

        Livewire::actingAs($john)->test(Editor::class)
            ->call('open', leadId: $lead->id)
            ->assertSet('form.contact_name', 'John Wambua')
            ->set('form.type', 'meeting')
            ->set('form.task', 'Proposal discussion')
            ->set('form.date', now()->addDays(2)->toDateString())
            ->set('form.time', '11:30')
            ->set('form.duration', '60')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('schedule-saved');

        $item = FollowUp::sole();
        $this->assertSame(ActivityType::Meeting, $item->type);
        $this->assertTrue($item->has_time);
        $this->assertSame('11:30–12:30', $item->timeLabel());
        $this->assertSame('General Manager', $item->contact_role);
        $this->assertTrue($item->isMeeting());
    }

    public function test_salespeople_cannot_schedule_on_or_edit_someone_elses_leads(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $mary = User::factory()->withRole(Role::Salesperson)->create();
        $marysLead = Lead::factory()->for($mary)->create();
        $marysItem = FollowUp::factory()->for($mary)->for($marysLead)->create();

        Livewire::actingAs($john)->test(Editor::class)
            ->call('open')
            ->set('form.lead_id', (string) $marysLead->id)
            ->set('form.task', 'Sneaky')
            ->call('save')
            ->assertHasErrors('form.lead_id');

        Livewire::actingAs($john)->test(Editor::class)->call('open', itemId: $marysItem->id)->assertNotFound();
        $this->assertSame(1, FollowUp::count());
    }

    public function test_completing_a_meeting_logs_the_outcome_and_books_the_next_follow_up(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->for($john)->create();
        $item = FollowUp::factory()->for($john)->for($lead)->create([
            'type' => ActivityType::Meeting, 'task' => 'Proposal discussion', 'due_at' => now()->subHour(), 'has_time' => true,
        ]);

        Livewire::actingAs($john)->test(Editor::class)
            ->call('open', itemId: $item->id, complete: true)
            ->assertSet('mode', 'complete')
            ->set('done.outcome', 'GM requested a commercial proposal.')
            ->set('done.next_action', 'Send proposal')
            ->set('done.next_date', now()->addDays(3)->toDateString())
            ->set('done.next_time', '10:00')
            ->call('complete')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertNotNull($item->completed_at);

        $activity = Activity::sole();
        $this->assertSame($activity->id, $item->outcome_activity_id);
        $this->assertSame(ActivityType::Meeting, $activity->type);
        $this->assertStringContainsString('GM requested a commercial proposal.', $activity->notes);

        $next = FollowUp::query()->whereNull('completed_at')->sole();
        $this->assertSame('Send proposal', $next->task);
        $this->assertTrue($next->has_time);
        $this->assertSame('10:00', $next->due_at->format('H:i'));
    }

    public function test_the_dashboard_shows_today_counts_and_schedule(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $abc = Lead::factory()->for($john)->create(['business_name' => 'ABC Hotel']);
        $xyz = Lead::factory()->for($john)->create(['business_name' => 'XYZ Resort']);
        $apartments = Lead::factory()->for($john)->create(['business_name' => 'ABC Apartments']);
        FollowUp::factory()->for($john)->for($apartments)->create(['type' => ActivityType::Call, 'task' => 'Follow-up', 'due_at' => now()->setTime(9, 0), 'has_time' => true]);
        FollowUp::factory()->for($john)->for($abc)->create(['type' => ActivityType::Meeting, 'task' => 'Proposal', 'due_at' => now()->setTime(11, 30), 'has_time' => true]);
        FollowUp::factory()->for($john)->for($xyz)->create(['type' => ActivityType::SiteVisit, 'task' => 'Visit', 'due_at' => now()->setTime(14, 0), 'has_time' => true]);
        FollowUp::factory()->for($john)->for($abc)->create(['due_at' => now()->subDays(2)]);

        Livewire::actingAs($john)->test(Dashboard::class)
            ->assertViewHas('today', fn (array $today) => $today['follow_ups'] === 1 && $today['meetings'] === 2 && $today['overdue'] === 1)
            ->assertSeeInOrder(['ABC Apartments', 'ABC Hotel', 'XYZ Resort'])
            ->assertSee('Today\'s schedule')
            ->assertSee('My sales pipeline')
            ->assertSee('My referrals');
    }

    public function test_calendar_views_and_team_visibility(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        FollowUp::factory()->for($john)->for(Lead::factory()->for($john)->create(['business_name' => 'Johns Lodge']))->create(['due_at' => now()->setTime(10, 0), 'has_time' => true]);
        FollowUp::factory()->for($mary)->for(Lead::factory()->for($mary)->create(['business_name' => 'Marys Villa']))->create(['due_at' => now()->setTime(12, 0), 'has_time' => true]);

        foreach (['day', 'week', 'month'] as $view) {
            Livewire::actingAs($john)->test(Calendar::class)->set('view', $view)
                ->assertSee('Johns Lodge')->assertDontSee('Marys Villa');
        }

        // Salespeople can't peek at a colleague's calendar by changing the filter.
        Livewire::actingAs($john)->test(Calendar::class)->set('rep', (string) $mary->id)->assertDontSee('Marys Villa');

        $manager = User::factory()->withRole(Role::SalesManager)->create();
        Livewire::actingAs($manager)->test(Calendar::class)->set('rep', 'team')
            ->assertSee('Johns Lodge')->assertSee('Marys Villa')
            ->set('rep', (string) $mary->id)
            ->assertDontSee('Johns Lodge')->assertSee('Marys Villa');

        $this->actingAs(User::factory()->withRole(Role::Accounts)->create())->get(route('calendar.index'))->assertForbidden();
    }

    public function test_registry_history_includes_lead_activity_and_upcoming_meetings(): void
    {
        $mary = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Mary Wambua']);
        $engagement = PropertyEngagement::factory()->forRep($mary)->create(['name' => 'ABC Hotel']);
        $lead = Lead::factory()->for($mary)->create(['property_engagement_id' => $engagement->id]);
        Activity::factory()->for($lead)->for($mary)->create(['type' => ActivityType::Call, 'notes' => 'GM confirmed interest.']);
        FollowUp::factory()->for($mary)->for($lead)->create([
            'type' => ActivityType::Meeting, 'task' => 'Proposal discussion', 'due_at' => now()->addDays(2)->setTime(11, 30),
            'has_time' => true, 'contact_name' => 'John Wambua', 'contact_role' => 'General Manager',
        ]);

        Livewire::actingAs(User::factory()->withRole(Role::Salesperson)->create())
            ->test(RegistryShow::class, ['engagement' => $engagement->id])
            ->assertSee('Upcoming')
            ->assertSee('Proposal discussion')
            ->assertSee('John Wambua, General Manager')
            ->assertSee('GM confirmed interest.')
            ->assertSee('via lead');

        $this->assertSame(0, $engagement->events()->count(), 'Lead activity is read into the history, not copied into the registry.');
    }

    public function test_navigation_shows_calendar(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('dashboard'))->assertSee('Calendar');
        $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create())->get(route('dashboard'))->assertSee('Team calendar');
    }
}
