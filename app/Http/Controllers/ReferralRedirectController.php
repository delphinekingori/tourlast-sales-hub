<?php

namespace App\Http\Controllers;

use App\Enums\ReferralTarget;
use App\Models\Lead;
use App\Models\ReferralCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralRedirectController extends Controller
{
    /**
     * Count the click on a salesperson's tracked link, then forward the
     * provider to tourlast.com with the code attached.
     *
     * The optional target picks the product (Stays by default). Unknown codes
     * still land on that product's registration page, just without a referral.
     * An optional ?l=<lead id> (added when a link is sent from a lead) records
     * which prospect clicked, but only if that lead belongs to the code's owner.
     */
    public function __invoke(Request $request, string $code, ?string $target = null): RedirectResponse
    {
        $target = ReferralTarget::tryFrom((string) $target) ?? ReferralTarget::Stays;
        $referralCode = ReferralCode::query()->where('code', strtoupper($code))->first();

        if (! $referralCode) {
            return redirect()->away($target->registrationUrl());
        }

        $leadId = $request->integer('l') ?: null;

        if ($leadId && ! Lead::query()->whereKey($leadId)->where('user_id', $referralCode->user_id)->exists()) {
            $leadId = null;
        }

        if (! $this->looksLikeBot((string) $request->userAgent())) {
            $referralCode->clicks()->create([
                'lead_id' => $leadId,
                'target' => $target,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'clicked_at' => now(),
            ]);
        }

        return redirect()->away($referralCode->destinationUrl($target));
    }

    /**
     * Link previews (WhatsApp, email scanners) fetch the link without a person clicking.
     */
    private function looksLikeBot(string $userAgent): bool
    {
        return $userAgent === '' || (bool) preg_match('/bot|crawl|spider|preview|whatsapp|facebookexternalhit|slack|telegram|skype|curl|wget/i', $userAgent);
    }
}
