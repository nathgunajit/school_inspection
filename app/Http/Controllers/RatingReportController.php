<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Inspection;
use App\Services\AuditLogger;
use App\Services\RatingReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** V6 Module 10: rating report (screen, Excel, PDF), scoped to the user's jurisdiction. */
class RatingReportController extends Controller
{
    public function __construct(private RatingReportService $report) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request);
        $schools = $this->report->schools($user, $f);
        $ranked = $schools->sortByDesc('score')->values();

        return view('reports.ratings.index', [
            'filters' => $f,
            'summary' => $this->report->summary($user, $f),
            'areas' => $this->report->areas($user, $f),
            'areaLevel' => $this->report->areaLevel($user, $f),
            'best' => $ranked->take(5),
            'lowest' => $ranked->reverse()->take(5)->values(),
            'rows' => $this->report->paginate($this->report->sortSchools($schools, $f['sort']), $request),
            'sorts' => RatingReportService::SORTS,
        ] + $this->report->options($user, $f));
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('export_excel'), 403);
        $f = $this->report->filters($request);
        $rows = $this->report->exportRows($request->user(), $f);
        $sections = collect($this->report->summary($request->user(), $f)['sections']);

        $data = $rows->map(fn (Inspection $i) => [
            $i->inspection_no, $i->inspection_date->format('d-m-Y'), $i->district?->name, $i->block?->name, $i->cluster?->name,
            $i->school->udise_code, $i->school->name, $i->school->category?->label, $i->officer?->name,
            (float) $i->rating->score_percent, $i->rating->band, $i->rating->scheme?->version,
            ...$sections->map(fn ($s) => $i->rating->sections[$s['key']]['percent'] ?? null)->all(),
        ])->all();

        AuditLogger::log('rating_report_exported', null, ['format' => 'xlsx', 'rows' => count($data)] + array_filter($f));

        return Excel::download(new ArrayExport(['Inspection ID', 'Date', 'District', 'Block', 'Cluster', 'UDISE Code', 'School', 'Category', 'Officer',
            'Score (%)', 'Rating band', 'Scheme version', ...$sections->map(fn ($s) => $s['title'].' (%)')->all()], $data),
            'rating-report-'.$f['from'].'-to-'.$f['to'].'.xlsx');
    }

    public function pdf(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request);
        $schools = $this->report->sortSchools($this->report->schools($user, $f), $f['sort']);
        $logo = fn (string $file) => is_file($p = public_path('logo/'.$file)) ? 'data:image/png;base64,'.base64_encode(file_get_contents($p)) : null;

        AuditLogger::log('rating_report_exported', null, ['format' => 'pdf'] + array_filter($f));

        $dompdf = Pdf::loadView('reports.ratings.pdf', [
            'filters' => $f,
            'summary' => $this->report->summary($user, $f),
            'areas' => $this->report->areas($user, $f),
            'areaLevel' => $this->report->areaLevel($user, $f),
            'rows' => $schools->take(500),
            'total' => $schools->count(),
            'logos' => ['assam' => $logo('ASSAM GOVT LOGO.png'), 'ssa' => $logo('SSA LOGO.png')],
            'generatedAt' => now(),
            'scope' => $this->scopeLabel($request, $f),
        ])->setPaper('a4')->getDomPDF();
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 40 - 62, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.28, 0.33, 0.41]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="rating-report-'.$f['from'].'-to-'.$f['to'].'.pdf"',
        ]);
    }

    /** "North District · Riverside Block" etc. for the PDF header; the user's own area when nothing is chosen. */
    private function scopeLabel(Request $request, array $f): string
    {
        $parts = array_filter([
            $f['district_id'] ? \App\Models\District::whereKey($f['district_id'])->value('name') : null,
            $f['block_id'] ? \App\Models\Block::whereKey($f['block_id'])->value('name') : null,
            $f['cluster_id'] ? \App\Models\Cluster::whereKey($f['cluster_id'])->value('name') : null,
        ]);

        return $parts ? implode(' · ', $parts) : ($request->user()->currentJurisdiction?->label() ?: 'Assam');
    }
}
