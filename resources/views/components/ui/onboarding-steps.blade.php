@props(['onboarding', 'compact' => false])

@php
    use App\Enums\OnboardingStatus;

    $status = $onboarding->status;
    $rejected = $status === OnboardingStatus::Rejected;
    $live = $status === OnboardingStatus::Active;

    // How many steps are complete; the next one is the current step.
    $completed = match ($status) {
        OnboardingStatus::Submitted, OnboardingStatus::UnderReview => 2,
        OnboardingStatus::Approved => 4,
        OnboardingStatus::Active => 5,
        OnboardingStatus::Rejected => 3,
    };
    $steps = [
        ['Referral', $onboarding->ref_code],
        ['Application', $onboarding->submitted_at?->format('j M')],
        ['Verification', $status === OnboardingStatus::UnderReview ? 'In review' : null],
        [$rejected ? 'Rejected' : 'Approval', ($rejected ? $onboarding->rejected_at : $onboarding->approved_at)?->format('j M')],
        ['Live', $onboarding->active_at?->format('j M')],
    ];
@endphp

{{-- Referral → Application → Verification → Approval → Live, driven by the synced status. --}}
@if ($compact)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-1']) }} aria-label="Onboarding {{ min($completed + 1, 5) }} of 5: {{ $status->label() }}">
        @foreach ($steps as $i => $step)
            <span @class([
                'h-1.5 w-4 rounded-full',
                'bg-success' => $live,
                'bg-brand' => ! $live && $i < $completed,
                'bg-danger' => $rejected && $i === $completed,
                'bg-surface-muted ring-1 ring-line ring-inset' => ! $live && $i >= $completed && ! ($rejected && $i === $completed),
            ])></span>
        @endforeach
    </div>
@else
    <ol {{ $attributes->merge(['class' => 'grid grid-cols-5']) }} aria-label="Onboarding progress">
        @foreach ($steps as $i => [$label, $meta])
            @php
                $done = $i < $completed;
                $current = $i === $completed;
                $failed = $rejected && $current;
            @endphp
            <li class="relative grid justify-items-center gap-1.5 text-center">
                @unless ($loop->first)
                    <span @class(['absolute top-3 right-1/2 left-[-50%] h-0.5', 'bg-success' => $live, 'bg-brand' => ! $live && $done, 'bg-danger/50' => $failed, 'bg-line' => ! $done && ! $failed])></span>
                @endunless
                <span @class([
                    'relative z-10 grid size-6 place-items-center rounded-full text-[11px] font-bold',
                    'bg-success text-white' => $done && $live,
                    'bg-brand text-white' => $done && ! $live,
                    'bg-danger text-white' => $failed,
                    'bg-surface text-brand-text ring-2 ring-brand' => $current && ! $failed,
                    'bg-surface text-ink-subtle ring-1 ring-line-strong' => ! $done && ! $current,
                ])>
                    @if ($done)
                        <x-ui.icon name="check" class="size-3.5" />
                    @elseif ($failed)
                        ×
                    @else
                        {{ $i + 1 }}
                    @endif
                </span>
                <span class="grid min-w-0 leading-tight">
                    <span @class(['text-xs font-medium', 'text-danger' => $failed, 'text-ink' => ($done || $current) && ! $failed, 'text-ink-subtle' => ! $done && ! $current])>{{ $label }}</span>
                    @if ($meta && ($done || $current))
                        <span class="truncate text-[11px] text-ink-subtle">{{ $meta }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
@endif
