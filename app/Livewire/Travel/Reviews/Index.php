<?php

namespace App\Livewire\Travel\Reviews;

use App\Actions\Travel\Reviews\ModerateTripReview;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Models\Package;
use App\Models\TravelProvider;
use App\Models\TripReview;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TripReviewFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer reviews after a trip: filter by provider, package, rating and
 * period, spot low ratings, download Excel; Travel managers hide or restore.
 */
#[Title('Trip reviews')]
class Index extends Component
{
    use CapturesActionErrors;
    use WithPagination;

    #[Url]
    public string $provider = '';

    #[Url]
    public string $package = '';

    #[Url]
    public string $rating = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $showHide = false;

    #[Locked]
    public ?int $hidingReviewId = null;

    public string $reason = '';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['provider', 'package', 'rating', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['provider', 'package', 'rating', 'from', 'to']);
        $this->resetPage();
    }

    public function openHide(int $reviewId): void
    {
        abort_unless(ModerateTripReview::canModerate(Auth::user()), 403);
        $this->resetErrorBag();
        $this->hidingReviewId = TripReview::query()->findOrFail($reviewId)->id;
        $this->reason = '';
        $this->showHide = true;
    }

    public function hide(ModerateTripReview $moderate): void
    {
        $this->resetErrorBag();
        $review = TripReview::query()->findOrFail($this->hidingReviewId);

        if ($this->attempt(fn () => $moderate->hide(Auth::user(), $review, $this->reason), '')) {
            $this->showHide = false;
            $this->dispatch('toast', message: 'Review hidden. It no longer counts towards ratings.');
        }
    }

    public function unhide(int $reviewId, ModerateTripReview $moderate): void
    {
        $moderate->unhide(Auth::user(), TripReview::query()->findOrFail($reviewId));
        $this->dispatch('toast', message: 'Review restored.');
    }

    private function filters(): TripReviewFilters
    {
        return TripReviewFilters::fromArray(['provider' => $this->provider, 'package' => $this->package, 'rating' => $this->rating, 'from' => $this->from, 'to' => $this->to]);
    }

    public function render(): View
    {
        $viewer = Auth::user();
        $filters = $this->filters();
        $visible = TripReview::query()->visible();

        return view('livewire.travel.reviews.index', [
            'reviews' => $filters->query($viewer)->with(['provider:id,name', 'package:id,name', 'booking:id,reference', 'client:id,name', 'recorder:id,name'])->paginate(25),
            'summary' => [
                'count' => (clone $visible)->count(),
                'average' => (clone $visible)->avg('rating'),
                'low' => (clone $visible)->where('rating', '<=', TripReview::LowRating)->count(),
                'month' => (clone $visible)->where('recorded_at', '>=', now()->startOfMonth())->count(),
            ],
            'providers' => TravelProvider::query()->orderBy('name')->get(['id', 'name']),
            'packages' => Package::query()->when($this->provider !== '', fn (Builder $query) => $query->where('travel_provider_id', (int) $this->provider))->orderBy('name')->get(['id', 'name']),
            'canModerate' => ModerateTripReview::canModerate($viewer),
            'hasFilters' => $filters->toQuery() !== [],
            'exportQuery' => $filters->toQuery(),
        ]);
    }
}
