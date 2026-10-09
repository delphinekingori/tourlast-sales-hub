<?php

namespace App\Actions\Travel\Influencers;

use App\Enums\Travel\InfluencerCodeStatus;
use App\Models\InfluencerCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\InfluencerAccess;
use Illuminate\Validation\ValidationException;

/**
 * Pause, resume or end a code. Ending is final; a paused code earns nothing
 * on bookings made while it is paused (bookings resolve the code at the time
 * they are made).
 */
class ChangeInfluencerCodeStatus
{
    public function handle(User $actor, InfluencerCode $code, InfluencerCodeStatus $status): InfluencerCode
    {
        abort_unless(InfluencerAccess::canManage($actor, $code->influencer), 403);

        if ($code->status === $status) {
            return $code;
        }

        if ($code->status === InfluencerCodeStatus::Ended) {
            throw ValidationException::withMessages(['status' => 'This code has ended and cannot be used again. Create a new code instead.']);
        }

        $from = $code->status;
        $code->forceFill([
            'status' => $status,
            'ends_on' => $status === InfluencerCodeStatus::Ended && ($code->ends_on === null || $code->ends_on->gt(today()))
                ? today()
                : $code->ends_on,
        ])->save();

        $verb = match ($status) {
            InfluencerCodeStatus::Active => 'Resumed',
            InfluencerCodeStatus::Paused => 'Paused',
            InfluencerCodeStatus::Ended => 'Ended',
        };

        Audit::record($code, 'influencer.code_status', "{$verb} code {$code->code}", ['status' => [$from->value, $status->value]]);

        return $code;
    }
}
