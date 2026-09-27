<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CompleteScheduleItem;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Http\Resources\V1\ScheduleItemResource;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Calls, meetings, site visits and follow-ups. Mirrors Calendar\Index (who
 * sees what) and Schedule\Editor (only the owner changes an item).
 */
class ScheduleController extends ApiController
{
    /**
     * GET /schedule?from=&to=&user= — salespeople see their own; managers see
     * the team ("team"), one person (id) or, if they sell, themselves by default.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        $sells = (bool) $viewer->role()?->earnsReferrals();
        $seesTeam = $viewer->can(Permission::ViewTeamPerformance->value);
        abort_unless($sells || $seesTeam, 403, 'Your account is not allowed to do this.');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user' => ['nullable', 'string', 'max:20'],
            'include_completed' => ['nullable', 'boolean'],
        ]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'))->startOfDay() : CarbonImmutable::now()->startOfWeek();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'))->endOfDay() : CarbonImmutable::now()->endOfWeek();
        abort_if($from->diffInDays($to) > 93, 422, 'The date range can be at most 93 days.');

        $rep = (string) $request->query('user', '');
        $userId = match (true) {
            ! $seesTeam => $viewer->id,
            $rep === 'team' => null,
            $rep === '' => $sells ? $viewer->id : null,
            default => (int) $rep,
        };

        $items = FollowUp::query()
            ->with(['lead:id,business_name,location,property_engagement_id', 'user:id,name,avatar_path'])
            ->whereBetween('due_at', [$from, $to])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when(! $userId, fn ($query) => $query->whereIn('user_id', User::query()->sellers()->select('id')))
            ->when($request->has('include_completed') && ! $request->boolean('include_completed'), fn ($query) => $query->whereNull('completed_at'))
            ->chronological()
            ->paginate($this->perPage($request, 100));

        return ScheduleItemResource::collection($items)->additional(['meta' => [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'user' => $userId ?? 'team',
        ]]);
    }

    /**
     * POST /schedule — sellers only, on their own leads.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $user = $this->user($request);
        $data = $request->validate($this->rules($user, true), [], $this->attributes());

        $item = FollowUp::create($this->attributesFrom($data) + ['user_id' => $user->id]);

        return (new ScheduleItemResource($item->load(['lead', 'user'])))->response()->setStatusCode(201);
    }

    /**
     * PATCH /schedule/{id} — owner only.
     */
    public function update(Request $request, FollowUp $item): ScheduleItemResource
    {
        $this->ownedItem($request, $item);
        $data = $request->validate($this->rules($this->user($request), false), [], $this->attributes());

        // Fields sent replace the current values; fields left out are kept.
        $item->update($this->attributesFrom($data + [
            'lead_id' => $item->lead_id,
            'type' => $item->type->value,
            'title' => $item->task,
            'date' => $item->due_at->toDateString(),
            'time' => $item->has_time ? $item->due_at->format('H:i') : null,
            'duration_minutes' => $item->duration_minutes,
            'contact_name' => $item->contact_name,
            'contact_role' => $item->contact_role,
            'location' => $item->location,
            'notes' => $item->notes,
        ]));

        return new ScheduleItemResource($item->fresh(['lead', 'user']));
    }

    /**
     * POST /schedule/{id}/complete — record the outcome (logged on the lead) and
     * optionally book the next step.
     */
    public function complete(Request $request, FollowUp $item, CompleteScheduleItem $completeScheduleItem): ScheduleItemResource
    {
        $this->ownedItem($request, $item);
        abort_if($item->isDone(), 422, 'This item is already done.');

        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'max:190'],
            'next_type' => ['nullable', Rule::enum(ActivityType::class)],
            'next_date' => ['nullable', 'date', 'after_or_equal:today'],
            'next_time' => ['nullable', 'date_format:H:i'],
        ], [], ['next_date' => 'follow-up date', 'next_time' => 'follow-up time']);

        $nextAt = filled($data['next_date'] ?? null)
            ? CarbonImmutable::parse(CarbonImmutable::parse($data['next_date'])->toDateString().(filled($data['next_time'] ?? null) ? ' '.$data['next_time'] : ''))
            : null;

        $completeScheduleItem->handle(
            $item,
            $this->user($request),
            ($data['outcome'] ?? null) ?: null,
            ($data['next_action'] ?? null) ?: null,
            $nextAt,
            $nextAt !== null && filled($data['next_time'] ?? null),
            ActivityType::from($data['next_type'] ?? ActivityType::FollowUp->value),
        );

        return new ScheduleItemResource($item->fresh(['lead', 'user']));
    }

    /**
     * DELETE /schedule/{id} — owner only, open items only.
     */
    public function destroy(Request $request, FollowUp $item): JsonResponse
    {
        $this->ownedItem($request, $item);
        abort_if($item->isDone(), 422, 'Completed items stay in the history and cannot be removed.');
        $item->delete();

        return response()->json(['message' => 'Removed from the schedule.']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(User $user, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'lead_id' => [$required, Rule::exists('leads', 'id')->where('user_id', $user->id)],
            'type' => [$required, Rule::enum(ActivityType::class)],
            'title' => [$required, 'string', 'max:190'],
            'date' => $creating ? ['required', 'date', 'after_or_equal:today'] : ['sometimes', 'date'],
            'time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['lead_id' => 'property / lead', 'title' => 'title', 'duration_minutes' => 'duration'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        $hasTime = filled($data['time'] ?? null);
        $date = CarbonImmutable::parse($data['date'])->toDateString();

        return [
            'lead_id' => (int) $data['lead_id'],
            'type' => ActivityType::from($data['type']),
            'task' => trim($data['title']),
            'due_at' => $hasTime ? CarbonImmutable::parse($date.' '.$data['time']) : CarbonImmutable::parse($date)->startOfDay(),
            'has_time' => $hasTime,
            'duration_minutes' => $hasTime && filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null,
            'contact_name' => ($data['contact_name'] ?? null) ?: null,
            'contact_role' => ($data['contact_role'] ?? null) ?: null,
            'location' => ($data['location'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
        ];
    }

    private function ownedItem(Request $request, FollowUp $item): void
    {
        abort_unless($item->user_id === $this->user($request)->id, 403, 'Only the person whose schedule this is can change it.');
    }
}
