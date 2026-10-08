<?php

namespace Tests\Feature\Travel\Providers;

use App\Enums\Role;
use App\Enums\Travel\TravelProviderStatus;
use App\Livewire\Travel\Providers\Form;
use App\Livewire\Travel\Providers\Show;
use App\Models\AuditEvent;
use App\Models\PropertyEngagement;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProviderFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_travel_salesperson_adds_a_provider_they_own(): void
    {
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        $other = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Kilele Mountain Guides')
            ->set('form.provider_type', 'guide_company')
            ->set('form.city', 'Nanyuki')
            ->set('form.phone', '0711 222 333')
            ->set('form.owner_id', $other->id) // ignored: salespeople always own what they add
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $provider = TravelProvider::query()->where('name', 'Kilele Mountain Guides')->firstOrFail();
        $this->assertSame($me->id, $provider->owner_id);
        $this->assertSame($me->id, $provider->created_by);
        $this->assertTrue(AuditEvent::query()->where('action', 'provider.created')->where('subject_id', $provider->id)->exists());
    }

    public function test_a_strong_duplicate_blocks_a_travel_salesperson_but_a_manager_may_continue(): void
    {
        TravelProvider::factory()->create(['name' => 'Twiga Plains Safaris', 'email' => 'hello@twigaplains.co.ke']);

        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Twiga Plains')
            ->set('form.email', 'HELLO@twigaplains.co.ke')
            ->call('save')
            ->assertHasErrors('form.name')
            ->assertSee('Strong match')
            ->call('continueAnyway')
            ->assertForbidden();

        $this->assertSame(1, TravelProvider::query()->count());

        $admin = User::factory()->withRole(Role::SalesAdmin)->create();
        Livewire::actingAs($admin)->test(Form::class)
            ->set('form.name', 'Twiga Plains')
            ->set('form.email', 'hello@twigaplains.co.ke')
            ->call('save')
            ->assertSee('Continue anyway')
            ->call('continueAnyway')
            ->assertRedirect();

        $this->assertSame(2, TravelProvider::query()->count());
    }

    public function test_a_similar_name_alone_can_be_confirmed_as_different(): void
    {
        TravelProvider::factory()->create(['name' => 'Ndovu Bush Camp Tours', 'city' => 'Nairobi']);
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Ndovu Bush Adventures')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Continue anyway')
            ->call('continueAnyway')
            ->assertRedirect();

        $this->assertSame(2, TravelProvider::query()->count());
    }

    public function test_travel_salespeople_never_see_registry_records(): void
    {
        $engagement = PropertyEngagement::factory()->create(['name' => 'Kudu Hills Experiences']);
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Kudu Hills Experiences')
            ->assertDontSee('Link to this Registry record')
            ->assertDontSee('Property Engagement Registry')
            ->call('linkRegistry', $engagement->id)
            ->assertForbidden();
    }

    public function test_managers_can_link_matching_registry_records_but_they_are_never_created(): void
    {
        $engagement = PropertyEngagement::factory()->create(['name' => 'Kudu Hills Experiences']);
        $me = User::factory()->withRole(Role::SalesAdmin)->create();
        $registryCount = PropertyEngagement::query()->count();

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Kudu Hills Experiences')
            ->assertSee('Link to this Registry record')
            ->call('linkRegistry', $engagement->id)
            ->call('save')
            ->assertRedirect();

        $this->assertSame($engagement->id, TravelProvider::query()->firstOrFail()->property_engagement_id);
        $this->assertSame($registryCount, PropertyEngagement::query()->count());
    }

    public function test_status_changes_are_audited_and_other_salespeople_cannot_edit(): void
    {
        $owner = User::factory()->withRole(Role::TravelSalesperson)->create();
        $provider = TravelProvider::factory()->prospect()->create(['owner_id' => $owner->id]);

        Livewire::actingAs($owner)->test(Form::class, ['provider' => $provider->id])
            ->set('form.status', TravelProviderStatus::Interested->value)
            ->call('save')
            ->assertRedirect();

        $event = AuditEvent::query()->where('action', 'provider.status_changed')->firstOrFail();
        $this->assertSame(['prospect', 'interested'], $event->changes['status']);

        $stranger = User::factory()->withRole(Role::TravelSalesperson)->create();
        Livewire::actingAs($stranger)->test(Form::class, ['provider' => $provider->id])->assertForbidden();
    }

    public function test_only_travel_managers_archive_providers(): void
    {
        $provider = TravelProvider::factory()->create();

        Livewire::actingAs($provider->owner)->test(Show::class, ['provider' => $provider->id])
            ->call('archive')
            ->assertForbidden();

        Livewire::actingAs(User::factory()->withRole(Role::SalesAdmin)->create())->test(Show::class, ['provider' => $provider->id])
            ->call('archive');

        $this->assertNotNull($provider->fresh()->archived_at);
    }
}
