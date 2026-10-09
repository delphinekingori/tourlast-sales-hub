<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\LogActivity;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Http\Resources\V1\ActivityResource;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The activity history of a lead, and logging new touchpoints.
 */
class LeadActivityController extends ApiController
{
    /**
     * GET /leads/{id}/activities — newest first; owner or managers.
     */
    public function index(Request $request, Lead $lead): AnonymousResourceCollection
    {
        $user = $this->user($request);
        abort_unless($lead->user_id === $user->id || $user->can(Permission::ViewTeamPerformance->value), 403, 'This lead belongs to someone else.');

        return ActivityResource::collection($lead->activities()->with('user')->paginate($this->perPage($request)));
    }

    /**
     * POST /leads/{id}/activities — owner only. Moves the lead forward like the
     * web app does and can book the next follow-up.
     */
    public function store(Request $request, Lead $lead, LogActivity $logActivity): JsonResponse
    {
        abort_unless($lead->user_id === $this->user($request)->id, 403, 'Only the lead\'s owner can log activity.');

        $data = $request->validate([
            'type' => ['required', Rule::enum(ActivityType::class)->only(ActivityType::forProperty())],
            'happened_at' => ['required', 'date', 'before_or_equal:'.now()->addHour()->toDateTimeString()],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'max:190'],
            'follow_up_at' => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_time' => ['nullable', 'date_format:H:i'],
        ], [], ['happened_at' => 'date', 'follow_up_at' => 'follow-up date']);

        $followUpDate = $data['follow_up_at'] ?? null;
        $followUpTime = $data['follow_up_time'] ?? null;

        $activity = $logActivity->handle(
            $lead,
            $this->user($request),
            ActivityType::from($data['type']),
            CarbonImmutable::parse($data['happened_at']),
            ($data['notes'] ?? null) ?: null,
            ($data['next_action'] ?? null) ?: null,
            $followUpDate ? CarbonImmutable::parse(CarbonImmutable::parse($followUpDate)->toDateString().($followUpTime ? ' '.$followUpTime : '')) : null,
            followUpHasTime: filled($followUpDate) && filled($followUpTime),
        );

        return (new ActivityResource($activity->load('user')))->response()->setStatusCode(201);
    }
}
