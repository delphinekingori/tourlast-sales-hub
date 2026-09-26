<?php

namespace App\Exports;

use App\Models\PropertyEngagement;
use App\Support\EngagementRegistryFilters;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<PropertyEngagement>
 */
class EngagementRegistryExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private EngagementRegistryFilters $filters) {}

    /**
     * @return Builder<PropertyEngagement>
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
            'Property name', 'Property type', 'Location', 'County / region', 'Country', 'Contact person', 'Phone', 'Email',
            'Salesperson', 'Stage', 'Status', 'First engaged', 'Last engaged', 'Engagement source',
        ];
    }

    /**
     * @param  PropertyEngagement  $row
     * @return list<string|null>
     */
    public function map(mixed $row): array
    {
        return [
            $row->name,
            $row->propertyTypeLabel(),
            $row->locationLabel(),
            $row->region,
            $row->country,
            $row->primaryContact?->name,
            $row->primaryContact?->phone,
            $row->primaryContact?->email,
            $row->salesRep?->name ?? 'Unassigned',
            $row->stage->label(),
            $row->status->label(),
            $row->first_engaged_on->format('Y-m-d'),
            $row->last_engaged_on?->format('Y-m-d'),
            $row->source?->label(),
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
        return 'Engagement Registry';
    }
}
