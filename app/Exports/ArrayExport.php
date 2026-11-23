<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Simple rows + headings export, used for templates, error lists and list exports. */
class ArrayExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    /** @param array<string, string> $headerFills optional header colour per cell range, e.g. ['A1:B1' => '1565C0'] */
    public function __construct(private array $headings, private array $rows, private array $headerFills = []) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        $styles = [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4338CA']]]];
        foreach ($this->headerFills as $range => $rgb) {
            $styles[$range] = ['fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $rgb]]];
        }

        return $styles;
    }
}
