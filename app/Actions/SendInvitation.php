<?php

namespace App\Actions;

use App\Enums\Role;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendInvitation
{
    /**
     * Create an invitation and email the single-use link.
     *
     * Any earlier pending invitation for the same email is revoked so only the
     * newest link works.
     *
     * @param  array{name: string, email: string, phone?: ?string, role: Role, region?: ?string}  $details
     */
    public function handle(User $inviter, array $details): Invitation
    {
        $plainToken = Str::random(64);
        $email = Str::lower(trim($details['email']));

        $invitation = DB::transaction(function () use ($inviter, $details, $email, $plainToken): Invitation {
            Invitation::query()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            return Invitation::create([
                'name' => trim($details['name']),
                'email' => $email,
                'phone' => $details['phone'] ?? null,
                'role' => $details['role'],
                'region' => $details['region'] ?? null,
                'token_hash' => Invitation::hashToken($plainToken),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(config('hub.invitation_expiry_days')),
                'last_sent_at' => now(),
            ]);
        });

        Mail::to($invitation->email)->queue(new InvitationMail($invitation, $plainToken));

        return $invitation;
    }

    /**
     * Send a fresh link for an existing invitation. The old link stops working.
     */
    public function resend(Invitation $invitation): Invitation
    {
        $plainToken = Str::random(64);

        $invitation->update([
            'token_hash' => Invitation::hashToken($plainToken),
            'expires_at' => now()->addDays(config('hub.invitation_expiry_days')),
            'revoked_at' => null,
            'last_sent_at' => now(),
        ]);

        Mail::to($invitation->email)->queue(new InvitationMail($invitation, $plainToken));

        return $invitation;
    }
}
