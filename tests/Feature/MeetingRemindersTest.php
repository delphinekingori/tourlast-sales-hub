<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MeetingRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');
        Notification::fake();
    }

    private function timedItem(User $user, string $dueAt, array $attributes = []): FollowUp
    {
        return FollowUp::factory()->create([
            'user_id' => $user->id,
            'type' => ActivityType::Meeting,
            'has_time' => true,
            'due_at' => $dueAt,
            ...$attributes,
        ]);
    }

    public function test_the_owner_gets_one_reminder_before_a_timed_meeting_and_never_a_duplicate(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $item = $this->timedItem($john, '2026-10-20 10:25:00');

        $this->artisan('hub:send-meeting-reminders')->assertSuccessful();

        Notification::assertSentTo($john, SmartAlert::class, fn (SmartAlert $alert): bool => $alert->type === 'meeting_reminder' && str_contains($alert->body, 'starts in 25 minutes'));
        Notification::assertNotSentTo($manager, SmartAlert::class);
        $this->assertNotNull($item->fresh()->reminder_sent_at);

        Carbon::setTestNow('2026-10-20 10:10:00');
        $this->artisan('hub:send-meeting-reminders')->assertSuccessful();

        Notification::assertSentToTimes($john, SmartAlert::class, 1);
    }

    public function test_meetings_outside_the_lead_time_are_not_reminded_until_they_get_close(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $this->timedItem($john, '2026-10-20 11:00:00');

        $this->artisan('hub:send-meeting-reminders');
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-10-20 10:35:00');
        $this->artisan('hub:send-meeting-reminders');
        Notification::assertSentToTimes($john, SmartAlert::class, 1);
    }

    public function test_the_lead_time_is_configurable(): void
    {
        config(['hub.meeting_reminder_minutes' => 90]);
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $this->timedItem($john, '2026-10-20 11:00:00');

        $this->artisan('hub:send-meeting-reminders');

        Notification::assertSentToTimes($john, SmartAlert::class, 1);
    }

    public function test_rescheduling_a_reminded_item_re_arms_the_reminder(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $item = $this->timedItem($john, '2026-10-20 10:20:00');

        $this->artisan('hub:send-meeting-reminders');
        Notification::assertSentToTimes($john, SmartAlert::class, 1);

        $item->update(['due_at' => '2026-10-20 12:00:00']);
        $this->assertNull($item->fresh()->reminder_sent_at);

        Carbon::setTestNow('2026-10-20 11:40:00');
        $this->artisan('hub:send-meeting-reminders');

        Notification::assertSentToTimes($john, SmartAlert::class, 2);
    }

    public function test_completed_anytime_started_and_inactive_owner_items_are_skipped(): void
    {
        $john = User::factory()->withRole(Role::Salesperson)->create();
        $suspended = User::factory()->withRole(Role::Salesperson)->create(['is_active' => false]);

        $this->timedItem($john, '2026-10-20 10:20:00', ['completed_at' => now()]);
        $this->timedItem($john, '2026-10-20 10:20:00', ['has_time' => false]);
        $this->timedItem($john, '2026-10-20 09:50:00');
        $this->timedItem($suspended, '2026-10-20 10:20:00');

        $this->artisan('hub:send-meeting-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_travel_calendar_items_are_reminded_too(): void
    {
        $agent = User::factory()->withRole(Role::Salesperson)->create();
        $item = $this->timedItem($agent, '2026-10-20 10:15:00', ['lead_id' => null, 'type' => ActivityType::ProviderMeeting]);

        $this->artisan('hub:send-meeting-reminders');

        Notification::assertSentToTimes($agent, SmartAlert::class, 1);
        $this->assertNotNull($item->fresh()->reminder_sent_at);
    }

    public function test_the_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'hub:send-meeting-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
