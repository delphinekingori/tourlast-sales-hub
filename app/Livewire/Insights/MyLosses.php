<?php

namespace App\Livewire\Insights;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;

/**
 * A salesperson's own losses: why their properties said no, who they chose
 * instead, and which lost properties are due to be approached again.
 */
#[Title('My losses')]
class MyLosses extends Objections
{
    public function mount(): void
    {
        abort_unless(Auth::user()->role()?->earnsReferrals(), 403);

        if (! array_key_exists($this->period, self::Periods)) {
            $this->period = 'year';
        }
    }

    protected function scopeUserId(): ?int
    {
        return Auth::id();
    }

    protected function viewName(): string
    {
        return 'livewire.insights.my-losses';
    }
}
