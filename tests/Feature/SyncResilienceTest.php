<?php

namespace Tests\Feature;

use App\Actions\SyncOnboardings;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Onboarding;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tourlast.source' => 'api',
            'tourlast.api.base_url' => 'https://stays.example.test,https://experiences.example.test',
            'tourlast.api.token' => 'secret-token',
        ]);
    }

    public function test_one_app_being_down_does_not_stop_the_other_from_syncing(): void
    {
        Http::fake([
            'stays.example.test/*' => Http::response(['message' => 'Class "App\\Models\\Provider" not found in /var/www/stays/app/Hub.php'], 500),
            'experiences.example.test/*' => Http::response([
                'data' => [['property_id' => 'exp-1', 'property_name' => 'Safari Co', 'property_type' => 'experience', 'status' => 'active']],
                'next_page' => null,
            ]),
        ]);

        $run = app(SyncOnboardings::class)->handle();

        $this->assertSame('exp-1', Onboarding::sole()->tourlast_property_id);
        $this->assertSame(1, $run->records_created);
        $this->assertSame('failed', $run->status);
        $this->assertSame('stays.example.test answered HTTP 500', $run->error);
        $this->assertStringNotContainsString('/var/www', $run->error);
    }

    public function test_one_bad_record_does_not_stop_the_rest_of_the_feed(): void
    {
        Http::fake([
            'stays.example.test/*' => Http::response([
                'data' => [
                    ['property_name' => 'No id at all'],
                    ['property_id' => 'st-1', 'property_name' => 'Amani Apartments', 'property_type' => 'apartment', 'status' => 'active'],
                ],
                'next_page' => null,
            ]),
            'experiences.example.test/*' => Http::response(['data' => [], 'next_page' => null]),
        ]);

        $run = app(SyncOnboardings::class)->handle();

        $this->assertSame('st-1', Onboarding::sole()->tourlast_property_id);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('skipped a record', $run->error);
    }

    public function test_a_clean_run_succeeds_with_no_error(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'next_page' => null])]);

        $run = app(SyncOnboardings::class)->handle();

        $this->assertSame('succeeded', $run->status);
        $this->assertNull($run->error);
    }

    public function test_reading_an_announcement_that_does_not_exist_is_ignored(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create();

        $this->assertNull(Inbox::markRead($user, 'a-999999'));
        $this->assertSame(0, AnnouncementRead::count());
    }

    public function test_reading_an_announcement_marks_it_read_for_that_person(): void
    {
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        $user = User::factory()->withRole(Role::Salesperson)->create();
        $announcement = Announcement::factory()->create(['user_id' => $manager->id, 'audience' => ['sales']]);

        Inbox::markRead($user, 'a-'.$announcement->id);

        $this->assertTrue(AnnouncementRead::where('announcement_id', $announcement->id)->where('user_id', $user->id)->exists());
    }

    public function test_a_replayed_webhook_without_an_event_id_is_a_duplicate(): void
    {
        config(['tourlast.webhook_secret' => 'shh']);
        $body = json_encode(['provider' => ['property_id' => 'TL-9', 'property_name' => 'ABC Hotel', 'property_type' => 'hotel', 'status' => 'active']]);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TOURLAST_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'shh'),
        ];

        $this->call('POST', '/webhooks/tourlast', [], [], [], $headers, $body)->assertOk()->assertJson(['result' => 'created']);
        $this->call('POST', '/webhooks/tourlast', [], [], [], $headers, $body)->assertOk()->assertJson(['result' => 'duplicate']);
    }

    public function test_a_source_app_can_create_update_and_delete_a_provider_by_webhook(): void
    {
        config(['tourlast.webhook_secret' => 'shh']);
        $send = function (array $provider) {
            $body = json_encode(['event_id' => (string) fake()->uuid(), 'provider' => $provider]);

            return $this->call('POST', '/webhooks/tourlast', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TOURLAST_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'shh'),
            ], $body);
        };
        $record = ['property_id' => 'exp-1', 'property_name' => 'Safari Co', 'category' => 'experience', 'property_type' => 'experience', 'status' => 'active', 'updated_at' => '2026-10-06T09:00:00+03:00', 'is_deleted' => false];

        $send($record)->assertOk()->assertJson(['result' => 'created']);
        $send([...$record, 'property_name' => 'Safari Co Ltd', 'updated_at' => '2026-10-06T10:00:00+03:00'])->assertOk()->assertJson(['result' => 'updated']);
        $this->assertSame('Safari Co Ltd', Onboarding::sole()->property_name);

        // The shape the source apps send when a provider no longer exists: ids and the deleted flag only.
        $send(['property_id' => 'exp-1', 'category' => 'experience', 'is_deleted' => true, 'deleted_at' => '2026-10-06T11:00:00+03:00', 'updated_at' => '2026-10-06T11:00:00+03:00'])
            ->assertOk()->assertJson(['result' => 'deleted']);
        $this->assertSoftDeleted(Onboarding::withTrashed()->sole());
    }
}
