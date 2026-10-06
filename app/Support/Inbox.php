<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use App\Notifications\SmartAlert;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * One feed of announcements and Smart Alerts for a user.
 */
class Inbox
{
    /**
     * Seconds between background refreshes. Slower when Reverb pushes updates
     * live, since polling is then only a safety net.
     */
    public static function pollSeconds(): int
    {
        return config('broadcasting.default') === 'reverb' ? 120 : 30;
    }

    public static function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count()
            + Announcement::query()->visibleTo($user)->unreadBy($user)->count();
    }

    /**
     * @return Collection<int, array{key: string, kind: string, type: string, title: string, body: string, url: ?string, unread: bool, at: CarbonInterface, author: ?User, importance: string, audience: ?string, id: string|int}>
     */
    public static function feed(User $user, string $filter = 'all', int $limit = 50): Collection
    {
        $items = collect();

        if ($filter !== 'alerts') {
            $readIds = $user->id ? AnnouncementRead::query()->where('user_id', $user->id)->pluck('announcement_id')->all() : [];

            Announcement::query()->visibleTo($user)->with('author')->latest()->limit($limit)->get()
                ->each(function (Announcement $announcement) use ($items, $user, $readIds): void {
                    $items->push([
                        'key' => 'a-'.$announcement->id,
                        'id' => $announcement->id,
                        'kind' => 'announcement',
                        'type' => 'announcement',
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                        'url' => null,
                        'unread' => $announcement->user_id !== $user->id && ! in_array($announcement->id, $readIds, true),
                        'at' => $announcement->created_at,
                        'author' => $announcement->author,
                        'importance' => $announcement->importance,
                        'audience' => $announcement->audienceLabel(),
                    ]);
                });
        }

        if ($filter !== 'announcements') {
            $user->notifications()->latest()->limit($limit)->get()
                ->each(function (DatabaseNotification $notification) use ($items): void {
                    $items->push([
                        'key' => 'n-'.$notification->id,
                        'id' => $notification->id,
                        'kind' => 'alert',
                        'type' => $notification->data['type'] ?? 'alert',
                        'title' => $notification->data['title'] ?? (SmartAlert::Types[$notification->data['type'] ?? '']['label'] ?? 'Alert'),
                        'body' => $notification->data['body'] ?? '',
                        'url' => $notification->data['url'] ?? null,
                        'unread' => $notification->read_at === null,
                        'at' => $notification->created_at,
                        'author' => null,
                        'importance' => 'normal',
                        'audience' => null,
                    ]);
                });
        }

        return $items->sortByDesc(fn (array $item) => $item['at']->getTimestamp())->take($limit)->values();
    }

    public static function markRead(User $user, string $key): ?string
    {
        if (str_starts_with($key, 'a-')) {
            $announcementId = Announcement::query()->visibleTo($user)->whereKey((int) substr($key, 2))->value('id');

            if ($announcementId) {
                AnnouncementRead::query()->firstOrCreate(
                    ['announcement_id' => $announcementId, 'user_id' => $user->id],
                    ['read_at' => now()],
                );
            }

            return null;
        }

        $notification = $user->notifications()->whereKey(substr($key, 2))->first();
        $notification?->markAsRead();

        return $notification?->data['url'] ?? null;
    }

    public static function markAllRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();

        Announcement::query()->visibleTo($user)->unreadBy($user)->pluck('id')
            ->each(fn (int $id) => AnnouncementRead::query()->firstOrCreate(['announcement_id' => $id, 'user_id' => $user->id], ['read_at' => now()]));
    }
}
