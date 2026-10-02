<?php

namespace App\Events;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells everyone the announcement is addressed to (except its author) that a
 * new one is waiting, so their bell updates without a page refresh.
 */
class AnnouncementPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Announcement $announcement) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return User::query()->active()->whereKeyNot($this->announcement->user_id)->get()
            ->filter(fn (User $user): bool => $this->announcement->isFor($user))
            ->map(fn (User $user): Channel => new PrivateChannel('App.Models.User.'.$user->id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'announcement.published';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'title' => $this->announcement->title,
            'importance' => $this->announcement->importance,
        ];
    }
}
