<x-mail::message>
# Good morning, {{ $manager->firstName() }}

The team has onboarded **{{ $alert['teamOnboarded'] }}** {{ \Illuminate\Support\Str::plural('partner', $alert['teamOnboarded']) }} so far in {{ now()->format('F') }}. Here's what needs your attention today.

@if ($alert['inactive'])
## No recent activity
@foreach ($alert['inactive'] as $row)
- **{{ $row['name'] }}**: {{ strtolower($row['label']) }}
@endforeach
@endif

@if ($alert['behind'])
## Behind their own target
@foreach ($alert['behind'] as $row)
- **{{ $row['name'] }}**: {{ $row['onboarded'] }} of {{ $row['target'] }}
@endforeach
@endif

@if ($alert['noTarget'])
## No target set for {{ now()->format('F') }}
{{ implode(', ', $alert['noTarget']) }}
@endif

@if ($alert['stalled'])
## Signups waiting over {{ config('hub.stalled_after_days') }} days for approval
@foreach ($alert['stalled'] as $row)
- {{ $row['property'] }} ({{ $row['salesperson'] }}, {{ $row['days'] }} days)
@endforeach
@endif

@if ($alert['nearRetainer'])
## Close to a retainer threshold
@foreach ($alert['nearRetainer'] as $row)
- **{{ $row['name'] }}**: {{ $row['points'] }} points, {{ $row['next'] - $row['points'] }} short of {{ $row['next'] }}
@endforeach
@endif

@if ($alert['reviewWarnings'])
## Accounts at risk in the 14-day review
{{ implode(', ', $alert['reviewWarnings']) }}
@endif

@if ($alert['verificationBacklog'])
**{{ $alert['verificationBacklog'] }}** live {{ \Illuminate\Support\Str::plural('Account', $alert['verificationBacklog']) }} waiting for verification, so {{ $alert['verificationBacklog'] === 1 ? 'its' : 'their' }} points are still provisional.
@endif

@if ($alert['unattributed'])
**{{ $alert['unattributed'] }}** {{ \Illuminate\Support\Str::plural('signup', $alert['unattributed']) }} arrived without a referral code and {{ $alert['unattributed'] === 1 ? 'needs' : 'need' }} assigning.
@endif

<x-mail::button :url="$performanceUrl">
Open Team Performance
</x-mail::button>

Tourlast Sales Hub
</x-mail::message>
