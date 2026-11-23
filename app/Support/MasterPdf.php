<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Master list downloads as PDF (23-11-2026): School, District, Block and Cluster Masters.
 * A4 landscape, the official header, the standard background (inspections/partials/pdf-watermark)
 * and "Page X of Y" on every page. Rows are plain arrays in the order of $headings.
 */
class MasterPdf
{
    /** @param list<string> $headings  @param list<array> $rows  @param list<string> $filters "District: KOKRAJHAR (1801)" … */
    public static function download(string $title, array $headings, array $rows, array $filters, string $file)
    {
        $dompdf = Pdf::loadView('admin.exports.master-pdf', [
            'title' => $title, 'headings' => $headings, 'rows' => $rows, 'filters' => $filters,
            'logos' => PdfLogos::all(), 'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }
}
