<?php

namespace App\Exports;

use App\Models\Onboarding;
use App\Support\PartnerRegisterFilters;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<Onboarding>
 */
class PartnerRegisterExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private PartnerRegisterFilters $filters) {}

    /**
     * @return Builder<Onboarding>
     */
    public function query(): Builder
    {
        return $this->filters->query();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Referral code', 'Salesperson', 'Property', 'Type', 'Location', 'Contact person', 'Phone', 'Email',
            'Signup date', 'Date onboarded', 'Status', 'Credit', 'tourlast.com ID',
        ];
    }

    /**
     * @param  Onboarding  $row
     * @return list<string|null>
     */
    public function map(mixed $row): array
    {
        return [
            $row->ref_code,
            $row->user?->name ?? 'Unattributed',
            $row->property_name,
            $row->propertyTypeLabel(),
            $row->location,
            $row->contact_name,
            $row->contact_phone,
            $row->contact_email,
            $row->submitted_at?->format('Y-m-d'),
            $row->credited_at?->format('Y-m-d'),
            $row->status->label(),
            $row->attribution === 'manual' ? 'Assigned by admin' : 'Referral link',
            $row->tourlast_property_id,
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
        return 'Partner Register';
    }
}
