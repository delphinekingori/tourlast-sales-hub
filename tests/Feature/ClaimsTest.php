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
}
