<?php

namespace Database\Factories;

use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Models\PropertyEngagement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyEngagement>
 */
class PropertyEngagementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $places = [
            ['Mombasa', 'Mombasa', 'Nyali'], ['Kwale', 'Diani', 'Galu'], ['Nairobi', 'Nairobi', 'Westlands'],
            ['Nakuru', 'Naivasha', 'Moi South Lake Road'], ['Narok', 'Maasai Mara', 'Talek'], ['Kilifi', 'Watamu', 'Turtle Bay'],
            ['Kisumu', 'Kisumu', 'Milimani'], ['Lamu', 'Lamu', 'Shela'],
        ];
        [$region, $city, $area] = fake()->randomElement($places);
        $first = fake()->dateTimeBetween('-10 months', '-1 week');
        $type = fake()->randomElement(['hotel', 'resort', 'lodge', 'apartment', 'villa', 'tour', 'restaurant', 'experience']);

        return [
            'name' => fake()->unique()->randomElement(['Kifaru', 'Duma', 'Tembo', 'Zawadi', 'Upendo', 'Bahari', 'Milele', 'Neema', 'Simba', 'Twiga', 'Pwani', 'Jambo', 'Coral', 'Baobab', 'Acacia', 'Savannah', 'Mara', 'Malaika', 'Kilima', 'Mawimbi', 'Nyota', 'Faraja', 'Tulia', 'Amani', 'Karibu', 'Sokoni', 'Sunset', 'Palm', 'Oceanic', 'Kijani'])
                .' '.fake()->randomElement(['Beach Resort', 'Hotel', 'Apartments', 'Safari Lodge', 'Villas', 'Guest House', 'Tours', 'Restaurant', 'Camp']),
            'property_type' => $type,
            'star_rating' => in_array($type, config('hub.accommodation_types'), true) ? fake()->optional(0.6)->randomElement(['2', '3', '4', '5']) : null,
            'country' => 'Kenya',
            'region' => $region,
            'city' => $city,
            'area' => $area,
            'sales_rep_id' => User::factory(),
            'stage' => EngagementStage::Contacted,
            'status' => EngagementStatus::Active,
            'source' => fake()->randomElement(EngagementSource::cases()),
            'summary' => fake()->optional()->sentence(12),
            'first_engaged_on' => $first,
            'last_engaged_on' => fake()->dateTimeBetween($first, 'now'),
        ];
    }

    public function stage(EngagementStage $stage, EngagementStatus $status = EngagementStatus::Active): static
    {
        return $this->state(['stage' => $stage, 'status' => $status]);
    }

    public function forRep(User $rep): static
    {
        return $this->state(['sales_rep_id' => $rep->id]);
    }

    /**
     * Give each record a primary contact and an opening rep period, as the
     * registry form does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PropertyEngagement $engagement): void {
            if ($engagement->contacts()->doesntExist()) {
                $engagement->contacts()->create([
                    'name' => fake()->name(),
                    'title' => fake()->randomElement(config('hub.contact_titles')),
                    'phone' => '+2547'.fake()->numerify('########'),
                    'email' => fake()->unique()->safeEmail(),
                    'is_primary' => true,
                    'is_decision_maker' => fake()->boolean(),
                ]);
            }

            if ($engagement->sales_rep_id && $engagement->reps()->doesntExist()) {
                $engagement->reps()->create(['user_id' => $engagement->sales_rep_id, 'started_on' => $engagement->first_engaged_on]);
            }
        });
    }
}
