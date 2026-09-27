<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Support\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart Alerts and announcements (the bell and the Notifications page).
 */
class NotificationController extends ApiController
{
    /**
     * GET /notifications?filter=all|alerts|announcements&limit=50
     */
    public function index(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), ['alerts', 'announcements'], true) ? (string) $request->query('filter') : 'all';
        $user = $this->user($request);

        return response()->json([
            'data' => Inbox::feed($user, $filter, max(1, min(100, $request->integer('limit', 50))))->map(fn (array $item) => [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'type' => $item['type'],
                'title' => $item['title'],
                'body' => $item['body'],
                'url' => $item['url'],
                'unread' => $item['unread'],
                'importance' => $item['importance'],
                'audience' => $item['audience'],
                'author' => $item['author'] ? ['id' => $item['author']->id, 'name' => $item['author']->name] : null,
                'created_at' => $item['at']->toIso8601String(),
            ])->values(),
            'meta' => ['unread' => Inbox::unreadCount($user)],
        ]);
    }

    /**
     * POST /notifications/{key}/read — key as returned by the list (a-12 or n-uuid).
     */
    public function markRead(Request $request, string $key): JsonResponse
    {
        abort_unless(preg_match('/^(a-\d+|n-[0-9a-f-]{36})$/', $key) === 1, 404);
        Inbox::markRead($this->user($request), $key);

        return response()->json(['message' => 'Marked as read.', 'unread' => Inbox::unreadCount($this->user($request))]);
    }

    /**
     * POST /notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        Inbox::markAllRead($this->user($request));

        return response()->json(['message' => 'All marked as read.', 'unread' => 0]);
    }

    /**
     * POST /announcements — admins, Sales Managers, HR and Finance.
     */
    public function publish(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::PublishAnnouncements);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'array', 'min:1'],
            'audience.*' => [Rule::in(array_keys(Announcement::Audiences))],
            'important' => ['sometimes', 'boolean'],
        ]);

        $announcement = Announcement::create([
            'user_id' => $this->user($request)->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => in_array('everyone', $data['audience'], true) ? ['everyone'] : array_values($data['audience']),
            'importance' => ($data['important'] ?? false) ? 'important' : 'normal',
        ]);

        return response()->json(['data' => [
            'key' => 'a-'.$announcement->id,
            'id' => $announcement->id,
            'title' => $announcement->title,
            'audience' => $announcement->audienceLabel(),
            'importance' => $announcement->importance,
            'created_at' => $announcement->created_at->toIso8601String(),
        ]], 201);
    }
}
