<?php

namespace App\Exports;

use App\Models\InfluencerCode;
use App\Models\InfluencerPlatform;
use App\Models\User;
use App\Support\Travel\InfluencerCodeFilters;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<InfluencerCode>
 */
class InfluencerCodesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private InfluencerCodeFilters $filters, private User $viewer) {}

    /**
     * @return Builder<InfluencerCode>
     */
    public function query(): Builder
    {
        return $this->filters->query($this->viewer);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Influencer', 'Platforms', 'Handles', 'Salesperson', 'Code', 'Created by', 'Commission terms', 'Applies to',
            'Starts', 'Ends', 'Booking limit', 'Bookings used', 'Bookings left', 'Revenue (KES)',
            'Commission pending (KES)', 'Commission payable (KES)', 'Commission paid (KES)', 'Status',
        ];
    }

    /**
     * @param  InfluencerCode  $row
     * @return list<string|int|float|null>
     */
    public function map(mixed $row): array
    {
        $used = (int) $row->bookings_used;

        return [
            $row->influencer->name,
            $row->influencer->platforms->map(fn (InfluencerPlatform $platform) => $platform->label())->implode(', ') ?: null,
            $row->influencer->platforms->pluck('handle')->filter()->implode(', ') ?: null,
            $row->influencer->owner?->name,
            $row->code,
            $row->creator?->name,
            $row->termsLabel(),
            $row->applies_to->label(),
            $row->starts_on->format('Y-m-d'),
            $row->ends_on?->format('Y-m-d'),
            $row->max_bookings ?? 'No limit',
            $used,
            $row->max_bookings !== null ? max(0, $row->max_bookings - $used) : null,
            round((float) $row->revenue_generated, 2),
            round((float) $row->commission_pending, 2),
            round((float) $row->commission_payable, 2),
            round((float) $row->commission_paid, 2),
            $row->status->label(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0C5295']],
            ],
        ];
    }

    public function title(): string
    {
        return 'Influencer codes';
    }
}
