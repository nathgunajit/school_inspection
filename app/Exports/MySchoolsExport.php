<?php

namespace App\Exports;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

/**
 * 23-11-2026: My Schools as Excel, the officer's own schools with how often they were inspected.
 * The query comes from SchoolController (jurisdiction scope, filters, visit order); read in chunks.
 * The sheet carries the official header (rows 1–5) and is protected, so it cannot be edited.
 */
class MySchoolsExport implements FromQuery, WithHeadings, WithMapping, WithCustomStartCell, WithEvents, WithDrawings, WithColumnWidths
{
    public const HEADINGS = ['UDISE Code', 'School', 'District', 'Block', 'Cluster', 'Category', 'Management', 'Medium', 'Status', 'Times inspected', 'Last inspected'];

    public const RELATIONS = ['district:id,name,code', 'block:id,name,code', 'cluster:id,name,code', 'category:id,label', 'management:id,label'];

    private const LAST_COL = 'K';

    private const HEAD_ROW = 6;

    /** @param array{scope: string, officer: string, filters: list<string>, rows: int} $meta */
    public function __construct(private Builder $query, private array $meta) {}

    public function query()
    {
        return $this->query->with(self::RELATIONS);
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function startCell(): string
    {
        return 'A'.self::HEAD_ROW;
    }

    /** @param School $s */
    public function map($s): array
    {
        return self::row($s);
    }

    /** One school as a list row (shared with the PDF). */
    public static function row(School $s): array
    {
        return [
            $s->udise_code, $s->name, $s->district?->label, $s->block?->label, $s->cluster?->label,
            $s->category?->label, $s->management?->label, $s->medium_label, ucfirst((string) $s->status),
            (int) $s->visits,
            $s->last_inspected_on ? Carbon::parse($s->last_inspected_on)->format('d-m-Y') : 'Not inspected yet',
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 15, 'B' => 42, 'C' => 22, 'D' => 22, 'E' => 26, 'F' => 22, 'G' => 22, 'H' => 18, 'I' => 10, 'J' => 10, 'K' => 18];
    }

    public function drawings(): array
    {
        $out = [];
        foreach ([['ASSAM GOVT LOGO.png', 'A1', 'Government of Assam'], ['SSA LOGO.png', self::LAST_COL.'1', 'Samagra Shiksha']] as [$file, $cell, $name]) {
            $path = public_path('logo/'.$file);
            if (! is_file($path)) {
                continue;
            }
            $d = new Drawing;
            $d->setName($name)->setPath($path)->setHeight(52)->setCoordinates($cell)->setOffsetX(6)->setOffsetY(4);
            $out[] = $d;
        }

        return $out;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $last = self::LAST_COL;
            $head = self::HEAD_ROW;
            $end = max($head, $sheet->getHighestRow());

            $lines = [
                1 => ['GOVERNMENT OF ASSAM', ['bold' => true, 'size' => 13]],
                2 => ['Samagra Shiksha Abhiyan, Assam', ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1B5E20']]],
                3 => ['MY SCHOOLS', ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D47A1']]],
                4 => [implode('   ·   ', ['Area: '.$this->meta['scope'], 'Officer: '.$this->meta['officer'],
                    'Records: '.number_format($this->meta['rows']), 'Generated: '.now()->format('d-m-Y H:i')]), ['size' => 10]],
                5 => [$this->meta['filters'] ? 'Filters: '.implode('   ·   ', $this->meta['filters']) : 'All schools', ['italic' => true, 'size' => 10, 'color' => ['rgb' => '475569']]],
            ];
            foreach ($lines as $r => [$text, $font]) {
                $sheet->mergeCells("A$r:$last$r");
                $sheet->setCellValue("A$r", $text);
                $sheet->getStyle("A$r")->applyFromArray(['font' => $font,
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
            }
            foreach ([1 => 22, 2 => 18, 3 => 22] as $r => $h) {
                $sheet->getRowDimension($r)->setRowHeight($h);
            }
            $sheet->getStyle("A3:{$last}3")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('1565C0');

            $sheet->getStyle("A$head:$last$head")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1565C0']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getStyle("A$head:$last$end")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            $sheet->freezePane('A'.($head + 1));
            $sheet->setAutoFilter("A$head:$last$end");
            $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($head, $head);

            // read-only: the sheet and the workbook structure are locked (select and filter still work)
            $password = (string) (config('inspection.excel_password') ?: Str::random(16));
            $sheet->getProtection()->setPassword($password)->setSheet(true)
                ->setSelectLockedCells(false)->setSelectUnlockedCells(false)->setAutoFilter(false)->setSort(true)
                ->setFormatCells(true)->setFormatColumns(true)->setFormatRows(true)
                ->setInsertRows(true)->setInsertColumns(true)->setDeleteRows(true)->setDeleteColumns(true);
            $event->sheet->getParent()->getSecurity()->setLockStructure(true)->setWorkbookPassword($password);
        }];
    }
}
