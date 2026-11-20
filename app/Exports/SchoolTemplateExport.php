<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Workbook of titled ArrayExport sheets (district school template, school count report). */
class SchoolTemplateExport implements WithMultipleSheets
{
    private array $sheets;

    /** Pairs of [ArrayExport, title] or, for the template, (schools, clusters, clustersTitle). */
    public function __construct(ArrayExport|array $first, ?ArrayExport $second = null, string $secondTitle = 'Clusters')
    {
        $this->sheets = is_array($first) ? $first : [[$first, 'Schools'], [$second, $secondTitle]];
    }

    public function sheets(): array
    {
        return array_map(fn ($p) => new class($p[0], mb_substr($p[1], 0, 31)) implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithStyles, WithTitle {
            public function __construct(private ArrayExport $inner, private string $name) {}

            public function array(): array { return $this->inner->array(); }

            public function headings(): array { return $this->inner->headings(); }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array { return $this->inner->styles($sheet); }

            public function title(): string { return $this->name; }
        }, array_filter($this->sheets, fn ($p) => $p[0] !== null));
    }
}
