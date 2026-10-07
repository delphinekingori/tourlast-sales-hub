<?php

namespace App\Support;

use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters shared by the Partner Register screen, its Excel export and its PDF report.
 */
final readonly class PartnerRegisterFilters
{
    public function __construct(
        public string $status = 'onboarded',
        public ?int $salespersonId = null,
        public ?string $type = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public string $search = '',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $parse = fn ($value): ?CarbonImmutable => filled($value) && strtotime((string) $value) ? CarbonImmutable::parse($value) : null;

        return new self(
            status: in_array($input['status'] ?? null, self::statusKeys(), true) ? $input['status'] : 'onboarded',
            salespersonId: filled($input['salesperson'] ?? null) ? (int) $input['salesperson'] : null,
            type: array_key_exists($input['type'] ?? '', config('hub.property_types')) ? $input['type'] : null,
            from: $parse($input['from'] ?? null)?->startOfDay(),
            to: $parse($input['to'] ?? null)?->endOfDay(),
            search: trim((string) ($input['q'] ?? '')),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'onboarded' => 'Onboarded (live)',
            'inactive' => 'Inactive (was live)',
            'approved' => 'Approved, going live',
            'awaiting' => 'Not live yet (all)',
            'rejected' => 'Rejected',
            'all' => 'All signups',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statusKeys(): array
    {
        return array_keys(self::statusOptions());
    }

    /**
     * Each status is dated by the date that defines it: the onboarding date for
     * live partners, the inactive date for properties that stopped, the signup
     * date for everything else.
     */
    public function dateColumn(): string
    {
        return match ($this->status) {
            'onboarded' => 'credited_at',
            'inactive' => 'inactive_at',
            default => 'submitted_at',
        };
    }

    public function dateLabel(): string
    {
        return match ($this->dateColumn()) {
            'credited_at' => 'Date onboarded',
            'inactive_at' => 'Date inactive',
            default => 'Signup date',
        };
    }

    /**
     * @return Builder<Onboarding>
     */
    public function query(): Builder
    {
        return Onboarding::query()
            ->with(['user', 'referralCode'])
            ->when($this->status === 'onboarded', fn ($query) => $query->onboarded())
            ->when($this->status === 'inactive', fn ($query) => $query->where('status', OnboardingStatus::Inactive))
            ->when($this->status === 'approved', fn ($query) => $query->where('status', OnboardingStatus::Approved))
            ->when($this->status === 'awaiting', fn ($query) => $query->awaitingApproval())
            ->when($this->status === 'rejected', fn ($query) => $query->where('status', OnboardingStatus::Rejected))
            ->when($this->salespersonId, fn ($query) => $query->where('user_id', $this->salespersonId))
            ->when($this->type, fn ($query) => $query->where('property_type', $this->type))
            ->when($this->from, fn ($query) => $query->where($this->dateColumn(), '>=', $this->from))
            ->when($this->to, fn ($query) => $query->where($this->dateColumn(), '<=', $this->to))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('property_name', 'like', "%{$this->search}%")
                ->orWhere('location', 'like', "%{$this->search}%")
                ->orWhere('ref_code', 'like', "%{$this->search}%")))
            ->orderByDesc($this->dateColumn())
            ->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        return array_filter([
            'status' => $this->status,
            'salesperson' => $this->salespersonId ? (string) $this->salespersonId : null,
            'type' => $this->type,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'q' => $this->search ?: null,
        ]);
    }

    public function periodLabel(): string
    {
        return match (true) {
            $this->from && $this->to => $this->from->format('j M Y').' – '.$this->to->format('j M Y'),
            (bool) $this->from => 'From '.$this->from->format('j M Y'),
            (bool) $this->to => 'Up to '.$this->to->format('j M Y'),
            default => 'All time',
        };
    }
}
