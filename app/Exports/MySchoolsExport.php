<?php

namespace App\Exports;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * 23-11-2026: My Schools as Excel, the officer's own schools with how often they were inspected.
 * The query comes from SchoolController (jurisdiction scope, filters, visit order); read in chunks.
 * The sheet carries the official header (rows 1–5) and is protected, so it cannot be edited.
 * Column widths follow the content (long text wraps), and the logos are sized to stay inside their column.
 */
class MySchoolsExport implements FromQuery, WithHeadings, WithMapping, WithCustomStartCell, WithEvents, WithDrawings
{
    public const HEADINGS = ['UDISE Code', 'School', 'District', 'Block', 'Cluster', 'Category', 'Management', 'Status', 'Times inspected', 'Last inspected'];

    public const RELATIONS = ['district:id,name,code', 'block:id,name,code', 'cluster:id,name,code', 'category:id,label', 'management:id,label'];

    private const LAST_COL = 'J';

    private const HEAD_ROW = 6;

    /** Logo boxes in pixels [file, cell, name, max width, max height]; the column widths below leave room for them. */
    private const LOGOS = [
        ['ASSAM GOVT LOGO.png', 'A1', 'Government of Assam', 56, 56],
        ['SSA LOGO.png', self::LAST_COL.'1', 'Samagra Shiksha', 140, 48],
    ];

    /** Column width limits in characters: [min, max]. The last column is wide enough for the SSA logo. */
    private const WIDTH_MIN = 10;

    private const WIDTH_MAX = ['B' => 55];

    private const WIDTH_MAX_DEFAULT = 34;

    private const WIDTH_FLOOR = ['A' => 13, self::LAST_COL => 22];

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
            $s->category?->label, $s->management?->label, ucfirst((string) $s->status),
            (int) $s->visits,
            $s->last_inspected_on ? Carbon::parse($s->last_inspected_on)->format('d-m-Y') : 'Not inspected yet',
        ];
    }

    public function drawings(): array
    {
        $out = [];
        foreach (self::LOGOS as [$file, $cell, $name, $w, $h]) {
            $path = public_path('logo/'.$file);
            if (! is_file($path)) {
                continue;
            }
            $d = new Drawing;
            $d->setName($name)->setPath($path)->setResizeProportional(true);
            $d->setWidthAndHeight($w, $h); // fits inside the box, keeping the proportions
            $d->setCoordinates($cell)->setOffsetX(4)->setOffsetY(4);
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

            // column widths from the longest heading / value, within limits (longer text wraps)
            $lastIdx = Coordinate::columnIndexFromString($last);
            for ($c = 1; $c <= $lastIdx; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $len = 0;
                foreach ($sheet->rangeToArray("{$col}{$head}:{$col}{$end}", null, false, false) as [$v]) {
                    $len = max($len, mb_strlen((string) $v));
                }
                $max = self::WIDTH_MAX[$col] ?? self::WIDTH_MAX_DEFAULT;
                $min = max(self::WIDTH_MIN, self::WIDTH_FLOOR[$col] ?? 0);
                $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth(min($max, max($min, $len + 2)));
            }

            $lines = [
                1 => ['GOVERNMENT OF ASSAM', ['bold' => true, 'size' => 13]],
                2 => ['Samagra Shiksha Axom', ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1B5E20']]],
                3 => ['MY SCHOOLS', ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D47A1']]],
                4 => [implode('   ·   ', ['Area: '.$this->meta['scope'], 'Officer: '.$this->meta['officer'],
                    'Records: '.number_format($this->meta['rows']), 'Generated: '.now()->format('d-m-Y H:i')]), ['size' => 10]],
                5 => [$this->meta['filters'] ? 'Filters: '.implode('   ·   ', $this->meta['filters']) : 'All schools', ['italic' => true, 'size' => 10, 'color' => ['rgb' => '475569']]],
            ];
            foreach ($lines as $r => [$text, $font]) {
                $sheet->mergeCells("A$r:$last$r");
                $sheet->setCellValue("A$r", $text);
                $sheet->getStyle("A$r")->applyFromArray(['font' => $font,
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]]);
            }
            // rows 1–3 together are taller than the logos (66 pt ≈ 88 px > 56 px + offset), so they stay in the header
            foreach ([1 => 24, 2 => 20, 3 => 24, 4 => 18, 5 => 18] as $r => $h) {
                $sheet->getRowDimension($r)->setRowHeight($h);
            }
            $sheet->getStyle("A3:{$last}3")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('1565C0');

            $sheet->getStyle("A$head:$last$head")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1565C0']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            if ($end > $head) {
                $sheet->getStyle('A'.($head + 1).":$last$end")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            }
            $sheet->getStyle("A$head:$last$end")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            $sheet->freezePane('A'.($head + 1));
            $sheet->setAutoFilter("A$head:$last$end");

            // print / page view: A4 landscape, one page wide, heading repeated, page numbers
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true)->setRowsToRepeatAtTopByStartAndEnd($head, $head);
            $sheet->getPageMargins()->setTop(0.4)->setBottom(0.5)->setLeft(0.3)->setRight(0.3)->setHeader(0.2)->setFooter(0.25);
            $sheet->getHeaderFooter()->setOddFooter('&L&8My Schools · '.str_replace('&', '&&', $this->meta['scope']).'&R&8Page &P of &N');

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
