<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\AnnouncementPublished;
use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\Index as Notifications;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\SmartAlert;
use App\Support\Inbox;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class LiveNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_alerts_are_stored_and_broadcast(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();
        $alert = new SmartAlert('deal_won', 'Deal won', 'Body', '/x');

        $this->assertSame(['database', 'broadcast'], $alert->via($user));
        $this->assertSame(['type' => 'deal_won', 'title' => 'Deal won', 'body' => 'Body', 'url' => '/x'], $alert->toBroadcast($user)->data);
    }

    public function test_publishing_an_announcement_broadcasts_to_its_audience_but_not_the_author(): void
    {
        Event::fake([AnnouncementPublished::class]);

        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $salesperson = User::factory()->withRole(Role::Salesperson)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();

        $announcement = Announcement::factory()->create(['user_id' => $manager->id, 'audience' => ['sales']]);

        Event::assertDispatched(AnnouncementPublished::class, fn (AnnouncementPublished $event): bool => $event->announcement->is($announcement));

        $channels = collect((new AnnouncementPublished($announcement))->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name);

        $this->assertContains('private-App.Models.User.'.$salesperson->id, $channels);
        $this->assertNotContains('private-App.Models.User.'.$hr->id, $channels);
        $this->assertNotContains('private-App.Models.User.'.$manager->id, $channels);
    }

    public function test_people_can_only_listen_to_their_own_channel(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();
        $other = User::factory()->withRole(Role::Salesperson)->create();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'reverb.test',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$other->id])->assertForbidden();
        $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$user->id])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_the_bell_and_page_refresh_when_the_browser_reports_a_push(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();

        $bell = Livewire::actingAs($user)->test(Bell::class)->assertSee('caught up');
        $page = Livewire::actingAs($user)->test(Notifications::class);

        $user->notify(new SmartAlert('deal_won', 'Deal won in real time', 'Body'));

        $bell->dispatch('inbox-updated')->assertSee('Deal won in real time');
        $page->dispatch('inbox-updated')->assertSee('Deal won in real time');
    }

    public function test_polling_slows_down_when_reverb_is_the_broadcaster(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->assertSame(30, Inbox::pollSeconds());

        config(['broadcasting.default' => 'reverb']);
        $this->assertSame(120, Inbox::pollSeconds());
    }
}
