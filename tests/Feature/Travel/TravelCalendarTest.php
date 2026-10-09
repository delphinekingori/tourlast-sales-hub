<?php

namespace Tests\Feature\Travel;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Livewire\Schedule\Editor;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Travel\TravelSubjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TravelCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_travel_salesperson_schedules_a_follow_up_about_their_package(): void
    {
        $package = Package::factory()->published()->create();
        $owner = $package->owner;

        Livewire::actingAs($owner)->test(Editor::class)
            ->call('open', subject: TravelSubjects::key($package))
            ->assertSet('form.subject', 'package:'.$package->id)
            ->set('form.type', ActivityType::PackageReview->value)
            ->set('form.task', 'Review price with provider')
            ->set('form.date', now()->addDay()->toDateString())
            ->set('form.time', '09:00')
            ->call('save')
            ->assertHasNoErrors();

        $item = FollowUp::query()->sole();
        $this->assertNull($item->lead_id);
        $this->assertTrue($item->subject->is($package));
        $this->assertSame($package->name, $item->subjectLabel());
    }

    public function test_travel_salesperson_cannot_schedule_against_someone_elses_package(): void
    {
        $other = Package::factory()->published()->create();
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($travel)->test(Editor::class)
            ->call('open')
            ->set('form.subject', 'package:'.$other->id)
            ->set('form.task', 'Sneaky')
            ->set('form.date', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors('form.subject');

        $this->assertSame(0, FollowUp::query()->count());
    }

    public function test_travel_types_are_not_accepted_on_leads(): void
    {
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $lead = Lead::factory()->create(['user_id' => $salesperson->id]);

        Livewire::actingAs($salesperson)->test(Editor::class)
            ->call('open', leadId: $lead->id)
            ->set('form.type', ActivityType::PackageReview->value)
            ->set('form.task', 'Wrong type')
            ->call('save')
            ->assertHasErrors('form.type');
    }

    public function test_completing_a_travel_item_books_the_next_follow_up_on_the_same_record(): void
    {
        $package = Package::factory()->published()->create();
        $item = FollowUp::query()->create([
            'user_id' => $package->owner_id, 'subject_type' => $package->getMorphClass(), 'subject_id' => $package->id,
            'type' => ActivityType::ProviderMeeting, 'task' => 'Rate review', 'due_at' => now(),
        ]);

        Livewire::actingAs($package->owner)->test(Editor::class)
            ->call('open', itemId: $item->id, complete: true)
            ->set('done.outcome', 'Provider agreed new rates')
            ->set('done.next_action', 'Send revised package for approval')
            ->set('done.next_date', now()->addDays(2)->toDateString())
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertNotNull($item->fresh()->completed_at);
        $this->assertStringContainsString('Provider agreed new rates', $item->fresh()->notes);
        $next = FollowUp::query()->whereNull('completed_at')->sole();
        $this->assertTrue($next->subject->is($package));
        $this->assertSame('Send revised package for approval', $next->task);
    }

    public function test_calendar_shows_travel_items_and_departures_to_the_owner_only(): void
    {
        $departure = PackageDeparture::factory()->create(['starts_on' => today()->addDay(), 'ends_on' => today()->addDays(3)]);
        $owner = $departure->package->owner;
        FollowUp::query()->create([
            'user_id' => $owner->id, 'subject_type' => $departure->package->getMorphClass(), 'subject_id' => $departure->package_id,
            'type' => ActivityType::CustomerFollowUp, 'task' => 'Call the Wanjiru family', 'due_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)->get(route('calendar.index', ['view' => 'week', 'date' => today()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee($departure->package->name)
            ->assertSee('Call the Wanjiru family', false);

        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $this->actingAs($manager)->get(route('calendar.index', ['view' => 'week', 'date' => today()->addDay()->toDateString(), 'rep' => 'team']))
            ->assertOk()
            ->assertDontSee('Call the Wanjiru family', false)
            ->assertDontSee($departure->package->name);

        $this->actingAs($manager)->get(route('calendar.index', ['rep' => (string) $owner->id, 'date' => today()->addDay()->toDateString()]))
            ->assertDontSee('Call the Wanjiru family', false);
    }

    public function test_property_follow_up_alerts_ignore_travel_items(): void
    {
        $travel = User::factory()->withRole(Role::TravelSalesperson)->create();
        $package = Package::factory()->create(['owner_id' => $travel->id, 'created_by' => $travel->id]);
        FollowUp::query()->create([
            'user_id' => $travel->id, 'subject_type' => $package->getMorphClass(), 'subject_id' => $package->id,
            'type' => ActivityType::CustomerFollowUp, 'task' => 'Old', 'due_at' => now()->subDays(3),
        ]);
        User::factory()->withRole(Role::SalesManager)->create();

        $this->artisan('hub:send-daily-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['data->type' => 'follow_up_overdue']);
    }
}
