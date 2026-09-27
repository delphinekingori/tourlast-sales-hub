<?php

namespace App\Actions;

use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Models\PropertyEngagement;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SavePropertyEngagement
{
    public function __construct(
        private AssignSalesRep $assignSalesRep,
        private TransitionEngagement $transition,
    ) {}

    /**
     * Add a property to the registry with its primary contact and opening
     * timeline entry.
     *
     * @param  array<string, mixed>  $details  Property, location and engagement fields.
     * @param  array{name: string, title: ?string, phone: ?string, whatsapp: ?string, email: ?string}  $contact
     * @param  list<int>  $overriddenDuplicates  Registry ids the user confirmed are different properties.
     */
    public function create(array $details, array $contact, User $by, array $overriddenDuplicates = []): PropertyEngagement
    {
        return DB::transaction(function () use ($details, $contact, $by, $overriddenDuplicates): PropertyEngagement {
            $outcome = $details['outcome'] ?? null;
            unset($details['outcome']);
            $engagement = new PropertyEngagement;
            $engagement->fill($details);

            if ($outcome && in_array($engagement->status, TransitionEngagement::Closing, true)) {
                $engagement->forceFill([
                    'objection' => $outcome['objection'] ?? null,
                    'competitor' => $outcome['competitor'] ?? null,
                    'outcome_notes' => $outcome['notes'] ?? null,
                    'reengage_on' => ($outcome['reengage_on'] ?? null)?->toDateString(),
                    'closed_at' => $engagement->status === EngagementStatus::Stalled ? null : now(),
                ]);
            }
            $engagement->last_engaged_on ??= $engagement->first_engaged_on;
            $engagement->created_by = $by->id;
            $engagement->updated_by = $by->id;
            $engagement->save();

            if ($engagement->sales_rep_id) {
                $engagement->reps()->create([
                    'user_id' => $engagement->sales_rep_id,
                    'started_on' => $engagement->first_engaged_on->toDateString(),
                    'assigned_by' => $by->id,
                ]);
            }

            $engagement->contacts()->create($contact + ['is_primary' => true, 'is_decision_maker' => false, 'created_by' => $by->id]);

            $engagement->events()->create([
                'type' => EngagementEventType::Created,
                'sales_rep_id' => $engagement->sales_rep_id,
                'recorded_by' => $by->id,
                'to_value' => $engagement->stage->value,
                'summary' => $engagement->stage->label().' · '.$engagement->status->label(),
                'notes' => $engagement->summary,
                'changes' => $overriddenDuplicates ? ['duplicate_override' => array_values($overriddenDuplicates)] : null,
                'happened_at' => now(),
            ]);

            return $engagement;
        });
    }

    /**
     * Save edited details. Every changed field is recorded with its old and
     * new value; stage, status and rep changes get their own entries.
     *
     * @param  array<string, mixed>  $details
     */
    public function update(PropertyEngagement $engagement, array $details, User $by): PropertyEngagement
    {
        return DB::transaction(function () use ($engagement, $details, $by): PropertyEngagement {
            $stage = $details['stage'] ?? null;
            $status = $details['status'] ?? null;
            $repId = array_key_exists('sales_rep_id', $details) ? $details['sales_rep_id'] : $engagement->sales_rep_id;
            $repReason = $details['rep_reason'] ?? null;
            $repNotes = $details['rep_notes'] ?? null;
            $outcome = $details['outcome'] ?? null;
            unset($details['stage'], $details['status'], $details['sales_rep_id'], $details['rep_reason'], $details['rep_notes'], $details['outcome']);

            $before = $engagement->only(array_keys(PropertyEngagement::Audited));
            $engagement->fill($details);
            $changes = [];

            foreach (PropertyEngagement::Audited as $field => $label) {
                $old = self::plain($before[$field] ?? null);
                $new = self::plain($engagement->{$field});

                if ($old !== $new) {
                    $changes[] = ['field' => $field, 'label' => $label, 'from' => $old, 'to' => $new];
                }
            }

            if ($changes !== []) {
                $engagement->updated_by = $by->id;
                $engagement->save();

                $engagement->events()->create([
                    'type' => EngagementEventType::Edited,
                    'sales_rep_id' => $engagement->sales_rep_id,
                    'recorded_by' => $by->id,
                    'summary' => collect($changes)->pluck('label')->implode(', '),
                    'changes' => $changes,
                    'happened_at' => now(),
                ]);
            }

            if ($repId !== $engagement->sales_rep_id) {
                $this->assignSalesRep->handle($engagement, $repId ? User::find($repId) : null, $by, CarbonImmutable::now(), $repReason, $repNotes);
            }

            $this->transition->handle(
                $engagement,
                $stage instanceof EngagementStage ? $stage : EngagementStage::tryFrom((string) $stage),
                $status instanceof EngagementStatus ? $status : EngagementStatus::tryFrom((string) $status),
                $by,
                outcome: $outcome,
            );

            // Same closing status, corrected reason: update it and keep the old values in the audit trail.
            if ($outcome && in_array($engagement->status, TransitionEngagement::Closing, true)) {
                $fresh = [
                    'objection' => $outcome['objection']?->value,
                    'competitor' => $outcome['competitor'],
                    'outcome_notes' => $outcome['notes'],
                    'reengage_on' => $outcome['reengage_on']?->toDateString(),
                ];
                $changes = collect($fresh)
                    ->map(fn ($value, $field) => ['field' => $field, 'label' => ['objection' => 'Objection', 'competitor' => 'Competitor', 'outcome_notes' => 'Outcome notes', 'reengage_on' => 'Re-engage date'][$field], 'from' => self::plain($engagement->{$field}), 'to' => $value])
                    ->filter(fn (array $change) => $change['from'] !== $change['to'])
                    ->values()
                    ->all();

                if ($changes !== []) {
                    $engagement->forceFill($fresh + ['updated_by' => $by->id])->save();
                    $engagement->events()->create([
                        'type' => EngagementEventType::Edited,
                        'sales_rep_id' => $engagement->sales_rep_id,
                        'recorded_by' => $by->id,
                        'summary' => collect($changes)->pluck('label')->implode(', '),
                        'changes' => $changes,
                        'happened_at' => now(),
                    ]);
                }
            }

            return $engagement->refresh();
        });
    }

    /**
     * Comparable string form of a stored value.
     */
    private static function plain(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_numeric($value) && str_contains((string) $value, '.') => rtrim(rtrim((string) $value, '0'), '.'),
            default => (string) $value,
        };
    }
}
