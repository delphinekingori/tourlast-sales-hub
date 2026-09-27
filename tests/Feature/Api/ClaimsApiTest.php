<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ClaimsApiTest extends TestCase
{
    use MakesApiRequests, RefreshDatabase;

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

    public function test_a_bolt_reimbursement_needs_the_trip_id_and_ride_details(): void
    {
        $payload = [
            'type' => 'transport_reimbursement', 'amount' => 640, 'description' => 'Partner training',
            'travel_date' => now()->subDay()->toDateString(), 'ride_provider' => 'bolt', 'pickup' => 'Westlands', 'dropoff' => 'Kilimani',
        ];

        $this->api($this->john, ['claims:write'])->postJson('/api/v1/claims', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['trip_reference', 'ride_details']);

        $response = $this->api($this->john, ['claims:write'])->post('/api/v1/claims', $payload + [
            'trip_reference' => 'RB12345678',
            'ride_details' => [UploadedFile::fake()->image('bolt-trip.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.current_step', 'manager')
            ->assertJsonPath('data.attachments.0.kind', 'ride_details');

        Storage::disk('local')->assertExists(ExpenseClaim::sole()->attachments->sole()->path);
    }

    public function test_the_approval_chain_runs_manager_then_hr_then_finance(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create(['amount' => 800]);

        $this->api($this->hr, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/approve')->assertForbidden();
        $this->api($this->manager, ['claims:read'])->getJson('/api/v1/claims/approvals')->assertOk()->assertJsonCount(1, 'data');

        $this->api($this->manager, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/approve', ['note' => 'Fine'])->assertOk()->assertJsonPath('data.current_step', 'hr');
        $this->api($this->hr, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/approve')->assertOk()->assertJsonPath('data.current_step', 'finance');

        $this->api($this->finance, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/approve', ['amount' => 900])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->api($this->finance, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/approve', ['amount' => 700])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_amount', 700)->assertJsonCount(3, 'data.approvals');
    }

    public function test_rejecting_needs_a_reason_and_people_cannot_approve_their_own_claims(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create();

        $this->api($this->manager, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/reject')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->api($this->manager, ['claims:write'])->postJson('/api/v1/claims/'.$claim->id.'/reject', ['note' => 'Trip not on a work day.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $own = ExpenseClaim::factory()->for($this->manager)->create();
        $this->api($this->manager, ['claims:write'])->postJson('/api/v1/claims/'.$own->id.'/approve')->assertForbidden();
    }

    public function test_claims_and_attachments_are_private_to_the_claimant_and_approvers(): void
    {
        $claim = ExpenseClaim::factory()->for($this->john)->create();
        $path = UploadedFile::fake()->image('receipt.jpg')->store('claims/'.$claim->id, 'local');
        $attachment = $claim->attachments()->create(['kind' => 'receipt', 'path' => $path, 'original_name' => 'receipt.jpg', 'mime' => 'image/jpeg', 'size' => 100]);
        $mary = User::factory()->withRole(Role::Salesperson)->create();

        $this->api($this->john, ['claims:read'])->getJson('/api/v1/claims')->assertOk()->assertJsonCount(1, 'data');
        $this->api($mary, ['claims:read'])->getJson('/api/v1/claims')->assertOk()->assertJsonCount(0, 'data');
        $this->api($mary, ['claims:read'])->getJson('/api/v1/claims/'.$claim->id)->assertForbidden();
        $this->api($mary, ['claims:read'])->get('/api/v1/claims/'.$claim->id.'/attachments/'.$attachment->id)->assertForbidden();

        $this->api($this->hr, ['claims:read'])->get('/api/v1/claims/'.$claim->id.'/attachments/'.$attachment->id)->assertOk();
        $this->api($this->john, ['claims:read'])->getJson('/api/v1/claims/'.$claim->id)->assertOk()->assertJsonPath('data.attachments.0.name', 'receipt.jpg');
    }

    public function test_finance_disburses_approved_transport_requests(): void
    {
        $request = ExpenseClaim::factory()->for($this->john)->create(['type' => 'transport_request', 'status' => 'approved', 'current_step' => null, 'travel_date' => now()->addDay()->toDateString()]);

        $this->api($this->manager, ['claims:write'])->postJson('/api/v1/claims/'.$request->id.'/disburse', ['payment_reference' => 'QX1'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
        $this->api($this->finance, ['claims:write'])->postJson('/api/v1/claims/'.$request->id.'/disburse', ['payment_reference' => 'QX1'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_scopes_and_roles_are_enforced(): void
    {
        $this->api($this->john, ['claims:read'])->postJson('/api/v1/claims', [])->assertForbidden()->assertJsonPath('required_scopes', ['claims:write']);
        $this->api($this->hr, ['claims:read'])->getJson('/api/v1/claims')->assertForbidden();
        $this->api($this->john, ['claims:read'])->getJson('/api/v1/claims/approvals')->assertForbidden();
    }
}
