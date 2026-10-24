<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Incentives\Claims;
use App\Livewire\Claims\Approvals;
use App\Livewire\Claims\Index;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimsTest extends TestCase
{
    use RefreshDatabase;

    private User $john;

    private User $manager;

    private User $hr;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->john = User::factory()->withRole(Role::Salesperson)->create();
        $this->manager = User::factory()->withRole(Role::SalesManager)->create();
        $this->hr = User::factory()->withRole(Role::Hr)->create();
        $this->finance = User::factory()->withRole(Role::Accounts)->create();
    }

    public function test_a_bolt_reimbursement_needs_the_trip_id_and_the_ride_details_from_the_app(): void
    {
        $component = Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->set('form.ride_provider', 'bolt')
            ->set('form.pickup', 'Westlands')
            ->set('form.dropoff', 'Kilimani')
            ->set('form.amount', '640')
            ->set('form.description', 'Partner training')
            ->set('form.travel_date', now()->subDay()->toDateString())
            ->call('submit')
            ->assertHasErrors(['form.trip_reference', 'rideDetails']);

        $component
            ->set('form.trip_reference', 'RB12345678')
            ->set('rideDetails', [UploadedFile::fake()->image('bolt-trip.png')])
            ->call('submit')
            ->assertHasNoErrors();

        $claim = ExpenseClaim::sole();
        $this->assertSame('manager', $claim->current_step);
        $this->assertSame('ride_details', $claim->attachments->sole()->kind);
        Storage::disk('local')->assertExists($claim->attachments->sole()->path);
    }

    public function test_a_taxi_reimbursement_needs_a_receipt_and_a_request_must_be_for_a_future_trip(): void
    {
        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->set('form.ride_provider', 'taxi')
            ->set('form.pickup', 'A')->set('form.dropoff', 'B')->set('form.amount', '900')->set('form.description', 'Visit')
            ->call('submit')
            ->assertHasErrors('receipts');

        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_request')
            ->set('form.travel_date', now()->subDay()->toDateString())
            ->set('form.ride_provider', 'matatu')
            ->set('form.pickup', 'Mombasa')->set('form.dropoff', 'Diani')->set('form.amount', '4500')->set('form.description', 'Onboarding trip')
            ->call('submit')
            ->assertHasErrors('form.travel_date')
            ->set('form.travel_date', now()->addDays(2)->toDateString())
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('transport_request', ExpenseClaim::sole()->type);
    }

    public function test_transport_goes_manager_then_hr_then_finance(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create(['amount' => 1500]);

        Livewire::actingAs($this->hr)->test(Approvals::class)->call('view', $claim->id)->call('approve')->assertForbidden();

        Livewire::actingAs($this->manager)->test(Approvals::class)->call('view', $claim->id)->set('decisionNote', 'Visit logged')->call('approve');
        $this->assertSame('hr', $claim->fresh()->current_step);

        Livewire::actingAs($this->hr)->test(Approvals::class)->assertSee($this->john->name)->call('view', $claim->id)->call('approve');
        $this->assertSame('finance', $claim->fresh()->current_step);

        Livewire::actingAs($this->finance)->test(Approvals::class)->call('view', $claim->id)->set('decisionAmount', '1200')->call('approve')->assertHasNoErrors();

        $claim->refresh();
        $this->assertSame('approved', $claim->status);
        $this->assertSame(1200.0, $claim->payableAmount());
        $this->assertSame(['manager', 'hr', 'finance'], $claim->approvals->pluck('step')->all());
    }

    public function test_a_rejection_needs_a_reason_and_ends_the_claim(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create();

        Livewire::actingAs($this->manager)->test(Approvals::class)->call('view', $claim->id)->call('reject')->assertHasErrors('decisionNote')
            ->set('decisionNote', 'Use Bolt inside town')->call('reject');

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->current_step);
    }

    public function test_nobody_approves_their_own_claim(): void
    {
        $managerClaim = ExpenseClaim::factory()->for($this->manager)->create();

        $this->assertFalse(app(Claims::class)->canDecide($this->manager, $managerClaim));
        $this->assertTrue(app(Claims::class)->canDecide(User::factory()->withRole(Role::SalesAdmin)->create(), $managerClaim));
    }

    public function test_the_page_offers_three_plain_choices_and_counts_every_claim_not_just_the_page(): void
    {
        ExpenseClaim::factory()->count(16)->create(['user_id' => $this->john->id, 'status' => 'pending']);

        Livewire::actingAs($this->john)->test(Index::class)
            ->assertSee('What do you need?')
            ->assertSee('Get my trip money back')
            ->assertSee('Ask for transport money')
            ->assertSee('Claim airtime')
            ->assertViewHas('pendingCount', 16);
    }

    public function test_each_form_says_who_approves_and_what_is_required(): void
    {
        $page = Livewire::actingAs($this->john)->test(Index::class);

        $page->call('open', 'airtime')
            ->assertSee('1. Finance')
            ->assertDontSee('The trip')
            ->assertSee('Airtime receipt or M-Pesa message');

        $page->call('open', 'transport_request')
            ->assertSee('1. Sales Manager')
            ->assertSee('3. Finance')
            ->assertSee('Quote or supporting document');

        $page->call('open', 'transport_reimbursement')
            ->set('form.ride_provider', 'bolt')
            ->assertSee('Trip receipt from the app')
            ->assertViewHas('rideDetailsRequired', true)
            ->assertViewHas('receiptRequired', false)
            ->set('form.ride_provider', 'taxi')
            ->assertViewHas('receiptRequired', true)
            ->set('form.ride_provider', 'matatu')
            ->assertViewHas('receiptRequired', false)
            ->assertSee('optional for this kind of trip');
    }

    public function test_trips_that_give_no_receipt_can_still_be_sent_including_other(): void
    {
        foreach (['other', 'boda', 'own_vehicle', 'matatu'] as $provider) {
            Livewire::actingAs($this->john)->test(Index::class)
                ->call('open', 'transport_reimbursement')
                ->set('form.ride_provider', $provider)
                ->set('form.pickup', 'Westlands')->set('form.dropoff', 'Kilimani')
                ->set('form.amount', '300')->set('form.description', 'Partner visit by '.$provider)
                ->call('submit')
                ->assertHasNoErrors()
                ->assertSet('showForm', false);
        }

        $this->assertSame(4, ExpenseClaim::where('user_id', $this->john->id)->count());
    }

    public function test_a_failed_send_lists_what_to_fix_at_the_top_of_the_form(): void
    {
        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->call('submit')
            ->assertSee('Fix these')
            ->assertSee('Enter the amount in KES.')
            ->assertSee('Enter where the trip started.')
            ->assertSee('Say what the money was for, in a sentence.');
    }

    public function test_places_picked_on_the_map_are_saved_with_their_coordinates(): void
    {
        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->set('form.ride_provider', 'other')
            ->set('form.pickup', 'Westlands, Nairobi')->set('form.pickup_lat', '-1.2673000')->set('form.pickup_lng', '36.8110000')
            ->set('form.dropoff', 'Diani Beach')->set('form.dropoff_lat', '-4.2800000')->set('form.dropoff_lng', '39.5900000')
            ->set('form.amount', '1200')->set('form.description', 'Partner visit')
            ->call('submit')
            ->assertHasNoErrors();

        $claim = ExpenseClaim::sole();
        $this->assertSame(-1.2673, $claim->pickup_lat);
        $this->assertSame(39.59, $claim->dropoff_lng);
        $this->assertStringContainsString('origin=-1.2673%2C36.811', $claim->routeUrl());
    }

    public function test_coordinates_must_be_real_and_typed_places_still_work_without_them(): void
    {
        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->set('form.ride_provider', 'other')
            ->set('form.pickup', 'Westlands')->set('form.pickup_lat', '95')
            ->set('form.dropoff', 'Kilimani')->set('form.dropoff_lng', '-181')
            ->set('form.amount', '300')->set('form.description', 'Visit')
            ->call('submit')
            ->assertHasErrors(['form.pickup_lat', 'form.dropoff_lng'])
            ->set('form.pickup_lat', '')->set('form.dropoff_lng', '')
            ->call('submit')
            ->assertHasNoErrors();

        $claim = ExpenseClaim::sole();
        $this->assertNull($claim->pickup_lat);
        $this->assertStringContainsString('origin=Westlands', $claim->routeUrl());
    }

    public function test_the_form_has_place_search_boxes_and_the_detail_links_to_the_route(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create();

        Livewire::actingAs($this->john)->test(Index::class)
            ->call('open', 'transport_reimbursement')
            ->assertSeeHtml('id="claim-pickup"')
            ->assertSeeHtml('id="claim-dropoff"')
            ->assertSee('Search a place')
            ->call('view', $claim->id)
            ->assertSee('See the route on Google Maps');
    }

    public function test_airtime_shows_how_much_is_left_this_month(): void
    {
        $claims = app(Claims::class);
        $claim = $claims->submit($this->john, ['type' => 'airtime', 'amount' => 300, 'description' => 'Calls']);
        $claims->approve($claim, $this->finance);

        Livewire::actingAs($this->john)->test(Index::class)
            ->assertViewHas('airtimeLeft', 100)
            ->assertSee('KES 100 left this month');
    }

    public function test_airtime_goes_straight_to_finance_and_is_capped_at_400_a_month(): void
    {
        $claims = app(Claims::class);
        $first = $claims->submit($this->john, ['type' => 'airtime', 'amount' => 300, 'description' => 'Calls']);
        $this->assertSame('finance', $first->current_step);
        $claims->approve($first, $this->finance);

        $second = $claims->submit($this->john, ['type' => 'airtime', 'amount' => 300, 'description' => 'More calls']);
        $claims->approve($second, $this->finance);
        $this->assertSame(100.0, $second->fresh()->payableAmount());

        $third = $claims->submit($this->john, ['type' => 'airtime', 'amount' => 50, 'description' => 'Even more']);
        $this->expectException(ValidationException::class);
        $claims->approve($third, $this->finance);
    }

    public function test_finance_disburses_an_approved_transport_request(): void
    {
        $request = ExpenseClaim::factory()->for($this->john)->create(['type' => 'transport_request', 'status' => 'approved', 'current_step' => null]);

        Livewire::actingAs($this->finance)->test(Approvals::class)->set('tab', 'disburse')->assertSee($this->john->name)
            ->call('view', $request->id)->set('paymentReference', 'QJK7H2L9XP')->call('disburse');

        $this->assertSame('paid', $request->fresh()->status);
        $this->assertSame('QJK7H2L9XP', $request->fresh()->payment_reference);
    }

    public function test_receipts_are_private(): void
    {
        $claim = app(Claims::class)->submit($this->john, ['type' => 'airtime', 'amount' => 100, 'description' => 'Calls'], ['receipt' => [UploadedFile::fake()->image('receipt.png')]]);
        $file = $claim->attachments->sole();

        $this->actingAs($this->john)->get(route('downloads.claim-attachment', $file))->assertOk();
        $this->actingAs($this->finance)->get(route('downloads.claim-attachment', $file))->assertOk();
        $this->actingAs(User::factory()->withRole(Role::Salesperson)->create())->get(route('downloads.claim-attachment', $file))->assertForbidden();
    }

    public function test_only_approvers_can_open_the_approvals_queue(): void
    {
        $this->actingAs($this->john)->get(route('claims.approvals'))->assertForbidden();
        $this->actingAs($this->hr)->get(route('claims.approvals'))->assertOk();
        $this->actingAs($this->hr)->get(route('claims.index'))->assertForbidden();
    }

    public function test_each_claim_row_has_a_real_button_that_opens_the_detail(): void
    {
        $claim = ExpenseClaim::factory()->create(['user_id' => $this->john->id]);

        Livewire::actingAs($this->john)->test(Index::class)
            ->assertSeeHtml('wire:click.stop="view('.$claim->id.')"')
            ->assertSeeHtml('aria-label="Open claim: ');
    }

    public function test_each_approval_row_has_a_real_button_that_opens_the_detail(): void
    {
        $claim = app(Claims::class)->submit($this->john, ['type' => 'airtime', 'amount' => 100, 'description' => 'Calls'], ['receipt' => [UploadedFile::fake()->image('receipt.png')]]);

        Livewire::actingAs($this->manager)->test(Approvals::class)
            ->assertSeeHtml('wire:click.stop="view('.$claim->id.')"');
    }
}
