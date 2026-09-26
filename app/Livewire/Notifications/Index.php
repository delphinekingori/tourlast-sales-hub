<?php

namespace App\Livewire\Notifications;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Support\Inbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Announcements and Smart Alerts. Admins, Sales Managers, HR and Finance can
 * write announcements; salespeople can only read them.
 */
#[Title('Notifications')]
class Index extends Component
{
    #[Url]
    public string $filter = 'all';

    public bool $showCompose = false;

    /** @var array{title: string, body: string, audience: list<string>, important: bool} */
    public array $compose = ['title' => '', 'body' => '', 'audience' => ['everyone'], 'important' => false];

    public function mount(): void
    {
        $this->filter = in_array($this->filter, ['all', 'announcements', 'alerts'], true) ? $this->filter : 'all';
    }

    public function open(string $key): void
    {
        $url = Inbox::markRead(Auth::user(), $key);

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Inbox::markAllRead(Auth::user());
        $this->dispatch('toast', message: 'Everything marked as read.');
    }

    public function openCompose(): void
    {
        abort_unless(Auth::user()->can(Permission::PublishAnnouncements->value), 403);
        $this->resetValidation();
        $this->compose = ['title' => '', 'body' => '', 'audience' => ['everyone'], 'important' => false];
        $this->showCompose = true;
    }

    public function publish(): void
    {
        abort_unless(Auth::user()->can(Permission::PublishAnnouncements->value), 403);

        $this->validate([
            'compose.title' => ['required', 'string', 'max:150'],
            'compose.body' => ['required', 'string', 'max:5000'],
            'compose.audience' => ['required', 'array', 'min:1'],
            'compose.audience.*' => [Rule::in(array_keys(Announcement::Audiences))],
            'compose.important' => ['boolean'],
        ], ['compose.audience.required' => 'Choose who should see it.'], [
            'compose.title' => 'title', 'compose.body' => 'message', 'compose.audience' => 'audience',
        ]);

        $audience = in_array('everyone', $this->compose['audience'], true) ? ['everyone'] : array_values($this->compose['audience']);

        Announcement::create([
            'user_id' => Auth::id(),
            'title' => $this->compose['title'],
            'body' => $this->compose['body'],
            'audience' => $audience,
            'importance' => $this->compose['important'] ? 'important' : 'normal',
        ]);

        $this->showCompose = false;
        $this->filter = 'announcements';
        $this->dispatch('toast', message: 'Announcement published.');
    }

    public function delete(int $announcementId): void
    {
        $announcement = Announcement::findOrFail($announcementId);
        abort_unless($announcement->user_id === Auth::id() || Auth::user()->can(Permission::ManageUsers->value), 403);

        $announcement->delete();
        $this->dispatch('toast', message: 'Announcement removed.');
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.notifications.index', [
            'items' => Inbox::feed($user, $this->filter, 60),
            'unread' => Inbox::unreadCount($user),
            'canPublish' => $user->can(Permission::PublishAnnouncements->value),
            'canModerate' => $user->can(Permission::ManageUsers->value),
            'audiences' => Announcement::Audiences,
        ]);
    }
}
