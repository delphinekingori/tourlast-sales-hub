<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcceptInvitation
{
    public function __construct(private IssueReferralCode $issueReferralCode) {}

    /**
     * Create the invited person's account with the role they were invited to.
     */
    public function handle(Invitation $invitation, string $password, ?string $phone = null): User
    {
        if (! $invitation->isUsable()) {
            throw new InvalidArgumentException('This invitation can no longer be accepted.');
        }

        return DB::transaction(function () use ($invitation, $password, $phone): User {
            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'phone' => $phone ?: $invitation->phone,
                'region' => $invitation->region,
                'password' => $password,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($invitation->role->value);

            $invitation->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ]);

            $this->issueReferralCode->handle($user);

            return $user;
        });
    }
}
