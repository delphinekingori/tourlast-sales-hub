<?php

namespace App\Exports;

use App\Models\PayoutStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<PayoutStatement>
 */
class PayoutStatementsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private CarbonImmutable $month) {}

    /**
     * @return Builder<PayoutStatement>
     */
    public function query(): Builder
    {
        return PayoutStatement::query()->with('user')->whereDate('month', $this->month->startOfMonth()->toDateString())->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Salesperson', 'Email', 'Month', 'Approved points', 'Week 1', 'Week 2', 'Week 3', 'Week 4',
            'Retainer', 'Weekly bonus', 'Monthly bonus', 'Exceptional', 'Airtime', 'Transport', 'Adjustments',
            'Total (KES)', 'Retainer conditions met', 'Status', 'Paid on', 'Payment reference',
        ];
    }

    /**
     * @param  PayoutStatement  $row
     * @return list<mixed>
     */
    public function map(mixed $row): array
    {
        $weeks = $row->weekly_points ?? [];

        return [
            $row->user->name, $row->user->email, $row->month->format('F Y'), $row->points,
            $weeks[1] ?? 0, $weeks[2] ?? 0, $weeks[3] ?? 0, $weeks[4] ?? 0,
            $row->retainer, $row->weekly_bonus, $row->monthly_bonus, $row->exceptional,
            $row->airtime, $row->transport, $row->adjustments, $row->total,
            $row->isCompliant() ? 'Yes' : 'No', ucfirst($row->status), $row->paid_at?->format('Y-m-d'), $row->payment_reference,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('B2');

        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0C5295']],
        ]];
    }

    public function title(): string
    {
        return 'Payouts '.$this->month->format('M Y');
    }
}
