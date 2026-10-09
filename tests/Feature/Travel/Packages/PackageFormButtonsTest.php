<?php

namespace Tests\Feature\Travel\Packages;

use App\Enums\Role;
use App\Livewire\Travel\Packages\Form;
use App\Models\Package;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The form's Save draft / Save & submit buttons, as a salesperson uses them:
 * a draft needs only the basics, and any error must be visible.
 */
class PackageFormButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_saves_with_only_the_basics(): void
    {
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        $provider = TravelProvider::factory()->create(['owner_id' => $me->id]);

        $component = Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Lake Nakuru Day Trip')
            ->set('form.short_description', 'Flamingos and rhinos in a day.')
            ->set('form.description', 'A full day at Lake Nakuru National Park.')
            ->set('form.travel_provider_id', (string) $provider->id)
            ->set('form.destination', 'Lake Nakuru')
            ->call('save');

        $this->assertSame([], $component->errors()->toArray());
        $component->assertRedirect();
        $this->assertSame(1, Package::query()->where('name', 'Lake Nakuru Day Trip')->count());
    }

    public function test_an_error_on_another_tab_is_listed_and_that_tab_opens(): void
    {
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        $provider = TravelProvider::factory()->create(['owner_id' => $me->id]);

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Lake Nakuru Day Trip')
            ->set('form.travel_provider_id', (string) $provider->id)
            ->set('form.destination', 'Lake Nakuru')
            ->set('form.adult_price', 'abc')
            ->assertSet('tab', 'basics')
            ->call('save')
            ->assertHasErrors('form.adult_price')
            ->assertSet('tab', 'pricing')
            ->assertSee('Not saved yet');

        $this->assertSame(0, Package::query()->count());
    }

    public function test_submit_explains_what_is_missing_instead_of_doing_nothing(): void
    {
        $me = User::factory()->withRole(Role::TravelSalesperson)->create();
        $provider = TravelProvider::factory()->create(['owner_id' => $me->id]);

        Livewire::actingAs($me)->test(Form::class)
            ->set('form.name', 'Lake Nakuru Day Trip')
            ->set('form.travel_provider_id', (string) $provider->id)
            ->set('form.destination', 'Lake Nakuru')
            ->call('saveAndSubmit')
            ->assertHasErrors('package')
            ->assertSee('Not submitted yet. Complete these first');

        $this->assertSame(0, Package::query()->count());
    }
}
