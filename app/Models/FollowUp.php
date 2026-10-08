<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Support\Travel\TravelSubjects;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A scheduled touchpoint with a lead (or, for Travel Sales, a travel record): a follow-up reminder, call, meeting or
 * site visit, optionally at a set time with a named contact.
 */
#[Fillable([
    'lead_id', 'subject_type', 'subject_id', 'user_id', 'type', 'task', 'due_at', 'has_time', 'duration_minutes',
    'contact_name', 'contact_role', 'location', 'notes', 'completed_at', 'outcome_activity_id',
])]
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'follow_up',
        'has_time' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'due_at' => 'datetime',
            'has_time' => 'boolean',
            'duration_minutes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The travel record this item is about (provider, package, booking,
     * client or flight booking) when it is not about a lead.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What the item is about: the lead's business, or the travel record.
     */
    public function subjectLabel(): string
    {
        return $this->lead_id ? (string) $this->lead?->business_name : TravelSubjects::label($this->subject);
    }

    /**
     * Where to open what the item is about.
     */
    public function subjectUrl(): ?string
    {
        return $this->lead_id ? route('leads.show', $this->lead_id) : TravelSubjects::url($this->subject);
    }

    /**
     * The activity logged when this item was completed.
     *
     * @return BelongsTo<Activity, $this>
     */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'outcome_activity_id');
    }

    /**
     * Still open from a previous day (matches the daily overdue alert).
     */
    public function isOverdue(): bool
    {
        return $this->completed_at === null && $this->due_at->lt(now()->startOfDay());
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Meetings, site visits, demos and contract discussions (as opposed to
     * calls and reminders).
     */
    public function isMeeting(): bool
    {
        return in_array($this->type, self::meetingTypes(), true);
    }

    /**
     * "09:00" or "09:00–10:00", or "Anytime" for date-only reminders.
     */
    public function timeLabel(): string
    {
        if (! $this->has_time) {
            return 'Anytime';
        }

        return $this->due_at->format('H:i').($this->duration_minutes ? '–'.$this->due_at->copy()->addMinutes($this->duration_minutes)->format('H:i') : '');
    }

    /**
     * @return list<ActivityType>
     */
    public static function meetingTypes(): array
    {
        return [
            ActivityType::Meeting, ActivityType::SiteVisit, ActivityType::Demo, ActivityType::ContractDiscussion,
            ActivityType::CustomerMeeting, ActivityType::ProviderMeeting, ActivityType::ContractMeeting,
            ActivityType::PackageReview, ActivityType::PreTripBriefing, ActivityType::PartnerCheckIn,
        ];
    }

    /**
     * Property sales items (about a lead). Property pages, alerts and the
     * property API only ever read these.
     *
     * @param  Builder<FollowUp>  $query
     */
    #[Scope]
    protected function forLeads(Builder $query): void
    {
        $query->whereNotNull('lead_id');
    }

    /**
     * Travel Sales items (about a provider, package, booking, client or flight).
     *
     * @param  Builder<FollowUp>  $query
     */
    #[Scope]
    protected function forTravel(Builder $query): void
    {
        $query->whereNull('lead_id')->whereNotNull('subject_type');
    }

    /**
     * @param  Builder<FollowUp>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /**
     * By day; within a day, timed items by time, then anytime reminders.
     *
     * @param  Builder<FollowUp>  $query
     */
    #[Scope]
    protected function chronological(Builder $query): void
    {
        $query->orderByRaw('date(due_at)')->orderByDesc('has_time')->orderBy('due_at');
    }
}
