<?php

namespace App\Exports;

use App\Models\TripReview;
use App\Models\User;
use App\Support\Travel\TripReviewFilters;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<TripReview>
 */
class TripReviewsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private TripReviewFilters $filters, private User $viewer) {}

    /**
     * @return Builder<TripReview>
     */
    public function query(): Builder
    {
        return $this->filters->query($this->viewer)->with(['provider:id,name', 'package:id,name', 'booking:id,reference', 'client:id,name', 'recorder:id,name']);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Recorded', 'Booking', 'Provider', 'Package', 'Client', 'Rating', ...array_values(TripReview::Categories),
            'Comment', 'Source', 'Recorded by', 'Visibility', 'Hidden reason',
        ];
    }

    /**
     * @param  TripReview  $row
     * @return list<string|int|float|null>
     */
    public function map(mixed $row): array
    {
        return [
            $row->recorded_at->format('Y-m-d H:i'),
            $row->booking?->reference,
            $row->provider?->name,
            $row->package?->name,
            $row->client?->name,
            $row->rating,
            ...array_map(fn (string $column): ?int => $row->{$column}, array_keys(TripReview::Categories)),
            $row->comment,
            $row->source->label(),
            $row->recorder?->name,
            $row->is_hidden ? 'Hidden' : 'Visible',
            $row->hidden_reason,
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
        return 'Trip reviews';
    }
}
