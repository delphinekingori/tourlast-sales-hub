<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\TravelProvider;
use App\Models\TripReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class TravelReviewsApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_reviews_are_listed_filtered_and_need_the_read_scope(): void
    {
        $low = TripReview::factory()->rated(1)->create();
        TripReview::factory()->rated(5)->create();
        TripReview::factory()->rated(4)->hidden()->create();
        $user = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($user, ['profile'])->getJson('/api/v1/travel/reviews')->assertForbidden()->assertJsonPath('required_scopes', ['travel:read']);

        $this->api($user, ['travel:read'])->getJson('/api/v1/travel/reviews')->assertOk()->assertJsonCount(2, 'data');
        $this->api($user, ['travel:read'])->getJson('/api/v1/travel/reviews?rating=low')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $low->id)->assertJsonPath('data.0.is_low', true);
        $this->api($user, ['travel:read'])->getJson('/api/v1/travel/reviews?provider_id='.$low->travel_provider_id)->assertOk()->assertJsonCount(1, 'data');

        $manager = User::factory()->withRole(Role::SalesAdmin)->create();
        $this->api($manager, ['travel:read'])->getJson('/api/v1/travel/reviews')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_hidden_review_is_not_found_for_salespeople_and_hr_is_refused(): void
    {
        $hidden = TripReview::factory()->hidden()->create();

        $this->api(User::factory()->withRole(Role::TravelSalesperson)->create(), ['travel:read'])->getJson('/api/v1/travel/reviews/'.$hidden->id)->assertNotFound();
        $this->api(User::factory()->withRole(Role::SalesAdmin)->create(), ['travel:read'])->getJson('/api/v1/travel/reviews/'.$hidden->id)
            ->assertOk()->assertJsonPath('data.is_hidden', true);
        $this->api(User::factory()->withRole(Role::Hr)->create(), ['travel:read'])->getJson('/api/v1/travel/reviews')->assertForbidden();
    }

    public function test_provider_and_package_carry_rating_summaries(): void
    {
        $provider = TravelProvider::factory()->create();
        $package = Package::factory()->create(['travel_provider_id' => $provider->id]);

        foreach ([5, 4] as $rating) {
            $booking = PackageBooking::factory()->completed()->create(['package_id' => $package->id]);
            TripReview::factory()->rated($rating)->create(['package_booking_id' => $booking->id, 'package_id' => $package->id, 'travel_provider_id' => $provider->id]);
        }

        $user = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($user, ['travel:read'])->getJson('/api/v1/travel/providers/'.$provider->id)
            ->assertOk()->assertJsonPath('data.reviews_count', 2)->assertJsonPath('data.rating_average', 4.5);
        $this->api($user, ['travel:read'])->getJson('/api/v1/travel/packages/'.$package->id)
            ->assertOk()->assertJsonPath('data.reviews_count', 2)->assertJsonPath('data.rating_average', 4.5);
    }

    public function test_review_is_recorded_through_the_write_scope(): void
    {
        $booking = PackageBooking::factory()->completed()->create();
        $payload = ['rating' => 2, 'source' => 'phone', 'comment' => 'Late pickup', 'rating_service' => 2];

        $this->api($booking->salesperson, ['travel:read'])->postJson("/api/v1/travel/bookings/{$booking->id}/review", $payload)->assertForbidden();

        $this->api($booking->salesperson, ['travel:write'])->postJson("/api/v1/travel/bookings/{$booking->id}/review", $payload)
            ->assertCreated()->assertJsonPath('data.rating', 2)->assertJsonPath('data.category_ratings.service', 2)->assertJsonPath('data.source', 'phone');

        $this->api($booking->salesperson, ['travel:write'])->postJson("/api/v1/travel/bookings/{$booking->id}/review", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
    }

    public function test_review_needs_a_completed_booking_the_caller_can_see(): void
    {
        $open = PackageBooking::factory()->confirmed()->create();
        $theirs = PackageBooking::factory()->completed()->create();
        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->api($open->salesperson, ['travel:write'])->postJson("/api/v1/travel/bookings/{$open->id}/review", ['rating' => 4, 'source' => 'email'])->assertUnprocessable();
        $this->api($stranger, ['travel:write'])->postJson("/api/v1/travel/bookings/{$theirs->id}/review", ['rating' => 4, 'source' => 'email'])->assertNotFound();
    }
}
