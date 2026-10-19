{{-- Average rating with the number of visible reviews. Needs $average (?float) and $count. --}}
@if ($count > 0)
    <span class="tabular inline-flex items-center gap-1 whitespace-nowrap font-semibold {{ $average <= \App\Models\TripReview::LowRating ? 'text-danger' : 'text-ink' }}"><span class="text-warning" aria-hidden="true">★</span>{{ number_format($average, 1) }}<span class="font-normal text-ink-subtle">({{ $count }} {{ $count === 1 ? 'review' : 'reviews' }})</span></span>
@else
    <span class="text-ink-subtle">No reviews yet</span>
@endif
