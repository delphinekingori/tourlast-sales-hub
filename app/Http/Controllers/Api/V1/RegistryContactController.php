<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EngagementEventType;
use App\Http\Resources\V1\EngagementContactResource;
use App\Models\PropertyEngagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Contacts at a registry property (managers). Every change is written to the
 * property's timeline, and removed contacts are kept there.
 */
class RegistryContactController extends ApiController
{
    /**
     * POST /registry/{engagement}/contacts
     */
    public function store(Request $request, int $engagement): JsonResponse
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'whatsapp' => ['nullable', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'is_decision_maker' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
        ], [], ['title' => 'job title']);
        $userId = $this->user($request)->id;

        $contact = DB::transaction(function () use ($record, $data, $userId) {
            if ($data['is_primary'] ?? false) {
                $record->contacts()->update(['is_primary' => false]);
            }

            $contact = $record->contacts()->create([
                'name' => trim($data['name']),
                'title' => $data['title'],
                'phone' => ($data['phone'] ?? null) ?: null,
                'whatsapp' => ($data['whatsapp'] ?? null) ?: null,
                'email' => ($data['email'] ?? null) ?: null,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
                'is_decision_maker' => (bool) ($data['is_decision_maker'] ?? false),
                'created_by' => $userId,
            ]);

            $record->events()->create([
                'type' => EngagementEventType::ContactAdded,
                'sales_rep_id' => $record->sales_rep_id,
                'recorded_by' => $userId,
                'summary' => "{$contact->name} · {$contact->title}".($contact->is_decision_maker ? ' · decision maker' : '').($contact->is_primary ? ' · now primary contact' : ''),
                'happened_at' => now(),
            ]);

            return $contact;
        });

        return (new EngagementContactResource($contact))->response()->setStatusCode(201);
    }

    /**
     * DELETE /registry/{engagement}/contacts/{contact} — the primary contact can't be removed.
     */
    public function destroy(Request $request, int $engagement, int $contact): JsonResponse
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);
        $model = $record->contacts()->whereKey($contact)->firstOrFail();

        if ($model->is_primary) {
            throw ValidationException::withMessages(['contact' => 'Make another contact primary before removing this one.']);
        }

        $userId = $this->user($request)->id;

        DB::transaction(function () use ($record, $model, $userId): void {
            $record->events()->create([
                'type' => EngagementEventType::ContactRemoved,
                'sales_rep_id' => $record->sales_rep_id,
                'recorded_by' => $userId,
                'summary' => "{$model->name} · {$model->title}",
                'changes' => ['contact' => $model->only(['name', 'title', 'phone', 'whatsapp', 'email', 'is_decision_maker'])],
                'happened_at' => now(),
            ]);
            $model->delete();
        });

        return response()->json(['message' => 'Contact removed. Their details are kept in the history.']);
    }

    /**
     * POST /registry/{engagement}/contacts/{contact}/primary
     */
    public function makePrimary(Request $request, int $engagement, int $contact): EngagementContactResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);
        $model = $record->contacts()->whereKey($contact)->firstOrFail();
        $userId = $this->user($request)->id;

        DB::transaction(function () use ($record, $model, $userId): void {
            $record->contacts()->update(['is_primary' => false]);
            $model->forceFill(['is_primary' => true])->save();
            $record->events()->create([
                'type' => EngagementEventType::Edited,
                'sales_rep_id' => $record->sales_rep_id,
                'recorded_by' => $userId,
                'summary' => "Primary contact: {$model->name}",
                'happened_at' => now(),
            ]);
        });

        return new EngagementContactResource($model->refresh());
    }
}
