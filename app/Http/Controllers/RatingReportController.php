<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Inspection;
use App\Services\AuditLogger;
use App\Services\RatingReportService;
use App\Support\MasterPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Maatwebsite\Excel\Facades\Excel;

/**
 * V6 Module 10: rating report (screen, Excel), scoped to the user's jurisdiction.
 * Modification V9: the PDF is removed; 23-11-2026: a new, designed PDF is added (exportPdf).
 * 20-11-2026: the report is for the State Administrator only (403 for everyone else), whatever RATING_DISPLAY says;
 * that switch still hides ratings on the other pages.
 */
class RatingReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function ($request, $next) {
            abort_unless($request->user()?->can('state_admin'), 403, 'The Rating Report is available to the State Administrator only.');

            return $next($request);
        })];
    }

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
            $i->inspection_no, $i->inspection_date->format('d-m-Y'), $i->district?->label, $i->block?->label, $i->cluster?->label,
            $i->school->udise_code, $i->school->name, $i->school->category?->label, $i->officer?->name,
            (float) $i->rating->score_percent, $i->rating->band, $i->rating->scheme?->version,
            ...$sections->map(fn ($s) => $i->rating->sections[$s['key']]['percent'] ?? null)->all(),
        ])->all();

        AuditLogger::log('rating_report_exported', null, ['format' => 'xlsx', 'rows' => count($data)] + array_filter($f));

        return Excel::download(new ArrayExport(['Inspection ID', 'Date', 'District', 'Block', 'Cluster', 'UDISE Code', 'School', 'Category', 'Officer',
            'Score (%)', 'Rating band', 'Scheme version', ...$sections->map(fn ($s) => $s['title'].' (%)')->all()], $data),
            'rating-report-'.$f['from'].'-to-'.$f['to'].'.xlsx');
    }

    /**
     * 23-11-2026: the report as an A4 PDF (KPI cards, band and section bars, best / lowest, area-wise and
     * school-wise tables), with the screen's filters. The V9 removal is reversed. At most master_pdf_max_rows schools.
     */
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $f = $this->report->filters($request);
        $schools = $this->report->schools($user, $f);
        if ($schools->count() > MasterPdf::maxRows()) {
            return back()->with('warning', 'The PDF holds up to '.number_format(MasterPdf::maxRows()).' schools; '.number_format($schools->count())
                .' match. Choose a district or a shorter period, or download Excel.');
        }
        MasterPdf::prepare();
        $ranked = $schools->sortByDesc('score')->values();
        $options = $this->report->options($user, $f);

        AuditLogger::log('rating_report_exported', null, ['format' => 'pdf', 'rows' => $schools->count()] + array_filter($f));

        return MasterPdf::view('reports.ratings.pdf', [
            'title' => 'Rating Report',
            'filters' => $f,
            'chips' => $this->chips($user, $f, $options),
            'summary' => $this->report->summary($user, $f),
            'areas' => $this->report->areas($user, $f),
            'areaLevel' => $this->report->areaLevel($user, $f),
            'best' => $ranked->take(5),
            'lowest' => $ranked->reverse()->take(5)->values(),
            'rows' => $this->report->sortSchools($schools, $f['sort']),
            'sortLabel' => RatingReportService::SORTS[$f['sort']],
        ], 'portrait', 'rating-report-'.$f['from'].'-to-'.$f['to'].'.pdf');
    }

    /** Filter chips printed under the PDF header. */
    private function chips($user, array $f, array $options): array
    {
        $name = fn ($model, $id) => $id ? $model::find($id)?->label : null;

        return array_values(array_filter([
            'Downloaded by: '.$user->name,
            'Period: '.\Illuminate\Support\Carbon::parse($f['from'])->format('d M Y').' – '.\Illuminate\Support\Carbon::parse($f['to'])->format('d M Y'),
            ($v = $name(\App\Models\District::class, $f['district_id'])) ? 'District: '.$v : null,
            ($v = $name(\App\Models\Block::class, $f['block_id'])) ? 'Block: '.$v : null,
            ($v = $name(\App\Models\Cluster::class, $f['cluster_id'])) ? 'Cluster: '.$v : null,
            $f['band'] ? 'Band: '.$f['band'] : null,
            $f['category_id'] ? 'Category: '.$options['categories']->firstWhere('id', $f['category_id'])?->label : null,
            $f['version'] ? 'Scheme version '.$f['version'] : null,
        ]));
    }
}
