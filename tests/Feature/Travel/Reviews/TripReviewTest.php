<?php

namespace Tests\Feature\Travel\Reviews;

use App\Actions\Travel\Reviews\ModerateTripReview;
use App\Actions\Travel\Reviews\RecordTripReview;
use App\Enums\Role;
use App\Livewire\Travel\Bookings\Show as BookingShow;
use App\Livewire\Travel\Providers\Show;
use App\Livewire\Travel\Reviews\Index;
use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\TravelProvider;
use App\Models\TripReview;
use App\Models\User;
use App\Notifications\SmartAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TripReviewTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->withRole(Role::SalesAdmin)->create();
    }

    public function test_salesperson_records_a_review_for_a_completed_booking_once(): void
    {
        Notification::fake();
        $booking = PackageBooking::factory()->completed()->create();

        $review = app(RecordTripReview::class)->handle($booking->salesperson, $booking, [
            'rating' => 5, 'rating_guide' => 4, 'source' => 'whatsapp', 'comment' => ' Lovely trip ',
        ]);

        $this->assertSame(5, $review->rating);
        $this->assertSame(4, $review->rating_guide);
        $this->assertNull($review->rating_safety);
        $this->assertSame('Lovely trip', $review->comment);
        $this->assertSame($booking->salesperson_id, $review->recorded_by);
        $this->assertSame($booking->package->travel_provider_id, $review->travel_provider_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'review.recorded')->count());
        Notification::assertNothingSent();

        $this->expectException(ValidationException::class);
        app(RecordTripReview::class)->handle($booking->salesperson, $booking->fresh(), ['rating' => 3, 'source' => 'phone']);
    }

    public function test_only_completed_bookings_can_be_reviewed(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create();

        try {
            app(RecordTripReview::class)->handle($booking->salesperson, $booking, ['rating' => 4, 'source' => 'phone']);
            $this->fail('A confirmed booking was reviewed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rating', $e->errors());
        }

        $this->assertSame(0, TripReview::query()->count());
    }

    public function test_ratings_and_source_are_validated(): void
    {
        $booking = PackageBooking::factory()->completed()->create();

        foreach ([
            ['rating' => 6, 'source' => 'phone'],
            ['rating' => 0, 'source' => 'phone'],
            ['source' => 'phone'],
            ['rating' => 3, 'source' => 'carrier-pigeon'],
            ['rating' => 3, 'source' => 'phone', 'rating_value' => 9],
        ] as $data) {
            try {
                app(RecordTripReview::class)->handle($booking->salesperson, $booking, $data);
                $this->fail('Invalid review accepted: '.json_encode($data));
            } catch (ValidationException) {
                $this->assertSame(0, TripReview::query()->count());
            }
        }
    }

    public function test_other_salespeople_cannot_record_but_managers_can(): void
    {
        $booking = PackageBooking::factory()->completed()->create();
        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();
        $hr = User::factory()->withRole(Role::Hr)->create();

        foreach ([$stranger, $hr] as $user) {
            try {
                app(RecordTripReview::class)->handle($user, $booking, ['rating' => 4, 'source' => 'phone']);
                $this->fail('Unauthorised user recorded a review.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $review = app(RecordTripReview::class)->handle($this->manager(), $booking, ['rating' => 4, 'source' => 'email']);
        $this->assertNotNull($review->id);
    }

    public function test_low_rating_alerts_travel_managers_only(): void
    {
        Notification::fake();
        $manager = $this->manager();
        $other = User::factory()->withRole(Role::TravelSalesperson)->create();
        $salesManager = User::factory()->withRole(Role::SalesManager)->create();
        $booking = PackageBooking::factory()->completed()->create();

        app(RecordTripReview::class)->handle($booking->salesperson, $booking, ['rating' => 2, 'source' => 'phone', 'comment' => 'Driver was late']);

        Notification::assertSentTo($manager, SmartAlert::class);
        Notification::assertNotSentTo($other, SmartAlert::class);
        Notification::assertNotSentTo($salesManager, SmartAlert::class);
    }

    public function test_provider_and_package_rating_average_visible_reviews_only(): void
    {
        $provider = TravelProvider::factory()->create();
        $package = Package::factory()->create(['travel_provider_id' => $provider->id]);
        $other = Package::factory()->create(['travel_provider_id' => $provider->id]);

        $make = function (Package $for, int $rating, bool $hidden = false) use ($provider): void {
            $booking = PackageBooking::factory()->completed()->create(['package_id' => $for->id]);
            TripReview::factory()->rated($rating)->when($hidden, fn ($factory) => $factory->hidden())
                ->create(['package_booking_id' => $booking->id, 'package_id' => $for->id, 'travel_provider_id' => $provider->id]);
        };

        $make($package, 5);
        $make($package, 4);
        $make($other, 3);
        $make($other, 1, hidden: true);

        $withStats = TravelProvider::query()->withReviewStats()->findOrFail($provider->id);
        $this->assertSame(3, $withStats->reviews_count);
        $this->assertSame(4.0, $withStats->ratingAverage());

        $this->assertSame(4.5, Package::query()->withReviewStats()->findOrFail($package->id)->ratingAverage());
        $this->assertNull(TravelProvider::query()->withReviewStats()->findOrFail(TravelProvider::factory()->create()->id)->ratingAverage());
    }

    public function test_managers_hide_and_unhide_with_a_reason_and_audit(): void
    {
        $review = TripReview::factory()->create();
        $manager = $this->manager();
        $moderate = app(ModerateTripReview::class);

        try {
            $moderate->hide($manager, $review, '  ');
            $this->fail('Hid a review without a reason.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $moderate->hide($manager, $review, 'Abusive language');
        $review->refresh();
        $this->assertTrue($review->is_hidden);
        $this->assertSame('Abusive language', $review->hidden_reason);
        $this->assertSame($manager->id, $review->moderated_by);
        $this->assertSame(0, TripReview::query()->visible()->count());

        $moderate->unhide($manager, $review);
        $this->assertFalse($review->fresh()->is_hidden);
        $this->assertNull($review->fresh()->hidden_reason);
        $this->assertSame(['review.hidden', 'review.unhidden'], AuditEvent::query()->where('action', 'like', 'review.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_salespeople_cannot_moderate(): void
    {
        $review = TripReview::factory()->create();
        $salesperson = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->expectException(HttpException::class);
        app(ModerateTripReview::class)->hide($salesperson, $review, 'Nope');
    }

    public function test_reviews_page_filters_highlights_and_hides_hidden_from_salespeople(): void
    {
        $good = TripReview::factory()->rated(5)->create(['comment' => 'Fantastic safari']);
        $low = TripReview::factory()->rated(1)->create(['comment' => 'Terrible transfer']);
        $hidden = TripReview::factory()->rated(4)->hidden('Spam')->create(['comment' => 'Buy my stuff']);
        $salesperson = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($salesperson)->test(Index::class)
            ->assertSee('Fantastic safari')->assertSee('Terrible transfer')->assertDontSee('Buy my stuff')
            ->set('rating', 'low')
            ->assertSee('Terrible transfer')->assertDontSee('Fantastic safari')
            ->set('rating', '')
            ->set('provider', (string) $good->travel_provider_id)
            ->assertSee('Fantastic safari')->assertDontSee('Terrible transfer');

        Livewire::actingAs($this->manager())->test(Index::class)->assertSee('Buy my stuff')->assertSee('Hidden')
            ->set('to', now()->subYear()->toDateString())->assertDontSee('Fantastic safari');

        $this->assertNotNull($low->id.$hidden->id);
    }

    public function test_manager_hides_a_review_from_the_page_and_salesperson_cannot(): void
    {
        $review = TripReview::factory()->create();

        Livewire::actingAs(User::factory()->withRole(Role::TravelSalesperson)->create())->test(Index::class)
            ->call('openHide', $review->id)->assertForbidden();

        Livewire::actingAs($this->manager())->test(Index::class)
            ->call('openHide', $review->id)
            ->call('hide')->assertHasErrors('reason')
            ->set('reason', 'Duplicate')
            ->call('hide')->assertHasNoErrors();

        $this->assertTrue($review->fresh()->is_hidden);
    }

    public function test_review_is_recorded_from_the_completed_booking_page(): void
    {
        Notification::fake();
        $booking = PackageBooking::factory()->completed()->create();

        $component = Livewire::actingAs($booking->salesperson)->test(BookingShow::class, ['booking' => $booking->id])
            ->assertSeeHtml('wire:click="openReview"')
            ->call('openReview')
            ->call('saveReview')->assertHasErrors('review.rating')
            ->set('review.rating', '5')
            ->set('review.source', 'phone')
            ->set('review.comment', 'Great')
            ->call('saveReview')->assertHasNoErrors();

        $component->assertDontSeeHtml('wire:click="openReview"')->assertSee('Great');
        $this->assertSame(5, TripReview::query()->firstOrFail()->rating);
    }

    public function test_record_review_is_not_offered_on_open_bookings(): void
    {
        $booking = PackageBooking::factory()->confirmed()->create();

        Livewire::actingAs($booking->salesperson)->test(BookingShow::class, ['booking' => $booking->id])
            ->assertDontSeeHtml('wire:click="openReview"')
            ->call('openReview')->assertForbidden();
    }

    public function test_reviews_export_downloads_the_filtered_rows(): void
    {
        TripReview::factory()->rated(1)->create();
        $user = User::factory()->withRole(Role::TravelSalesperson)->create();

        $this->actingAs($user)->get(route('travel.reviews.export', ['rating' => 'low']))->assertOk()
            ->assertDownload('tourlast-trip-reviews-'.now()->format('Y-m-d').'.xlsx');

        $this->actingAs(User::factory()->withRole(Role::Hr)->create())->get(route('travel.reviews.export'))->assertForbidden();
    }

    public function test_provider_page_shows_the_rating(): void
    {
        $review = TripReview::factory()->rated(4)->create();

        Livewire::actingAs($this->manager())->test(Show::class, ['provider' => $review->travel_provider_id])
            ->assertSee('4.0')->assertSee('1 review');
    }
}
