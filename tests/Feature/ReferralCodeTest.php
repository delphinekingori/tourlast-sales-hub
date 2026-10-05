<?php

namespace Tests\Feature;

use App\Actions\IssueReferralCode;
use App\Enums\ReferralTarget;
use App\Enums\Role;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_roles_get_a_code_in_the_agreed_format(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create(['name' => 'Wanjirū Kariuki']);

        $code = app(IssueReferralCode::class)->handle($user);

        $this->assertMatchesRegularExpression('/^TL-WANJIRU-\d{4}$/', $code->code);
    }

    public function test_hr_accounts_and_super_admins_do_not_get_codes(): void
    {
        foreach ([Role::Hr, Role::Accounts, Role::SuperAdmin] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->assertNull(app(IssueReferralCode::class)->handle($user));
        }

        $this->assertSame(0, ReferralCode::count());
    }

    public function test_issuing_again_keeps_the_same_permanent_code(): void
    {
        $user = User::factory()->withRole(Role::SalesManager)->create();

        $first = app(IssueReferralCode::class)->handle($user);
        $second = app(IssueReferralCode::class)->handle($user);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ReferralCode::count());
    }

    public function test_the_tracked_link_forwards_to_tourlast_with_the_code(): void
    {
        $code = ReferralCode::factory()->create(['code' => 'TL-JOHN-2847']);

        $this->assertSame(url('/r/TL-JOHN-2847'), $code->shareUrl());

        $this->get('/r/TL-JOHN-2847')
            ->assertRedirect('https://www.tourlast.com/list-your-property?ref=TL-JOHN-2847');

        $this->get('/r/tl-john-2847')
            ->assertRedirect('https://www.tourlast.com/list-your-property?ref=TL-JOHN-2847');
    }

    public function test_an_unknown_code_still_reaches_list_your_property(): void
    {
        $this->get('/r/TL-NOBODY-0000')->assertRedirect('https://www.tourlast.com/list-your-property');
    }

    public function test_the_experiences_link_forwards_to_the_experiences_registration_with_the_code(): void
    {
        config(['hub.list_experiences_url' => 'https://experiences.example.test/provider/register']);
        $code = ReferralCode::factory()->create(['code' => 'TL-JOHN-2847']);

        $this->assertSame(url('/r/TL-JOHN-2847/experiences'), $code->shareUrl(ReferralTarget::Experiences));

        $this->get('/r/TL-JOHN-2847/experiences')
            ->assertRedirect('https://experiences.example.test/provider/register?ref=TL-JOHN-2847');
    }

    public function test_an_unknown_code_on_the_experiences_link_still_reaches_experiences_registration(): void
    {
        config(['hub.list_experiences_url' => 'https://experiences.example.test/provider/register']);

        $this->get('/r/TL-NOBODY-0000/experiences')->assertRedirect('https://experiences.example.test/provider/register');
    }

    public function test_an_unknown_target_is_not_a_route(): void
    {
        ReferralCode::factory()->create(['code' => 'TL-JOHN-2847']);

        $this->get('/r/TL-JOHN-2847/flights')->assertNotFound();
    }

    public function test_each_click_records_which_link_was_used(): void
    {
        $code = ReferralCode::factory()->create(['code' => 'TL-JOHN-2847']);
        $browser = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'];

        $this->withHeaders($browser)->get('/r/TL-JOHN-2847');
        $this->withHeaders($browser)->get('/r/TL-JOHN-2847/experiences');

        $this->assertSame(
            [ReferralTarget::Stays, ReferralTarget::Experiences],
            $code->clicks()->orderBy('id')->get()->map->target->all(),
        );
    }

    public function test_a_salesperson_sees_their_link_on_the_home_page(): void
    {
        $user = User::factory()->withRole(Role::Salesperson)->create(['name' => 'John Doe']);
        $code = app(IssueReferralCode::class)->handle($user);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Good')
            ->assertSee($code->code)
            ->assertSee($code->shareUrl());
    }
}
