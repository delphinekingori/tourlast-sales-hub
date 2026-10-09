<?php

namespace Tests\Feature\Travel\Flights;

use App\Enums\ApiScope;
use App\Enums\Permission;
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

    private function integrationAccount(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::PushFlightBookings->value);

        return $user;
    }

    public function test_pushing_needs_a_token(): void
    {
        $this->postJson(self::Url, ['booking' => $this->booking()])->assertUnauthorized();
    }

    public function test_pushing_needs_the_flights_push_scope(): void
    {
        $this->api($this->integrationAccount(), [ApiScope::TravelRead->value])
            ->postJson(self::Url, ['booking' => $this->booking()])
            ->assertForbidden();
    }

    public function test_pushing_needs_the_permission_even_with_the_scope(): void
    {
        $admin = User::factory()->withRole(Role::SalesAdmin)->create();

        $this->api($admin, [ApiScope::FlightsPush->value])
            ->postJson(self::Url, ['booking' => $this->booking()])
            ->assertForbidden();

        $this->assertSame(0, FlightBooking::query()->count());
    }

    public function test_the_integration_account_pushes_bookings(): void
    {
        $client = $this->api($this->integrationAccount(), [ApiScope::FlightsPush->value]);

        $client->postJson(self::Url, ['bookings' => [$this->booking('FL-1'), $this->booking('FL-2'), ['external_id' => 'FL-3']]])
            ->assertOk()
            ->assertJsonPath('received', 3)
            ->assertJsonPath('created', 2)
            ->assertJsonPath('failed.0.external_id', 'FL-3');

        $client->postJson(self::Url, ['booking' => $this->booking('FL-1')])
            ->assertOk()
            ->assertJsonPath('unchanged', 1);

        $this->assertSame(2, FlightBooking::query()->count());
    }

    public function test_the_create_account_command_issues_a_push_only_token(): void
    {
        $this->artisan('travel:create-flights-account')->assertSuccessful();

        $account = User::query()->where('email', 'flights-integration@tourlast.com')->sole();
        $this->assertTrue($account->can(Permission::PushFlightBookings->value));
        $this->assertFalse($account->can(Permission::AccessTravelSales->value));
        $this->assertSame([ApiScope::FlightsPush->value], $account->tokens()->sole()->abilities);

        $this->artisan('travel:create-flights-account')->assertFailed();
        $this->artisan('travel:create-flights-account', ['--rotate' => true])->assertSuccessful();
        $this->assertSame(1, $account->tokens()->count());
    }
}
