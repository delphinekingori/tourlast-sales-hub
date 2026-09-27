<x-mail::message>
# Hello {{ \Illuminate\Support\Str::before($invitation->name, ' ') }},

{{ $inviterName }} has invited you to join **Tourlast Sales Hub** as a **{{ $roleLabel }}**.

Tourlast Sales Hub is where the sales team tracks prospects, referral links and the partners each salesperson has onboarded.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This link works once and expires on **{{ $expiresOn }}**. If it has expired, ask {{ $inviterName }} to send a new one.

If you weren't expecting this invitation, you can ignore this email.

Tourlast Sales
</x-mail::message>
