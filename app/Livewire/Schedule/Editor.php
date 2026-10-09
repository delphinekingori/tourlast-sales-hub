<?php

namespace App\Livewire\Schedule;

use App\Actions\CompleteScheduleItem;
use App\Actions\Travel\CompleteTravelScheduleItem;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Support\Travel\TravelSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The one place to schedule, edit and complete calls, meetings and visits.
 * Pages open it with: $dispatch('open-schedule', { leadId, itemId, date, complete }).
 *
 * Travel salespeople schedule against a travel record instead of a lead:
 * $dispatch('open-schedule', { subject: 'package:12' }) (see TravelSubjects).
 */
class Editor extends Component
{
    public bool $show = false;

    #[Locked]
    public ?int $itemId = null;

    /** 'edit' or 'complete' */
    public string $mode = 'edit';

    /** @var array<string, string> */
    public array $form = [];

    /** @var array<string, string> */
    public array $done = [];

    #[On('open-schedule')]
    public function open(?int $leadId = null, ?int $itemId = null, ?string $date = null, bool $complete = false, ?string $subject = null): void
    {
        abort_unless($this->sells() || $this->travels(), 403);
        $this->resetValidation();
        $this->itemId = null;
        $this->mode = 'edit';

        if ($itemId) {
            $item = $this->ownedItem($itemId);
            $this->itemId = $item->id;
            $this->form = [
                'lead_id' => (string) $item->lead_id,
                'subject' => $item->subject ? TravelSubjects::key($item->subject) : '',
                'type' => $item->type->value,
                'task' => $item->task,
                'date' => $item->due_at->toDateString(),
                'time' => $item->has_time ? $item->due_at->format('H:i') : '',
                'duration' => (string) $item->duration_minutes,
                'contact_name' => (string) $item->contact_name,
                'contact_role' => (string) $item->contact_role,
                'location' => (string) $item->location,
                'notes' => (string) $item->notes,
            ];
            $this->mode = $complete && ! $item->isDone() ? 'complete' : 'edit';
        } else {
            $lead = $leadId && $this->sells() ? Lead::query()->where('user_id', Auth::id())->find($leadId) : null;
            $this->form = [
                'lead_id' => (string) $lead?->id,
                'subject' => $this->travels() && TravelSubjects::find($subject, Auth::user()) ? (string) $subject : '',
                'type' => $this->travels() ? ActivityType::CustomerFollowUp->value : ActivityType::Meeting->value,
                'task' => '',
                'date' => $date && strtotime($date) ? CarbonImmutable::parse($date)->toDateString() : now()->toDateString(),
                'time' => '',
                'duration' => '60',
                'contact_name' => (string) $lead?->contact_name,
                'contact_role' => (string) $lead?->contact_role,
                'location' => (string) $lead?->location,
                'notes' => '',
            ];
        }

        $nextType = $this->travels() ? ActivityType::CustomerFollowUp : ActivityType::FollowUp;
        $this->done = ['outcome' => '', 'next_action' => '', 'next_type' => $nextType->value, 'next_date' => '', 'next_time' => ''];
        $this->show = true;
    }

    /**
     * Prefill the contact and location from the chosen lead.
     */
    public function updatedFormLeadId(string $leadId): void
    {
        $lead = Lead::query()->where('user_id', Auth::id())->find($leadId);

        if ($lead) {
            $this->form['contact_name'] = (string) $lead->contact_name;
            $this->form['contact_role'] = (string) $lead->contact_role;
            $this->form['location'] = $this->form['location'] ?: (string) $lead->location;
        }
    }

    public function save(): void
    {
        abort_unless($this->sells() || $this->travels(), 403);
        $item = $this->itemId ? $this->ownedItem($this->itemId) : null;
        $travel = $this->travels();

        $data = $this->validate([
            'form.lead_id' => $travel ? ['nullable'] : ['required', Rule::exists('leads', 'id')->where('user_id', Auth::id())],
            'form.subject' => $travel ? ['required', 'string'] : ['nullable'],
            'form.type' => ['required', Rule::enum(ActivityType::class)->only($travel ? ActivityType::forTravel() : ActivityType::forProperty())],
            'form.task' => ['required', 'string', 'max:190'],
            'form.date' => $item ? ['required', 'date'] : ['required', 'date', 'after_or_equal:today'],
            'form.time' => ['nullable', 'date_format:H:i'],
            'form.duration' => ['nullable', 'integer', 'min:5', 'max:720'],
            'form.contact_name' => ['nullable', 'string', 'max:190'],
            'form.contact_role' => ['nullable', 'string', 'max:120'],
            'form.location' => ['nullable', 'string', 'max:190'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'form.lead_id' => 'property / lead', 'form.subject' => 'travel record', 'form.task' => 'title', 'form.date' => 'date', 'form.time' => 'time', 'form.duration' => 'duration',
        ])['form'];

        $subject = $travel ? TravelSubjects::find($data['subject'], Auth::user()) : null;

        if ($travel && ! $subject) {
            $this->addError('form.subject', 'Choose a provider, package, booking, client or flight you can see.');

            return;
        }

        $hasTime = filled($data['time']);
        $attributes = [
            'lead_id' => $travel ? null : (int) $data['lead_id'],
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'type' => ActivityType::from($data['type']),
            'task' => trim($data['task']),
            'due_at' => $hasTime ? CarbonImmutable::parse($data['date'].' '.$data['time']) : CarbonImmutable::parse($data['date'])->startOfDay(),
            'has_time' => $hasTime,
            'duration_minutes' => $hasTime && filled($data['duration']) ? (int) $data['duration'] : null,
            'contact_name' => $data['contact_name'] ?: null,
            'contact_role' => $data['contact_role'] ?: null,
            'location' => $data['location'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($item) {
            $item->update($attributes);
        } else {
            FollowUp::create($attributes + ['user_id' => Auth::id()]);
        }

        $this->show = false;
        $this->dispatch('schedule-saved');
        $this->dispatch('toast', message: $item ? 'Schedule updated.' : ActivityType::from($data['type'])->label().' scheduled.');
    }

    public function startComplete(): void
    {
        $this->ownedItem((int) $this->itemId);
        $this->mode = 'complete';
    }

    public function complete(CompleteScheduleItem $completeScheduleItem, CompleteTravelScheduleItem $completeTravelItem): void
    {
        $item = $this->ownedItem((int) $this->itemId);
        $types = $item->lead_id === null ? ActivityType::forTravel() : ActivityType::forProperty();

        $data = $this->validate([
            'done.outcome' => ['nullable', 'string', 'max:5000'],
            'done.next_action' => ['nullable', 'string', 'max:190'],
            'done.next_type' => ['required', Rule::enum(ActivityType::class)->only($types)],
            'done.next_date' => ['nullable', 'date', 'after_or_equal:today'],
            'done.next_time' => ['nullable', 'date_format:H:i'],
        ], [], ['done.next_date' => 'follow-up date', 'done.next_time' => 'follow-up time'])['done'];

        $nextAt = filled($data['next_date'])
            ? CarbonImmutable::parse($data['next_date'].(filled($data['next_time']) ? ' '.$data['next_time'] : ''))
            : null;

        $handler = $item->lead_id === null ? $completeTravelItem : $completeScheduleItem;

        $handler->handle(
            $item,
            Auth::user(),
            $data['outcome'] ?: null,
            $data['next_action'] ?: null,
            $nextAt,
            $nextAt !== null && filled($data['next_time']),
            ActivityType::from($data['next_type']),
        );

        $this->show = false;
        $this->dispatch('schedule-saved');
        $this->dispatch('toast', message: $nextAt ? 'Done. Next follow-up booked for '.$nextAt->format('D j M').'.' : 'Marked as done.');
    }

    public function delete(): void
    {
        $item = $this->ownedItem((int) $this->itemId);
        abort_if($item->isDone(), 422);
        $item->delete();

        $this->show = false;
        $this->dispatch('schedule-saved');
        $this->dispatch('toast', message: 'Removed from your schedule.');
    }

    public function render(): View
    {
        $travel = $this->travels();

        return view('livewire.schedule.editor', [
            'travel' => $travel,
            'leads' => $this->show && ! $travel ? $this->leadOptions() : new Collection,
            'subjects' => $this->show && $travel ? TravelSubjects::options(Auth::user(), $this->form['subject'] ?? null) : [],
            'types' => $travel ? ActivityType::forTravel() : [
                ActivityType::Meeting, ActivityType::SiteVisit, ActivityType::Call, ActivityType::FollowUp, ActivityType::WhatsApp,
                ActivityType::Email, ActivityType::Demo, ActivityType::ProposalSent, ActivityType::ContractDiscussion,
            ],
            'item' => $this->itemId ? FollowUp::with(['lead', 'subject'])->find($this->itemId) : null,
        ]);
    }

    /**
     * @return Collection<int, Lead>
     */
    private function leadOptions(): Collection
    {
        return Lead::query()
            ->where('user_id', Auth::id())
            ->where(fn ($query) => $query->where('status', '!=', LeadStatus::Lost)->orWhere('id', (int) ($this->form['lead_id'] ?? 0)))
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'location']);
    }

    private function ownedItem(int $itemId): FollowUp
    {
        return FollowUp::query()->where('user_id', Auth::id())->findOrFail($itemId);
    }

    private function sells(): bool
    {
        return (bool) Auth::user()->role()?->earnsReferrals();
    }

    /**
     * Travel salespeople schedule against travel records instead of leads.
     */
    private function travels(): bool
    {
        return Auth::user()->isTravelSalesperson();
    }
}
