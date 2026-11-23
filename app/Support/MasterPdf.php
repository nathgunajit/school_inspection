<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * List downloads as PDF (23-11-2026): School, District, Block and Cluster Masters, My Schools,
 * Inspections / My Inspections and the Coverage Report.
 * A4 landscape, the official header, the standard background (inspections/partials/pdf-watermark)
 * and "Page X of Y" on every page. Rows are plain arrays in the order of their headings.
 */
class MasterPdf
{
    /** @param list<string> $headings  @param list<array> $rows  @param list<string> $filters "District: KOKRAJHAR (1801)" … */
    public static function download(string $title, array $headings, array $rows, array $filters, string $file)
    {
        return self::sections($title, [['title' => null, 'headings' => $headings, 'rows' => $rows]], $filters, $file);
    }

    /**
     * Several tables in one PDF, each with an optional caption. "Records" counts the rows of the last section.
     *
     * @param list<array{title: ?string, headings: list<string>, rows: list<array>}> $sections
     */
    public static function sections(string $title, array $sections, array $filters, string $file)
    {
        return self::view('admin.exports.master-pdf', [
            'title' => $title, 'sections' => $sections, 'filters' => $filters,
            'records' => count(end($sections)['rows'] ?? []),
        ], 'landscape', $file);
    }

    /**
     * Any PDF view with the shared parts: logos and generated time passed in as $logos / $generatedAt,
     * "Page X of Y" stamped at the bottom right (23-11-2026; also the Rating Report PDF).
     */
    public static function view(string $view, array $data, string $orientation, string $file)
    {
        $dompdf = Pdf::loadView($view, $data + ['logos' => PdfLogos::all(), 'generatedAt' => now()])
            ->setPaper('a4', $orientation)->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /** The PDF row cap (DomPDF cannot lay out tens of thousands of rows). */
    public static function maxRows(): int
    {
        return (int) config('inspection.master_pdf_max_rows', 3000);
    }

    /** Longer runs for big lists (one district of schools: about 70 s and 650 MB). */
    public static function prepare(): void
    {
        set_time_limit(300);
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
    }
}
