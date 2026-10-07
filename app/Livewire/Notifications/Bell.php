<?php

namespace App\Livewire\Notifications;

use App\Support\Inbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Bell in the top bar: unread count and the latest announcements and alerts.
 */
class Bell extends Component
{
    /**
     * Fired from the browser when Reverb pushes a new alert or announcement.
     */
    #[On('inbox-updated')]
    public function refreshInbox(): void {}

    public function open(string $key): void
    {
        $url = Inbox::markRead(Auth::user(), $key);

        $this->redirect($url ?? route('notifications.index'), navigate: true);
    }

    public function markAllRead(): void
    {
        Inbox::markAllRead(Auth::user());
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.notifications.bell', [
            'pollSeconds' => Inbox::pollSeconds(),
            'unread' => Inbox::unreadCount($user),
            'items' => Inbox::feed($user, 'all', 8),
        ]);
    }
}
