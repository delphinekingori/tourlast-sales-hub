<?php

namespace App\Livewire\Activities;

use App\Models\Activity;
use App\Models\FollowUp;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Activities & Follow-ups')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()->role()?->earnsReferrals(), 403);
    }

    public function complete(int $followUpId): void
    {
        FollowUp::query()->where('user_id', Auth::id())->whereKey($followUpId)->update(['completed_at' => now()]);
        $this->dispatch('toast', message: 'Follow-up done.');
    }

    #[On('schedule-saved')]
    public function refreshSchedule(): void
    {
        // Re-render with the updated schedule.
    }

    public function render(): View
    {
        $open = FollowUp::query()->open()->where('user_id', Auth::id())->with('lead')->chronological()->get();

        return view('livewire.activities.index', [
            'overdue' => $open->filter(fn (FollowUp $item): bool => $item->due_at->lt(now()->startOfDay())),
            'today' => $open->filter(fn (FollowUp $item): bool => $item->due_at->isToday()),
            'upcoming' => $open->filter(fn (FollowUp $item): bool => $item->due_at->gt(now()->endOfDay()))->take(15),
            'recent' => Activity::query()->where('user_id', Auth::id())->with('lead')->latest('happened_at')->limit(20)->get(),
            'weekCount' => Activity::query()->where('user_id', Auth::id())->where('happened_at', '>=', now()->startOfWeek())->count(),
        ]);
    }
}
