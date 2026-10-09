<?php

namespace App\Support\Travel;

use App\Enums\Travel\CommissionEntryStatus;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for the influencer codes table, shared by the page and the Excel
 * export so both show exactly the same rows.
 */
class InfluencerCodeFilters
{
    public function __construct(
        public string $search = '',
        public string $salesperson = '',
        public string $status = '',
        public string $platform = '',
        public string $appliesTo = '',
        public string $activeFrom = '',
        public string $activeTo = '',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input[$key] ?? '')) ? (string) $input[$key] : '';

        return new self(
            search: trim((string) ($input['q'] ?? '')),
            salesperson: ctype_digit((string) ($input['salesperson'] ?? '')) ? (string) $input['salesperson'] : '',
            status: InfluencerCodeStatus::tryFrom((string) ($input['status'] ?? ''))?->value ?? '',
            platform: array_key_exists((string) ($input['platform'] ?? ''), Influencer::Platforms) ? (string) $input['platform'] : '',
            appliesTo: InfluencerCodeScope::tryFrom((string) ($input['applies_to'] ?? ''))?->value ?? '',
            activeFrom: $date('active_from'),
            activeTo: $date('active_to'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->search,
            'salesperson' => $this->salesperson,
            'status' => $this->status,
            'platform' => $this->platform,
            'applies_to' => $this->appliesTo,
            'active_from' => $this->activeFrom,
            'active_to' => $this->activeTo,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Codes the viewer may see, with booking and commission totals.
     *
     * @return Builder<InfluencerCode>
     */
    public function query(User $viewer): Builder
    {
        $live = fn (Builder $query) => $query->where('status', '!=', CommissionEntryStatus::Cancelled);

        return InfluencerCode::query()
            ->with(['influencer.owner:id,name', 'influencer.platforms', 'creator:id,name'])
            ->whereHas('influencer', function (Builder $influencers) use ($viewer): void {
                InfluencerAccess::scopeInfluencers($influencers, $viewer);

                $influencers
                    ->when($this->salesperson !== '' && InfluencerAccess::seesAll($viewer), fn (Builder $query) => $query->where('owner_id', (int) $this->salesperson))
                    ->when($this->platform !== '', fn (Builder $query) => $query->whereHas('platforms', fn (Builder $platforms) => $platforms->where('platform', $this->platform)));
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->appliesTo !== '', fn (Builder $query) => $query->where('applies_to', $this->appliesTo))
            ->when($this->activeTo !== '', fn (Builder $query) => $query->whereDate('starts_on', '<=', $this->activeTo))
            ->when($this->activeFrom !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('ends_on')->orWhereDate('ends_on', '>=', $this->activeFrom)))
            ->when($this->search !== '', function (Builder $query): void {
                $like = '%'.$this->search.'%';
                $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', $like)
                    ->orWhereHas('influencer', fn (Builder $influencer) => $influencer
                        ->where('name', 'like', $like)
                        ->orWhereHas('platforms', fn (Builder $platforms) => $platforms->where('handle', 'like', $like))));
            })
            ->withCount(['commissions as bookings_used' => $live])
            ->withSum(['commissions as revenue_generated' => $live], 'booking_amount')
            ->withSum(['commissions as commission_pending' => fn (Builder $query) => $query->where('status', CommissionEntryStatus::Pending)], 'commission_amount')
            ->withSum(['commissions as commission_payable' => fn (Builder $query) => $query->where('status', CommissionEntryStatus::Payable)], 'commission_amount')
            ->withSum(['commissions as commission_paid' => fn (Builder $query) => $query->where('status', CommissionEntryStatus::Paid)], 'commission_amount')
            ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [InfluencerCodeStatus::Active->value, InfluencerCodeStatus::Paused->value])
            ->latest('id');
    }
}
