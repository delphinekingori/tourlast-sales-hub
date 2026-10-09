<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One section of the Travel report: rows keyed by column heading.
 */
class TravelReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private string $title, private array $rows) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return array_map('array_values', $this->rows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->rows === [] ? ['No data for this period'] : array_keys($this->rows[0]);
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
