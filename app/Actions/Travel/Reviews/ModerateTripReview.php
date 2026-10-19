<?php

namespace App\Actions\Travel\Reviews;

use App\Models\TripReview;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Travel managers hide a review (with a reason) or restore it. Hidden reviews
 * stay on file but stop counting towards provider and package ratings.
 */
class ModerateTripReview
{
    public static function canModerate(User $actor): bool
    {
        return TravelAccess::works($actor) && TravelAccess::managesAll($actor);
    }

    public function hide(User $actor, TripReview $review, string $reason): TripReview
    {
        abort_unless(self::canModerate($actor), 403);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this review is being hidden.']);
        }

        if (mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason under 255 characters.']);
        }

        $review->forceFill(['is_hidden' => true, 'hidden_reason' => $reason, 'moderated_by' => $actor->id, 'moderated_at' => now()])->save();
        Audit::record($review->booking, 'review.hidden', "Review hidden: {$reason}", ['is_hidden' => [false, true]], $actor->id);

        return $review;
    }

    public function unhide(User $actor, TripReview $review): TripReview
    {
        abort_unless(self::canModerate($actor), 403);

        $review->forceFill(['is_hidden' => false, 'hidden_reason' => null, 'moderated_by' => $actor->id, 'moderated_at' => now()])->save();
        Audit::record($review->booking, 'review.unhidden', 'Review restored', ['is_hidden' => [true, false]], $actor->id);

        return $review;
    }
}
