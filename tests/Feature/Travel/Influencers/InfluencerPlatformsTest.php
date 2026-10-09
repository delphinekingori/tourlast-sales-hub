<?php

namespace Tests\Feature\Travel\Influencers;

use App\Actions\Travel\Influencers\SaveInfluencer;
use App\Enums\Role;
use App\Livewire\Travel\Influencers\Index;
use App\Livewire\Travel\Influencers\Show;
use App\Models\AuditEvent;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InfluencerPlatformsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_influencer_can_be_on_several_platforms(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();

        Livewire::actingAs($seller)->test(Index::class)
            ->call('newInfluencer')
            ->set('influencerForm.name', 'Amina Wanjiru')
            ->set('influencerForm.platforms.0.handle', '@aminatravels')
            ->set('influencerForm.platforms.0.url', 'https://instagram.com/aminatravels')
            ->call('addPlatform')
            ->assertSet('influencerForm.platforms.1.platform', 'tiktok')
            ->set('influencerForm.platforms.1.handle', '@amina.ke')
            ->call('addPlatform')
            ->set('influencerForm.platforms.2.platform', 'youtube')
            ->call('saveInfluencer')
            ->assertHasNoErrors();

        $influencer = Influencer::query()->sole();
        $this->assertSame(
            [['instagram', '@aminatravels', 'https://instagram.com/aminatravels'], ['tiktok', '@amina.ke', null], ['youtube', null, null]],
            $influencer->platforms->map(fn ($platform) => [$platform->platform, $platform->handle, $platform->url])->all(),
        );
        $this->assertSame('instagram', $influencer->platform);
        $this->assertSame('@aminatravels', $influencer->handle);
        $this->assertSame('Instagram @aminatravels · TikTok @amina.ke · YouTube', $influencer->platformSummary());
    }

    public function test_platforms_can_be_edited_removed_and_are_audited(): void
    {
        $influencer = Influencer::factory()->create(['platform' => 'instagram', 'handle' => '@old']);

        Livewire::actingAs($influencer->owner)->test(Show::class, ['influencer' => $influencer->id])
            ->call('edit')
            ->assertSet('influencerForm.platforms.0.handle', '@old')
            ->call('addPlatform')
            ->set('influencerForm.platforms.1.platform', 'tiktok')
            ->set('influencerForm.platforms.1.handle', '@new')
            ->call('removePlatform', 0)
            ->call('save')
            ->assertHasNoErrors();

        $influencer->refresh();
        $this->assertSame(['tiktok'], $influencer->platforms->pluck('platform')->all());
        $this->assertSame('tiktok', $influencer->platform);
        $this->assertSame('@new', $influencer->handle);

        $audit = AuditEvent::query()->where('action', 'influencer.updated')->sole();
        $this->assertSame(['Instagram @old', 'TikTok @new'], $audit->changes['platforms']);
    }

    public function test_a_platform_can_only_be_added_once_and_links_must_be_full(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $save = app(SaveInfluencer::class);

        try {
            $save->handle($seller, ['name' => 'Twice', 'platforms' => [['platform' => 'instagram', 'handle' => '@a'], ['platform' => 'instagram', 'handle' => '@b']]]);
            $this->fail('A platform was added twice.');
        } catch (ValidationException $exception) {
            $this->assertSame('Each platform can only be added once.', $exception->errors()['platforms.0.platform'][0]);
        }

        $this->expectException(ValidationException::class);
        $save->handle($seller, ['name' => 'Bad link', 'platforms' => [['platform' => 'tiktok', 'url' => 'tiktok.com/@x']]]);
    }

    public function test_an_influencer_can_have_no_platform(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();

        $influencer = app(SaveInfluencer::class)->handle($seller, ['name' => 'Offline Olivia', 'platforms' => []]);

        $this->assertNull($influencer->platform);
        $this->assertSame('No platform', $influencer->platformSummary());
    }

    public function test_the_platform_filter_and_search_match_any_of_the_platforms(): void
    {
        $seller = User::factory()->withRole(Role::TravelSalesperson)->create();
        $both = app(SaveInfluencer::class)->handle($seller, ['name' => 'Both Bea', 'platforms' => [['platform' => 'instagram', 'handle' => '@bea'], ['platform' => 'tiktok', 'handle' => '@bea.tok']]]);
        $instagram = app(SaveInfluencer::class)->handle($seller, ['name' => 'Gram Gina', 'platforms' => [['platform' => 'instagram', 'handle' => '@gina']]]);
        InfluencerCode::factory()->create(['influencer_id' => $both->id, 'code' => 'BEA10']);
        InfluencerCode::factory()->create(['influencer_id' => $instagram->id, 'code' => 'GINA10']);

        Livewire::actingAs($seller)->test(Index::class)
            ->set('platform', 'tiktok')
            ->assertSee('BEA10')->assertDontSee('GINA10')
            ->set('platform', '')
            ->set('search', 'bea.tok')
            ->assertSee('BEA10')->assertDontSee('GINA10');
    }

    public function test_existing_influencers_keep_their_platform(): void
    {
        $influencer = Influencer::factory()->create(['platform' => 'youtube', 'handle' => '@tube']);

        $this->assertSame([['youtube', '@tube']], $influencer->platforms->map(fn ($platform) => [$platform->platform, $platform->handle])->all());
    }
}
