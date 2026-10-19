<?php

namespace App\Support\Travel;

use App\Models\TripReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for the reviews list, shared by the page, the Excel export and the
 * API so all show exactly the same rows. Hidden reviews are only listed for
 * Travel managers.
 */
class TripReviewFilters
{
    public function __construct(
        public string $provider = '',
        public string $package = '',
        public string $rating = '',
        public string $from = '',
        public string $to = '',
    ) {}

    /**
     * @param  array<string, mixed>  $input  provider(_id), package(_id), rating (1-5 or "low"), from, to.
     */
    public static function fromArray(array $input): self
    {
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input[$key] ?? '')) ? (string) $input[$key] : '';
        $id = fn (string ...$keys): string => collect($keys)->map(fn (string $key): string => (string) ($input[$key] ?? ''))->first(fn (string $value): bool => ctype_digit($value)) ?? '';
        $rating = (string) ($input['rating'] ?? '');

        return new self(
            provider: $id('provider', 'provider_id'),
            package: $id('package', 'package_id'),
            rating: in_array($rating, ['1', '2', '3', '4', '5', 'low'], true) ? $rating : '',
            from: $date('from'),
            to: $date('to'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'package' => $this->package,
            'rating' => $this->rating,
            'from' => $this->from,
            'to' => $this->to,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * @return Builder<TripReview>
     */
    public function query(User $viewer): Builder
    {
        return TripReview::query()
            ->when(! TravelAccess::managesAll($viewer), fn (Builder $query) => $query->visible())
            ->when($this->provider !== '', fn (Builder $query) => $query->where('travel_provider_id', (int) $this->provider))
            ->when($this->package !== '', fn (Builder $query) => $query->where('package_id', (int) $this->package))
            ->when($this->rating === 'low', fn (Builder $query) => $query->where('rating', '<=', TripReview::LowRating))
            ->when(ctype_digit($this->rating), fn (Builder $query) => $query->where('rating', (int) $this->rating))
            ->when($this->from !== '', fn (Builder $query) => $query->whereDate('recorded_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $query) => $query->whereDate('recorded_at', '<=', $this->to))
            ->latest('recorded_at')
            ->latest('id');
    }
}
