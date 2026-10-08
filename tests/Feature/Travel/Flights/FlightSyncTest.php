<?php

namespace Tests\Feature\Travel\Flights;

use App\Actions\Travel\Flights\ApplyFlightRecord;
use App\Actions\Travel\Flights\SyncFlights;
use App\Enums\Role;
use App\Enums\Travel\InfluencerCodeScope;
use App\Events\FlightBookingSynced;
use App\Integrations\Flights\FlightRecord;
use App\Integrations\Flights\FlightSource;
use App\Integrations\Flights\SandboxFlightSource;
use App\Models\AuditEvent;
use App\Models\FlightBooking;
use App\Models\FlightSyncRun;
use App\Models\InfluencerCode;
use App\Models\SyncRun;
use App\Models\User;
use App\Notifications\SmartAlert;
use App\Support\Travel\FlightSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FlightSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'external_id' => 'FL-1',
            'booking_reference' => 'TLF00001',
            'pnr' => 'abc123',
            'customer' => ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '0712345678'],
            'airline' => ['code' => 'kq', 'name' => 'Kenya Airways'],
            'origin' => 'nbo',
            'destination' => 'MBA',
            'departure_at' => now()->addDays(5)->toIso8601String(),
            'passengers' => [['name' => 'Jane Doe', 'type' => 'adult', 'ticket_number' => '7061']],
            'segments' => [['flight_number' => 'KQ602', 'origin' => 'NBO', 'destination' => 'MBA', 'departure_at' => now()->addDays(5)->toIso8601String()]],
            'total_amount' => 12000,
            'markup_amount' => 700,
            'booking_status' => 'Confirmed',
            'booked_at' => now()->subDay()->toIso8601String(),
            'updated_at' => now()->subHour()->toIso8601String(),
        ], $overrides);
    }

    public function test_the_sandbox_sync_creates_bookings_and_logs_a_run_without_touching_tourlast_sync_runs(): void
    {
        $run = app(SyncFlights::class)->handle('full');

        $this->assertTrue($run->succeeded());
        $this->assertSame('sandbox', $run->source);
        $this->assertSame(SandboxFlightSource::Count, $run->records_created);
        $this->assertSame(SandboxFlightSource::Count, FlightBooking::query()->count());
        $this->assertGreaterThan(0, FlightBooking::query()->whereNotNull('refund_status')->count());
        $this->assertSame(0, SyncRun::query()->count(), 'The tourlast.com sync log must stay untouched.');
        $this->assertFalse((new FlightSyncStatus)->isStale());
    }

    public function test_syncing_again_changes_nothing(): void
    {
        app(SyncFlights::class)->handle('full');
        $run = app(SyncFlights::class)->handle('full');

        $this->assertSame(0, $run->records_created);
        $this->assertSame(0, $run->records_updated);
        $this->assertSame(SandboxFlightSource::Count, FlightBooking::query()->count());
    }

    public function test_a_record_is_stored_verbatim_with_segments_passengers_and_sync_fields(): void
    {
        Event::fake([FlightBookingSynced::class]);

        $result = app(ApplyFlightRecord::class)->handle(FlightRecord::fromArray($this->payload()), 'push');

        $booking = FlightBooking::query()->with(['segments', 'passengers'])->sole();
        $this->assertSame(ApplyFlightRecord::Created, $result);
        $this->assertSame('confirmed', $booking->booking_status);
        $this->assertSame('KQ', $booking->airline_code);
        $this->assertSame('NBO → MBA', $booking->route());
        $this->assertSame('ABC123', $booking->pnr);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(1, $booking->passengers);
        $this->assertSame('synced', $booking->sync_status);
        $this->assertNotNull($booking->last_synced_at);
        $this->assertSame('tourlast-flights', $booking->source_system);
        Event::assertDispatched(FlightBookingSynced::class, fn (FlightBookingSynced $event) => $event->isNew);
    }

    public function test_an_older_update_is_ignored(): void
    {
        $apply = app(ApplyFlightRecord::class);
        $apply->handle(FlightRecord::fromArray($this->payload()), 'push');

        $result = $apply->handle(FlightRecord::fromArray($this->payload([
            'booking_status' => 'cancelled',
            'updated_at' => now()->subDays(2)->toIso8601String(),
        ])), 'push');

        $this->assertSame(ApplyFlightRecord::Unchanged, $result);
        $this->assertSame('confirmed', FlightBooking::query()->sole()->booking_status);
    }

    public function test_status_changes_are_audited(): void
    {
        $apply = app(ApplyFlightRecord::class);
        $apply->handle(FlightRecord::fromArray($this->payload()), 'push');

        $apply->handle(FlightRecord::fromArray($this->payload([
            'booking_status' => 'cancelled',
            'cancellation' => ['status' => 'cancelled', 'cancelled_at' => now()->toIso8601String(), 'reason' => 'Customer request'],
            'refund' => ['status' => 'pending', 'amount' => 9000],
            'updated_at' => now()->toIso8601String(),
        ])), 'push');

        $summaries = AuditEvent::query()->where('action', 'flight.synced')->pluck('summary');
        $this->assertCount(4, $summaries);
        $this->assertTrue($summaries->contains(fn (string $summary) => str_starts_with($summary, 'Cancellation synced')));
        $this->assertTrue($summaries->contains(fn (string $summary) => str_starts_with($summary, 'Refund synced')));
        $this->assertSame(['confirmed', 'cancelled'], AuditEvent::query()->where('summary', 'like', 'Booking synced: TLF00001 via%')->sole()->changes['booking_status']);
    }

    public function test_bookings_are_credited_to_the_travel_salesperson_and_influencer_code(): void
    {
        $aisha = User::factory()->withRole(Role::TravelSalesperson)->create(['email' => 'aisha@tourlast.test']);
        User::factory()->withRole(Role::Salesperson)->create(['email' => 'john@tourlast.test']);
        $code = InfluencerCode::factory()->create(['code' => 'AMINA10', 'applies_to' => InfluencerCodeScope::Flights]);
        InfluencerCode::factory()->create(['code' => 'PKGONLY', 'applies_to' => InfluencerCodeScope::Packages]);

        $apply = app(ApplyFlightRecord::class);
        $apply->handle(FlightRecord::fromArray($this->payload(['agent_reference' => 'Aisha@tourlast.test', 'promo_code' => 'amina10'])), 'push');
        $apply->handle(FlightRecord::fromArray($this->payload(['external_id' => 'FL-2', 'agent_reference' => 'john@tourlast.test', 'promo_code' => 'PKGONLY'])), 'push');

        $first = FlightBooking::query()->where('external_id', 'FL-1')->sole();
        $second = FlightBooking::query()->where('external_id', 'FL-2')->sole();

        $this->assertSame($aisha->id, $first->salesperson_id);
        $this->assertSame($code->id, $first->influencer_code_id);
        $this->assertNull($second->salesperson_id, 'Only travel salespeople are credited.');
        $this->assertNull($second->influencer_code_id, 'A packages-only code does not count on flights.');
        $this->assertSame('PKGONLY', $second->promo_code);
    }

    public function test_the_api_source_follows_pages(): void
    {
        config(['travel.flights.source' => 'api', 'travel.flights.api_url' => 'https://flights.test/api', 'travel.flights.api_token' => 'secret']);
        app()->forgetInstance(FlightSource::class);

        Http::fake([
            'flights.test/api/bookings*' => Http::sequence()
                ->push(['data' => [$this->payload()], 'meta' => ['current_page' => 1, 'last_page' => 2]])
                ->push(['data' => [$this->payload(['external_id' => 'FL-2']), ['external_id' => 'FL-BAD']], 'meta' => ['current_page' => 2, 'last_page' => 2]]),
        ]);

        $run = app(SyncFlights::class)->handle('full');

        $this->assertTrue($run->succeeded());
        $this->assertSame('api', $run->source);
        $this->assertSame(2, FlightBooking::query()->count());
        $this->assertSame(1, $run->records_failed);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret') && str_contains($request->url(), 'page=2'));
    }

    public function test_a_failed_pull_keeps_existing_bookings_and_alerts_managers_once_an_hour(): void
    {
        Notification::fake();
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        $manager = User::factory()->withRole(Role::SalesManager)->create();
        app(ApplyFlightRecord::class)->handle(FlightRecord::fromArray($this->payload()), 'push');

        config(['travel.flights.source' => 'api', 'travel.flights.api_url' => 'https://flights.test/api', 'travel.flights.api_token' => 'secret']);
        Http::fake(['flights.test/*' => Http::response(['message' => 'down'], 500)]);

        $first = app(SyncFlights::class)->handle();
        $second = app(SyncFlights::class)->handle();

        $this->assertSame('failed', $first->status);
        $this->assertSame('failed', $second->status);
        $this->assertStringContainsString('HTTP 500', (string) $first->error);
        $this->assertSame(1, FlightBooking::query()->count());
        $this->assertSame('confirmed', FlightBooking::query()->sole()->booking_status);
        $this->assertTrue((new FlightSyncStatus)->isStale());
        Notification::assertSentToTimes($admin, SmartAlert::class, 1);
        Notification::assertNotSentTo($manager, SmartAlert::class);
    }

    public function test_the_sync_command_skips_in_push_mode(): void
    {
        config(['travel.flights.source' => 'push']);

        $this->artisan('travel:sync-flights')->assertSuccessful();

        $this->assertSame(0, FlightSyncRun::query()->count());
    }

    public function test_the_sync_command_reports_counts(): void
    {
        $this->artisan('travel:sync-flights', ['--full' => true])
            ->expectsOutputToContain('Flights synced from sandbox: '.SandboxFlightSource::Count.' seen')
            ->assertSuccessful();
    }

    public function test_records_without_required_keys_are_rejected(): void
    {
        $this->expectExceptionMessage('booking_status is required');

        FlightRecord::fromArray(['external_id' => 'X', 'booked_at' => now()->toIso8601String()]);
    }
}
