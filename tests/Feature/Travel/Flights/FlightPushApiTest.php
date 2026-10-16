<?php

namespace Tests\Feature\Travel\Flights;

use App\Enums\ApiScope;
use App\Enums\Role;
use App\Models\FlightBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class FlightPushApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    private const Url = '/api/v1/integrations/flights/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        config(['travel.flights.api_token' => 'shared-secret']);
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(string $id = 'FL-9'): array
    {
        return [
            'external_id' => $id,
            'booking_status' => 'confirmed',
            'booked_at' => now()->toIso8601String(),
            'origin' => 'NBO',
            'destination' => 'KIS',
            'total_amount' => 8000,
        ];
    }

    public function test_pushing_needs_the_shared_token(): void
    {
        $this->postJson(self::Url, ['booking' => $this->booking()])->assertUnauthorized();
    }

    public function test_pushing_needs_the_configured_secret(): void
    {
        $this->withToken('wrong')
            ->postJson(self::Url, ['booking' => $this->booking()])
            ->assertUnauthorized();

        $this->assertSame(0, FlightBooking::query()->count());
    }

    public function test_pushing_is_refused_before_the_hub_has_a_token(): void
    {
        config(['travel.flights.api_token' => null]);

        $this->withToken('anything')
            ->postJson(self::Url, ['booking' => $this->booking()])
            ->assertStatus(503);
    }

    public function test_a_user_api_token_does_not_open_the_push_endpoint(): void
    {
        $user = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->api($user, [ApiScope::TravelRead->value])
            ->postJson(self::Url, ['booking' => $this->booking()])
            ->assertUnauthorized();

        $this->assertSame(0, FlightBooking::query()->count());
    }

    public function test_the_shared_secret_pushes_bookings(): void
    {
        $one = $this->booking('FL-1');

        $this->withToken('shared-secret')
            ->postJson(self::Url, ['bookings' => [$one, $this->booking('FL-2'), ['external_id' => 'FL-3']]])
            ->assertOk()
            ->assertJsonPath('received', 3)
            ->assertJsonPath('created', 2)
            ->assertJsonPath('failed.0.external_id', 'FL-3');

        $this->withToken('shared-secret')
            ->postJson(self::Url, ['booking' => $one])
            ->assertOk()
            ->assertJsonPath('unchanged', 1);

        $this->assertSame(2, FlightBooking::query()->count());
    }
}
