<?php

namespace Database\Seeders;

use App\Actions\IssueReferralCode;
use App\Actions\SyncOnboardings;
use App\Actions\SyncOnboardingToRegistry;
use App\Enums\ActivityType;
use App\Enums\EngagementEventType;
use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\LeadStatus;
use App\Enums\Objection;
use App\Enums\Role;
use App\Incentives\AccountPoints;
use App\Incentives\ChecklistItem;
use App\Incentives\Claims;
use App\Incentives\Statements;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\FollowUp;
use App\Models\IncentiveAgreement;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PaymentDetail;
use App\Models\PayoutStatement;
use App\Models\PropertyEngagement;
use App\Models\ReferralClick;
use App\Models\SandboxProvider;
use App\Models\Target;
use App\Models\User;
use App\Support\Alerts;
use App\Support\SampleData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    /**
     * Example accounts and activity for local development only. Every password is "password".
     *
     * Onboardings are created as sandbox providers and pulled in through the real
     * sync, exactly as tourlast.com data will be.
     */
    public function run(IssueReferralCode $issueReferralCode, SyncOnboardings $syncOnboardings): void
    {
        SampleData::ensureAllowed('DemoSeeder');

        fake()->seed(2026);

        $people = [
            ['Super Admin', 'admin@tourlast.test', Role::SuperAdmin, null],
            ['Grace Njeri', 'grace@tourlast.test', Role::SalesAdmin, 'Nairobi'],
            ['David Otieno', 'david@tourlast.test', Role::SalesManager, 'Coast'],
            ['John Doe', 'john@tourlast.test', Role::Salesperson, 'Nairobi'],
            ['Mary Wambui', 'mary@tourlast.test', Role::Salesperson, 'Nairobi'],
            ['Peter Kamau', 'peter@tourlast.test', Role::Salesperson, 'Coast'],
            ['James Mwangi', 'james@tourlast.test', Role::Salesperson, 'Western'],
            ['Faith Achieng', 'faith@tourlast.test', Role::Hr, null],
            ['Samuel Kiprono', 'samuel@tourlast.test', Role::Accounts, null],
        ];

        foreach ($people as [$name, $email, $role, $region]) {
            $user = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'region' => $region,
                'phone' => '+2547'.fake()->numerify('########'),
                'last_login_at' => now()->subHours(fake()->numberBetween(1, 60)),
            ]);
            $user->assignRole($role->value);
            $issueReferralCode->handle($user);
        }

        $grace = User::where('email', 'grace@tourlast.test')->first();

        Invitation::factory()->for($grace, 'inviter')->create([
            'name' => 'Lucy Wanjiku', 'email' => 'lucy@tourlast.test', 'role' => Role::Salesperson, 'region' => 'Nairobi',
        ]);
        Invitation::factory()->for($grace, 'inviter')->expired()->create([
            'name' => 'Brian Ochieng', 'email' => 'brian@tourlast.test', 'role' => Role::Salesperson, 'region' => 'Rift Valley',
        ]);

        /** @var array<string, array{0: int, 1: int, 2: int}> $profiles monthly onboardings range, target in points, activity level */
        $profiles = [
            'mary@tourlast.test' => [[6, 11], 32, 5],
            'john@tourlast.test' => [[3, 8], 30, 4],
            'peter@tourlast.test' => [[2, 5], 20, 3],
            'james@tourlast.test' => [[0, 2], 18, 0],
            'grace@tourlast.test' => [[1, 3], 10, 2],
            'david@tourlast.test' => [[1, 4], 12, 2],
        ];

        foreach (['john', 'mary', 'peter', 'james'] as $name) {
            IncentiveAgreement::create([
                'user_id' => User::where('email', $name.'@tourlast.test')->value('id'),
                'starts_on' => now()->startOfMonth()->subMonths(6)->toDateString(),
                'notes' => 'Signed with Schedule 1 attached',
            ]);
        }

        $now = CarbonImmutable::now();

        foreach ($profiles as $email => [[$min, $max], $target, $activityLevel]) {
            $seller = User::where('email', $email)->first();
            $code = $seller->referralCode->code;

            for ($offset = 5; $offset >= 0; $offset--) {
                $month = $now->startOfMonth()->subMonths($offset);
                $lastDay = $offset === 0 ? max(1, $now->day - 1) : $month->daysInMonth;

                if ($email !== 'james@tourlast.test' || $offset > 0) {
                    Target::create(['user_id' => $seller->id, 'month' => $month->toDateString(), 'target' => $target + fake()->numberBetween(-2, 2)]);
                }

                $count = $offset === 0 ? (int) round(fake()->numberBetween($min, $max) * $now->day / $now->daysInMonth) : fake()->numberBetween($min, $max);

                for ($i = 0; $i < $count; $i++) {
                    $approvedAt = $month->addDays(fake()->numberBetween(0, $lastDay - 1))->setTime(fake()->numberBetween(8, 17), fake()->numberBetween(0, 59));
                    $live = $approvedAt->lt($now->subDays(4)) && fake()->boolean(80);
                    $this->provider($code, $live ? 'active' : 'approved', $approvedAt->subDays(fake()->numberBetween(2, 9)), $approvedAt, $live ? $approvedAt->addDays(fake()->numberBetween(1, 4)) : null);
                }

                $clicks = $count * fake()->numberBetween(3, 6) + fake()->numberBetween(0, 6);

                for ($i = 0; $i < $clicks; $i++) {
                    ReferralClick::create([
                        'referral_code_id' => $seller->referralCode->id,
                        'ip_hash' => hash('sha256', fake()->ipv4()),
                        'user_agent' => 'Mozilla/5.0',
                        'clicked_at' => $month->addDays(fake()->numberBetween(0, $lastDay - 1))->addHours(fake()->numberBetween(8, 20)),
                    ]);
                }
            }

            foreach (range(1, fake()->numberBetween(1, 3)) as $i) {
                $this->provider($code, fake()->randomElement(['submitted', 'under_review']), $now->subDays(fake()->numberBetween(1, 10)));
            }

            if (in_array($email, ['john@tourlast.test', 'peter@tourlast.test'], true)) {
                $this->provider($code, 'under_review', $now->subDays(fake()->numberBetween(16, 25)));
            }

            $this->leadsFor($seller, $activityLevel);
        }

        // A realistic day for John: a call, a meeting and a site visit.
        $john = User::where('email', 'john@tourlast.test')->first();
        $johnsLeads = Lead::query()->where('user_id', $john->id)->where('status', '!=', LeadStatus::Lost)->take(3)->get();
        foreach ([[ActivityType::Call, 'Follow-up on the proposal', 9, 0, null], [ActivityType::Meeting, 'Proposal discussion', 11, 30, 60], [ActivityType::SiteVisit, 'Walk the property with the GM', 14, 0, 90]] as $i => [$type, $task, $hour, $minute, $duration]) {
            if ($lead = $johnsLeads->get($i)) {
                FollowUp::factory()->for($lead)->for($john)->create([
                    'type' => $type, 'task' => $task, 'due_at' => now()->setTime($hour, $minute), 'has_time' => true,
                    'duration_minutes' => $duration, 'contact_name' => $lead->contact_name, 'contact_role' => $lead->contact_role,
                    'location' => $type === ActivityType::SiteVisit ? $lead->location : null,
                ]);
            }
        }

        foreach (['mary@tourlast.test' => 'Coral Bay Hospitality Ltd', 'john@tourlast.test' => 'Savannah Stays Group Ltd'] as $email => $group) {
            $code = User::where('email', $email)->first()->referralCode->code;
            $accountId = 'H-'.fake()->unique()->numerify('####');
            $first = $now->subDays(min(20, $now->day - 1));
            $this->provider($code, 'active', $first->subDays(4), $first, $first, ['account_id' => $accountId, 'legal_name' => $group, 'property_type' => 'hotel', 'inventory_count' => 30]);
            $this->provider($code, 'active', $first->subDays(4), $first, $first, ['account_id' => $accountId, 'legal_name' => $group, 'property_type' => 'hotel', 'inventory_count' => 50]);
            $this->provider($code, 'active', $first->subDays(2), $first->addDays(8), $first->addDays(9), ['account_id' => $accountId, 'legal_name' => $group, 'property_type' => 'apartment', 'inventory_count' => 40]);
        }

        $this->provider(null, 'approved', $now->subDays(6), $now->subDays(2));
        $this->provider(null, 'submitted', $now->subDays(1));

        $syncOnboardings->handle('full');

        $this->incentives();
        $this->people();
        $this->registry();
    }

    /**
     * Property Engagement Registry: properties at every stage, some handed from
     * one salesperson to another, a few linked to synced onboardings and leads.
     */
    private function registry(): void
    {
        $reps = User::query()->whereIn('email', ['john@tourlast.test', 'mary@tourlast.test', 'peter@tourlast.test', 'james@tourlast.test', 'david@tourlast.test'])->get()->keyBy('email');
        $manager = User::where('email', 'david@tourlast.test')->first();
        $now = CarbonImmutable::now();
        $story = [
            'whatsapp' => ['WhatsApp intro sent', 'Shared the Tourlast partner deck.'],
            'call' => ['Initial call', 'GM unavailable. Spoke with the Sales Manager.'],
            'meeting' => ['Meeting completed', 'GM requested a commercial proposal.'],
            'proposal' => ['Proposal sent', 'Commission and payout terms shared by email.'],
            'negotiation' => ['Negotiating commission', 'Asked for a lower rate in the first three months.'],
        ];

        $records = [
            ['PrideInn Paradise Beach Resort', 'resort', 'Mombasa', 'Mombasa', 'Shanzu', EngagementStage::ProposalSent, EngagementStatus::Active, ['john', 'mary']],
            ['Sarova Whitesands Beach Resort', 'resort', 'Mombasa', 'Mombasa', 'Bamburi', EngagementStage::Negotiation, EngagementStatus::Active, ['mary']],
            ['Leopard Beach Resort', 'resort', 'Kwale', 'Diani', 'Diani Beach', EngagementStage::MeetingCompleted, EngagementStatus::Stalled, ['peter']],
            ['Swahili Beach Hotel', 'hotel', 'Kwale', 'Diani', 'Galu', EngagementStage::Contacted, EngagementStatus::Active, ['peter']],
            ['Tamarind Dhow Restaurant', 'restaurant', 'Mombasa', 'Mombasa', 'Nyali', EngagementStage::Interested, EngagementStatus::Active, ['john']],
            ['Mara Serena Safari Lodge', 'lodge', 'Narok', 'Maasai Mara', 'Mara Triangle', EngagementStage::ProposalSent, EngagementStatus::Lost, ['james', 'john']],
            ['Ashnil Mara Camp', 'lodge', 'Narok', 'Maasai Mara', 'Talek', EngagementStage::Demo, EngagementStatus::Active, ['mary']],
            ['Lake Naivasha Sopa Resort', 'resort', 'Nakuru', 'Naivasha', 'Moi South Lake Road', EngagementStage::MeetingScheduled, EngagementStatus::Active, ['john']],
            ['Kilima Safaris', 'tour', 'Nairobi', 'Nairobi', 'Westlands', EngagementStage::OnboardingStarted, EngagementStatus::Active, ['mary']],
            ['Bush Adventures Ltd', 'activity', 'Nakuru', 'Naivasha', 'Hells Gate', EngagementStage::Contacted, EngagementStatus::ReEngage, ['james']],
            ['Nairobi Serviced Suites', 'apartment', 'Nairobi', 'Nairobi', 'Kilimani', EngagementStage::NotContacted, EngagementStatus::Active, ['john']],
            ['Hemingways Watamu', 'hotel', 'Kilifi', 'Watamu', 'Turtle Bay', EngagementStage::Negotiation, EngagementStatus::Stalled, ['peter', 'mary']],
            ['Peponi Hotel', 'hotel', 'Lamu', 'Lamu', 'Shela', EngagementStage::MeetingCompleted, EngagementStatus::Rejected, ['peter']],
            ['Coast Transfers & Shuttles', 'transport', 'Mombasa', 'Mombasa', 'Moi Airport', EngagementStage::Interested, EngagementStatus::Active, ['david']],
            ['Pollman Tours DMC', 'dmc', 'Nairobi', 'Nairobi', 'Upper Hill', EngagementStage::Contacted, EngagementStatus::Closed, ['david']],
            ['Kisumu Hotel & Conference Centre', 'venue', 'Kisumu', 'Kisumu', 'Milimani', EngagementStage::Contacted, EngagementStatus::Active, ['james']],
        ];

        foreach ($records as $index => [$name, $type, $region, $city, $area, $stage, $status, $repNames]) {
            $first = $now->subDays(20 + $index * 9)->startOfDay();
            $assigned = array_map(fn (string $rep) => $reps[$rep.'@tourlast.test'], $repNames);
            $engagement = PropertyEngagement::factory()->forRep(end($assigned))->create([
                'name' => $name, 'property_type' => $type, 'region' => $region, 'city' => $city, 'area' => $area,
                'stage' => $stage, 'status' => $status, 'first_engaged_on' => $first, 'last_engaged_on' => $now->subDays($index % 7 + 1),
                'created_by' => $manager->id, 'updated_by' => $manager->id,
                'website' => str_contains($name, 'PrideInn') ? 'https://www.prideinnhotels.com' : null,
                'next_action' => $status->isOpen() ? fake()->randomElement(['Send commercial proposal', 'Follow up with the GM', 'Book a site visit', 'Share onboarding link']) : null,
                'next_action_on' => $status->isOpen() ? $now->addDays(fake()->numberBetween(-3, 10)) : null,
            ]);
            $engagement->reps()->delete();
            $this->registryHistory($engagement, $assigned, $first, $stage, $story, $manager);
        }

        // Why the lost, rejected and paused registry properties said no.
        foreach ([
            'Mara Serena Safari Lodge' => [Objection::OtherOta, 'Booking.com', 'Management renewed their Booking.com contract for another year.', $now->addMonths(5)],
            'Peponi Hotel' => [Objection::Commission, null, 'Would only consider 10% commission.', $now->addMonths(3)],
            'Pollman Tours DMC' => [Objection::CompetitorRelationship, 'Tour operator / DMC contract', 'Exclusive allotments with a German tour operator.', null],
            'Leopard Beach Resort' => [Objection::ManagementApproval, null, 'GM supportive; owner in Europe until December.', $now->addWeeks(6)],
            'Hemingways Watamu' => [Objection::HasPms, null, 'Worried about double bookings with their PMS.', $now->addWeeks(3)],
        ] as $name => [$objection, $competitor, $notes, $reengage]) {
            PropertyEngagement::query()->where('name', $name)->update([
                'objection' => $objection, 'competitor' => $competitor, 'outcome_notes' => $notes,
                'reengage_on' => $reengage?->toDateString(), 'closed_at' => $now->subDays(fake()->numberBetween(10, 90)),
            ]);
        }
        // Mary's lead for PrideInn: her calls and next meeting appear in the property's history.
        $mary = $reps['mary@tourlast.test'];
        $prideInn = PropertyEngagement::query()->where('name', 'PrideInn Paradise Beach Resort')->first();
        $prideLead = Lead::factory()->for($mary)->create([
            'business_name' => 'PrideInn Paradise Beach Resort', 'property_type' => 'resort', 'location' => 'Shanzu, Mombasa',
            'contact_name' => 'John Wambua', 'contact_role' => 'General Manager', 'status' => LeadStatus::Meeting,
            'property_engagement_id' => $prideInn->id, 'last_contacted_at' => $now->subDays(2),
        ]);
        Activity::factory()->for($prideLead)->for($mary)->create([
            'type' => ActivityType::Call, 'happened_at' => $now->subDays(2)->setTime(10, 15),
            'notes' => 'GM confirmed interest. Wants to discuss commission before signing up.', 'next_action' => 'Proposal discussion meeting',
        ]);
        FollowUp::factory()->for($prideLead)->for($mary)->create([
            'type' => ActivityType::Meeting, 'task' => 'Proposal discussion', 'due_at' => $now->addDays(2)->setTime(11, 30), 'has_time' => true,
            'duration_minutes' => 60, 'contact_name' => 'John Wambua', 'contact_role' => 'General Manager', 'location' => 'PrideInn Paradise, Shanzu',
            'notes' => 'Next action: send proposal. Follow up in 3 days.',
        ]);

        // Signups that came through the registry: stage follows tourlast.com from here.
        $sync = app(SyncOnboardingToRegistry::class);

        Onboarding::query()->whereNotNull('user_id')->with('user')->where('status', 'active')->latest('submitted_at')->limit(5)->get()
            ->merge(Onboarding::query()->whereNotNull('user_id')->with('user')->where('status', '!=', 'active')->latest('submitted_at')->limit(3)->get())
            ->each(function (Onboarding $onboarding) use ($story, $manager, $sync): void {
                $first = CarbonImmutable::parse($onboarding->submitted_at)->subDays(fake()->numberBetween(10, 40))->startOfDay();
                $engagement = PropertyEngagement::factory()->forRep($onboarding->user)->create([
                    'name' => $onboarding->property_name, 'property_type' => $onboarding->property_type,
                    'stage' => EngagementStage::OnboardingStarted, 'status' => EngagementStatus::Active,
                    'first_engaged_on' => $first, 'last_engaged_on' => $onboarding->submitted_at, 'tourlast_property_id' => $onboarding->tourlast_property_id,
                    'source' => EngagementSource::Referral, 'created_by' => $manager->id,
                ]);
                $engagement->reps()->delete();
                $this->registryHistory($engagement, [$onboarding->user], $first, EngagementStage::OnboardingStarted, $story, $manager);
                $sync->handle($onboarding);
            });
    }

    /**
     * @param  list<User>  $reps  In order; the last one is current.
     * @param  array<string, array{0: string, 1: string}>  $story
     */
    private function registryHistory(PropertyEngagement $engagement, array $reps, CarbonImmutable $first, EngagementStage $stage, array $story, User $manager): void
    {
        $engagement->events()->create([
            'type' => EngagementEventType::Created, 'sales_rep_id' => $reps[0]->id, 'recorded_by' => $manager->id,
            'to_value' => $stage->value, 'summary' => 'Added to the registry', 'happened_at' => $first,
        ]);

        $steps = array_slice(array_keys($story), 0, max(1, min(count($story), intdiv($stage->order(), 2) + 1)));
        $span = max(1, (int) $first->diffInDays(now()) - 2);

        foreach ($steps as $i => $key) {
            $rep = $reps[min(count($reps) - 1, intdiv($i * count($reps), count($steps)))];
            $engagement->events()->create([
                'type' => EngagementEventType::from($key), 'sales_rep_id' => $rep->id, 'recorded_by' => $manager->id,
                'summary' => $story[$key][0], 'notes' => $story[$key][1],
                'happened_at' => $first->addDays((int) round(($i + 1) * $span / (count($steps) + 1)))->setTime(11, 0),
            ]);
        }

        foreach ($reps as $i => $rep) {
            $start = $i === 0 ? $first : $first->addDays((int) round($i * $span / count($reps)));
            $end = $i < count($reps) - 1 ? $first->addDays((int) round(($i + 1) * $span / count($reps))) : null;
            $engagement->reps()->create(['user_id' => $rep->id, 'started_on' => $start, 'ended_on' => $end, 'assigned_by' => $manager->id]);

            if ($i > 0) {
                $engagement->events()->create([
                    'type' => EngagementEventType::RepChanged, 'sales_rep_id' => $rep->id, 'recorded_by' => $manager->id,
                    'from_value' => $reps[$i - 1]->name, 'to_value' => $rep->name, 'happened_at' => $start->setTime(9, 0),
                ]);
            }
        }
    }

    /**
     * Job titles, payout details, who is online, a few announcements and recent alerts.
     */
    private function people(): void
    {
        $titles = [
            'admin@tourlast.test' => 'Systems Administrator', 'grace@tourlast.test' => 'Head of Sales', 'david@tourlast.test' => 'Regional Sales Manager, Coast',
            'john@tourlast.test' => 'Business Development Executive', 'mary@tourlast.test' => 'Senior Business Development Executive',
            'peter@tourlast.test' => 'Business Development Executive', 'james@tourlast.test' => 'Business Development Associate',
            'faith@tourlast.test' => 'HR Officer', 'samuel@tourlast.test' => 'Finance Officer',
        ];

        foreach ($titles as $email => $title) {
            User::where('email', $email)->update(['job_title' => $title]);
        }

        User::whereIn('email', ['mary@tourlast.test', 'david@tourlast.test'])->update(['last_seen_at' => now()->subMinute()]);
        User::where('email', 'peter@tourlast.test')->update(['last_seen_at' => now()->subHours(3)]);

        PaymentDetail::create(['user_id' => User::where('email', 'mary@tourlast.test')->value('id'), 'method' => 'mpesa', 'mpesa_phone' => '254712345678', 'mpesa_name' => 'MARY WAMBUI']);
        PaymentDetail::create(['user_id' => User::where('email', 'peter@tourlast.test')->value('id'), 'method' => 'bank', 'bank_name' => 'Equity Bank', 'bank_branch' => 'Mombasa', 'account_number' => '0170291234567', 'account_name' => 'PETER KAMAU']);

        DB::table('notifications')->delete();

        $grace = User::where('email', 'grace@tourlast.test')->first();
        $samuel = User::where('email', 'samuel@tourlast.test')->first();
        $faith = User::where('email', 'faith@tourlast.test')->first();

        Announcement::create(['user_id' => $samuel->id, 'title' => 'August incentives paid', 'body' => 'August statements have been paid. Check My Earnings for your statement and payment reference. Please make sure your M-Pesa or bank details are up to date before 1 October.', 'audience' => ['sales'], 'importance' => 'normal', 'created_at' => now()->subDays(20)]);
        Announcement::create(['user_id' => $grace->id, 'title' => 'Q4 push: villas and beachfront stays', 'body' => 'Tourlast is prioritising villas, cottages and beachfront stays on the Coast for the holiday season. Every Account still earns points by size, so focus on properties that can go live quickly.', 'audience' => ['everyone'], 'importance' => 'important', 'created_at' => now()->subDays(2)]);
        Announcement::create(['user_id' => $faith->id, 'title' => 'Update your profile', 'body' => 'Please add a profile photo, your position and an emergency contact under My profile by Friday.', 'audience' => ['everyone'], 'importance' => 'normal', 'created_at' => now()->subHours(5)]);

        $mary = User::where('email', 'mary@tourlast.test')->first();
        $john = User::where('email', 'john@tourlast.test')->first();
        Alerts::send('property_referred', 'New property referred', 'Kilima Bay Villas signed up on tourlast.com through the referral link of Mary Wambui.', route('accounts.index'), $mary);
        Alerts::send('partner_approved', 'Partner approved', 'Coral Ridge Cottages by John Doe was approved by Tourlast.', route('accounts.index'), $john);
        Alerts::send('first_booking', 'First booking received', 'Savannah Palms Hotel by Mary Wambui received its first booking on tourlast.com.', route('accounts.index'), $mary);
        Alerts::send('follow_up_overdue', 'Follow-up overdue', 'John Doe has 2 overdue follow-ups.', route('activities.index'), $john);
        Alerts::send('deal_lost', 'Deal lost', 'Duma Lodge was marked lost by Peter Kamau: Signed an exclusive deal with another OTA', route('leads.index'));
        Alerts::sendToPaymentReviewers('payment_details_changed', 'Payment details changed', 'Mary Wambui added payout details (M-Pesa, paid to MARY WAMBUI).', route('payment-details.index'));
    }

    /**
     * Verify most live Accounts, add a same-category expansion, fail one review,
     * create claims at every approval step and pay last month's statements.
     */
    private function incentives(): void
    {
        $accountPoints = app(AccountPoints::class);
        $claims = app(Claims::class);
        $statements = app(Statements::class);
        $grace = User::where('email', 'grace@tourlast.test')->first();
        $david = User::where('email', 'david@tourlast.test')->first();
        $faith = User::where('email', 'faith@tourlast.test')->first();
        $samuel = User::where('email', 'samuel@tourlast.test')->first();

        $accounts = PartnerAccount::query()->current()->whereNotNull('activation_date')->whereNotNull('user_id')->orderBy('activation_date')->get();

        foreach ($accounts as $account) {
            $recent = $account->activation_date->gt(now()->subDays(6));

            if ($recent && fake()->boolean(60)) {
                continue;
            }

            foreach (ChecklistItem::cases() as $item) {
                if (! $item->isAutomatic()) {
                    $account->checklistItems()->updateOrCreate(['item' => $item->value], ['completed_at' => $account->activation_date->addDay(), 'completed_by' => $grace->id, 'source' => 'manual']);
                }
            }

            $accountPoints->verify($account->fresh(), $grace, $account->activation_inventory ?? fake()->numberBetween(5, 60), $account->inventory_basis);
        }

        $growing = PartnerAccount::query()->current()->where('category', 'stay')->where('qualification_status', 'verified')
            ->whereHas('user', fn ($query) => $query->where('email', 'mary@tourlast.test'))
            ->where('activation_date', '>', now()->subDays(60))->where('activation_inventory', '>=', 12)->where('activation_inventory', '<=', 30)
            ->first();

        if ($growing) {
            $accountPoints->recordInventory($growing, $grace, (int) ceil($growing->activation_inventory * 1.6), CarbonImmutable::now()->subDays(3), 'New wing configured and live, checked on the extranet');
        }

        $failing = PartnerAccount::query()->current()->whereHas('user', fn ($query) => $query->where('email', 'peter@tourlast.test'))
            ->where('activation_date', '>', now()->subDays(12))->first();

        if ($failing) {
            $accountPoints->failReview($failing, $grace, 'Partner requested closure within the review period');
        }

        $receipt = fn (string $name) => new UploadedFile(public_path('images/tourlast-mark.png'), $name, 'image/png', null, true);
        $john = User::where('email', 'john@tourlast.test')->first();
        $mary = User::where('email', 'mary@tourlast.test')->first();
        $peter = User::where('email', 'peter@tourlast.test')->first();
        $trip = fn (array $extra) => [
            'type' => 'transport_reimbursement', 'amount' => 850, 'description' => 'Site visit and partner training',
            'travel_date' => now()->subDays(2)->toDateString(), 'ride_provider' => 'bolt', 'trip_reference' => 'RB'.fake()->numerify('########'),
            'pickup' => 'Tourlast office, Westlands', 'dropoff' => 'Kilimani', 'distance_km' => 7.4, ...$extra,
        ];

        $claims->submit($john, $trip(['amount' => 640]), ['ride_details' => [$receipt('bolt-trip-receipt.png')]]);

        $atHr = $claims->submit($mary, $trip(['ride_provider' => 'uber', 'trip_reference' => 'UBR-'.fake()->numerify('#######'), 'amount' => 1250, 'dropoff' => 'Karen']), ['ride_details' => [$receipt('uber-receipt.png')]]);
        $claims->approve($atHr, $david, 'Visit logged on the lead');

        $request = $claims->submit($peter, [
            'type' => 'transport_request', 'amount' => 4500, 'description' => 'Two-day onboarding trip to Diani partners',
            'travel_date' => now()->addDays(3)->toDateString(), 'ride_provider' => 'matatu', 'pickup' => 'Mombasa CBD', 'dropoff' => 'Diani',
        ]);
        $claims->approve($request, $david);
        $claims->approve($request, $faith);
        $claims->approve($request, $samuel, 'Approved, disburse via M-Pesa');

        $airtime = $claims->submit($john, ['type' => 'airtime', 'amount' => 400, 'description' => 'Partner follow-up calls']);
        $claims->approve($airtime, $samuel);

        $rejected = $claims->submit($peter, $trip(['ride_provider' => 'taxi', 'trip_reference' => null, 'amount' => 3000]), ['receipt' => [$receipt('taxi-receipt.png')]]);
        $claims->reject($rejected, $david, 'Please use Bolt or Uber for trips inside Mombasa');

        $lastMonth = CarbonImmutable::now()->startOfMonth()->subMonth();

        foreach ([$john, $mary, $peter] as $person) {
            $old = $claims->submit($person, $trip(['travel_date' => $lastMonth->addDays(10)->toDateString(), 'amount' => fake()->numberBetween(500, 1500)]), ['ride_details' => [$receipt('bolt-trip.png')]]);
            $claims->approve($old, $david);
            $claims->approve($old, $faith);
            $claims->approve($old, $samuel);
        }

        foreach ($statements->generate($lastMonth) as $statement) {
            $statements->setCompliance($statement, array_fill_keys(array_keys(PayoutStatement::ComplianceItems), true));

            if ($statement->user->email !== 'james@tourlast.test') {
                $approved = $statements->approve($statement->fresh(), $samuel);
                $statements->markPaid($approved, $samuel, 'BANK-'.fake()->numerify('######'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(?string $refCode, string $status, CarbonImmutable $submittedAt, ?CarbonImmutable $approvedAt = null, ?CarbonImmutable $activeAt = null, array $overrides = []): void
    {
        $types = ['hotel', 'hotel', 'apartment', 'apartment', 'villa', 'guesthouse', 'resort', 'cottage', 'tour', 'experience'];
        $type = $overrides['property_type'] ?? fake()->randomElement($types);
        $inventory = ['hotel' => [12, 160], 'apartment' => [6, 60], 'villa' => [3, 12], 'guesthouse' => [5, 20], 'resort' => [40, 220], 'cottage' => [3, 15], 'tour' => [4, 40], 'experience' => [2, 20]][$type];
        $suffix = ['hotel' => 'Hotel', 'apartment' => 'Apartments', 'villa' => 'Villas', 'guesthouse' => 'Guest House', 'resort' => 'Beach Resort', 'cottage' => 'Cottages', 'tour' => 'Safaris', 'experience' => 'Experiences'][$type];

        SandboxProvider::create([
            'property_id' => 'TL-'.fake()->unique()->numerify('#####'),
            'ref_code' => $refCode,
            'property_name' => fake()->randomElement(['Acacia', 'Baobab', 'Coral', 'Savannah', 'Tsavo', 'Kilima', 'Jambo', 'Mara', 'Lamu', 'Malindi', 'Amani', 'Pwani', 'Nyota', 'Serena', 'Twiga', 'Simba'])
                .' '.fake()->randomElement(['Palms', 'Heights', 'Bay', 'Gardens', 'Ridge', 'View', 'Springs', 'Point']).' '.$suffix,
            'property_type' => $type,
            'location' => fake()->randomElement(['Nairobi', 'Mombasa', 'Diani', 'Naivasha', 'Nanyuki', 'Kisumu', 'Malindi', 'Watamu']).', Kenya',
            'contact_name' => fake()->name(),
            'contact_phone' => '+2547'.fake()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => $status,
            'submitted_at' => $submittedAt,
            'approved_at' => $approvedAt,
            'active_at' => $activeAt,
            'account_id' => 'H-'.fake()->unique()->numerify('#####'),
            'inventory_count' => fake()->numberBetween(...$inventory),
            ...$overrides,
        ]);
    }

    /**
     * A realistic reason for a lost demo lead.
     *
     * @return array<string, mixed>
     */
    private function lostOutcome(): array
    {
        [$objection, $competitor, $notes] = fake()->randomElement([
            [Objection::OtherOta, 'Booking.com', 'Signed an exclusive deal with Booking.com.'],
            [Objection::OtherOta, 'Expedia', 'Happy with their current Expedia volume.'],
            [Objection::Commission, null, 'Commission too high compared with direct bookings.'],
            [Objection::HasPms, null, 'Their PMS vendor has no Tourlast connection yet.'],
            [Objection::Timing, null, 'Renovating until the new year.'],
            [Objection::ManagementApproval, null, 'Owner has not approved new channels.'],
            [Objection::NoNeed, null, 'Fully booked by returning guests.'],
        ]);

        return [
            'objection' => $objection,
            'competitor' => $competitor,
            'lost_notes' => $notes,
            'lost_reason' => $objection->label().($competitor ? ' ('.$competitor.')' : ''),
            'lost_at' => now()->subDays(fake()->numberBetween(5, 200)),
            'reengage_on' => fake()->boolean(60) ? now()->addDays(fake()->numberBetween(10, 150))->toDateString() : null,
        ];
    }

    private function leadsFor(User $seller, int $activityLevel): void
    {
        $statuses = [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Contacted, LeadStatus::Meeting, LeadStatus::LinkSent, LeadStatus::LinkSent, LeadStatus::Lost];

        foreach ($statuses as $status) {
            $lead = Lead::factory()->for($seller)->create([
                'status' => $status,
                ...($status === LeadStatus::Lost ? $this->lostOutcome() : []),
            ]);

            if ($status === LeadStatus::New || $activityLevel === 0) {
                if ($activityLevel === 0 && $status !== LeadStatus::New) {
                    Activity::factory()->for($lead)->for($seller)->create(['type' => ActivityType::Call, 'happened_at' => now()->subDays(fake()->numberBetween(8, 14))]);
                }

                continue;
            }

            $touches = fake()->numberBetween(1, $activityLevel);
            $last = null;

            for ($i = $touches; $i >= 1; $i--) {
                $last = now()->subDays($i * fake()->numberBetween(1, 3))->setTime(fake()->numberBetween(9, 17), 0);
                Activity::factory()->for($lead)->for($seller)->create([
                    'type' => fake()->randomElement([ActivityType::Call, ActivityType::WhatsApp, ActivityType::Meeting, ActivityType::SiteVisit, ActivityType::Email]),
                    'happened_at' => $last,
                    'notes' => fake()->randomElement([
                        'Spoke to the GM about listing on tourlast.com. Interested, wants to see commission terms.',
                        'Sent the List Your Property link on WhatsApp.',
                        'Site visit. Showed how the host dashboard and calendar sync work.',
                        'Owner is comparing with another OTA. Follow up next week.',
                    ]),
                    'next_action' => fake()->randomElement([null, 'Send the link', 'Check signup progress', 'Call back about commission']),
                ]);
            }

            $lead->update(['last_contacted_at' => $last]);

            if ($status !== LeadStatus::Lost) {
                [$type, $task] = fake()->randomElement([
                    [ActivityType::Meeting, 'Proposal discussion'], [ActivityType::SiteVisit, 'Walk the property with the GM'],
                    [ActivityType::Call, 'Call the GM'], [ActivityType::FollowUp, 'Check signup progress'],
                    [ActivityType::WhatsApp, 'Send the List Your Property link'], [ActivityType::Demo, 'Show the extranet'],
                ]);
                $day = fake()->numberBetween(-3, 6);
                $timed = $type !== ActivityType::FollowUp;

                FollowUp::factory()->for($lead)->for($seller)->create([
                    'type' => $type,
                    'task' => $task,
                    'due_at' => $timed ? now()->addDays($day)->setTime(fake()->randomElement([9, 10, 11, 14, 15, 16]), fake()->randomElement([0, 30])) : now()->addDays($day)->startOfDay(),
                    'has_time' => $timed,
                    'duration_minutes' => in_array($type, [ActivityType::Meeting, ActivityType::SiteVisit, ActivityType::Demo], true) ? fake()->randomElement([30, 60, 90]) : null,
                    'contact_name' => $lead->contact_name,
                    'contact_role' => $lead->contact_role,
                    'location' => $type === ActivityType::SiteVisit ? $lead->location : null,
                ]);
            }
        }
    }
}
