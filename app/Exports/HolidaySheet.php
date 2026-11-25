<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
/** One sheet of the holiday template. */
class HolidaySheet implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths
{
    public function __construct(private string $title, private array $headings, private array $rows, private array $widths) {}

    public function array(): array { return $this->rows; }

    public function headings(): array { return $this->headings; }

    public function title(): string { return $this->title; }

    public function columnWidths(): array { return $this->widths; }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('@');

        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1565C0']]]];
    }
}
