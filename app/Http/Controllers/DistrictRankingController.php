<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Exports\SchoolTemplateExport;
use App\Models\MasterValue;
use App\Services\AuditLogger;
use App\Services\DistrictRankingService;
use App\Support\MasterPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/** 27-11-2026: District Ranking report (Reports tab), Coverage % and Rating % ranked separately. State-wide viewers only. */
class DistrictRankingController extends Controller
{
    public function __construct(private DistrictRankingService $ranking) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->ranking->filters($request, $user);
        $showRating = DistrictRankingService::showsRating($user);

        return view('reports.ranking.index', [
            'filters' => $f,
            'period' => $this->ranking->period($f),
            'cycle' => $this->ranking->cycle($f),
            'coverage' => $this->ranking->coverageRanking($user, $f),
            'rating' => $showRating ? $this->ranking->ratingRanking($user, $f) : collect(),
            'showRating' => $showRating,
            'how' => app(\App\Services\CoverageReportService::class)->howCounted($f), // the Coverage ranking uses the same rules
            'categories' => MasterValue::where('type', 'category')->orderBy('sort')->get(['id', 'label']),
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $f = $this->ranking->filters($request, $user);
        $this->audit($user, $f, 'excel');
        $sheets = array_map(fn ($s) => [new ArrayExport($s['headings'], $s['rows']), $s['sheet']], $this->ranking->sections($user, $f));

        return Excel::download(new SchoolTemplateExport($sheets), $this->fileName($f, 'xlsx'));
    }

    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $f = $this->ranking->filters($request, $user);
        MasterPdf::prepare();
        $this->audit($user, $f, 'pdf');
        $period = $this->ranking->period($f);
        $chips = array_values(array_filter([
            'Area: Whole state',
            'Period: '.$period->typeLabel().' · '.$period->label().' ('.$period->from->format('d-m-Y').' to '.$period->to->format('d-m-Y').')',
            'Inspection cycle: '.$this->ranking->cycle($f)->from->format('d-m-Y').' to '.$this->ranking->cycle($f)->to->format('d-m-Y'),
            $f['category_id'] ? 'Category: '.MasterValue::whereKey($f['category_id'])->value('label') : null,
            'Downloaded by: '.$user->name,
        ]));

        return MasterPdf::sections('District Ranking · '.$period->typeLabel(), $this->ranking->sections($user, $f), $chips, $this->fileName($f, 'pdf'));
    }

    private function audit($user, array $f, string $format): void
    {
        $period = $this->ranking->period($f);
        AuditLogger::log('district_ranking_exported', null, ['format' => $format, 'type' => $period->type, 'from' => $period->fromDate(), 'to' => $period->toDate(),
            'category_id' => $f['category_id'], 'rating' => DistrictRankingService::showsRating($user)]);
    }

    private function fileName(array $f, string $ext): string
    {
        $period = $this->ranking->period($f);

        return 'district-ranking-'.Str::slug($period->typeLabel()).'-'.$period->fromDate().'-to-'.$period->toDate().'.'.$ext;
    }
}
