<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\District;
use App\Models\InspectionAnswer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Support\MasterPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Modification V11 item 2: inspections where the officer reported an internet / network problem
 * at the school (NET_01 = yes, with a remark), so the administration can arrange and raise it.
 * Scoped through JurisdictionService; drafts are included (the problem is often met mid-inspection).
 * 25-11-2026: the filtered list also downloads as PDF.
 */
class NetworkIssueController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->filters($request);
        $query = $this->query($user, $f);

        $rows = $query->clone()->with(['school', 'officer', 'district', 'block'])
            ->latest('inspection_date')->latest('id')
            ->paginate(config('inspection.per_page'))->withQueryString();
        $remarks = InspectionAnswer::whereIn('inspection_id', $rows->pluck('id'))->where('question_code', 'NET_01')->pluck('remarks', 'inspection_id');

        return view('reports.network.index', [
            'rows' => $rows,
            'remarks' => $remarks,
            'total' => $query->clone()->count(),
            'schools' => $query->clone()->distinct('school_id')->count('school_id'),
            'districts' => $user->level->isStateWide() ? District::orderBy('name')->get(['id', 'code', 'name']) : collect(), // others see their own area only
            'f' => $f,
        ]);
    }

    /** 25-11-2026: the list with the screen's filters as an A4 landscape PDF (at most master_pdf_max_rows rows). */
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $f = $this->filters($request);
        $query = $this->query($user, $f);
        $count = $query->clone()->count();
        $max = MasterPdf::maxRows();
        if ($count > $max) {
            return back()->with('warning', number_format($count).' reports match. A PDF holds at most '.number_format($max).': choose a district or a shorter period.');
        }
        MasterPdf::prepare();

        $list = $query->clone()->with(['school:id,udise_code,name', 'officer:id,name', 'district:id,code,name', 'block:id,code,name'])
            ->latest('inspection_date')->latest('id')->get();
        $remarks = InspectionAnswer::whereIn('inspection_id', $list->pluck('id'))->where('question_code', 'NET_01')->pluck('remarks', 'inspection_id');
        $rows = $list->values()->map(fn ($i, $n) => [
            $n + 1, $i->inspection_date->format('d-m-Y'), $i->school->udise_code, $i->school->name, $i->block?->label, $i->district?->label,
            $i->officer->name, $i->status->label(), filled($remarks[$i->id] ?? null) ? $remarks[$i->id] : 'No remark',
        ])->all();

        $chips = array_values(array_filter([
            ($f['district_id'] ?? null) && ($d = District::find($f['district_id'])) ? 'District: '.$d->label : null,
            ($f['from'] ?? null) ? 'From: '.Carbon::parse($f['from'])->format('d-m-Y') : null,
            ($f['to'] ?? null) ? 'To: '.Carbon::parse($f['to'])->format('d-m-Y') : null,
            'Reports: '.$count.' · Schools: '.$query->clone()->distinct('school_id')->count('school_id'),
            'Downloaded by: '.$user->name,
        ]));
        AuditLogger::log('network_issues_exported', null, ['format' => 'pdf', 'filters' => array_filter($f), 'rows' => count($rows)]);

        return MasterPdf::download('Network Issues at Schools',
            ['Sl', 'Date', 'UDISE', 'School', 'Block', 'District', 'Officer', 'Status', 'Problem reported'],
            $rows, $chips, 'network-issues-'.now()->format('Y-m-d').'.pdf');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'district_id' => ['nullable', 'integer'],
        ]);
    }

    /** Non-cancelled inspections in the user's jurisdiction with NET_01 = yes, filtered. */
    private function query(User $user, array $f): Builder
    {
        // NET_01 = yes, read through the model cast (the JSON column compares differently on MySQL and SQLite)
        $yes = InspectionAnswer::where('question_code', 'NET_01')->whereNotNull('value')
            ->whereIn('inspection_id', $this->j->inspections($user)->select('id'))
            ->get(['inspection_id', 'value'])->filter(fn ($a) => $a->value === 'yes')->pluck('inspection_id');

        return $this->j->inspections($user)
            ->where('status', '!=', InspectionStatus::Cancelled)
            ->whereIn('id', $yes)
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('inspection_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('inspection_date', '<=', $d))
            ->when($f['district_id'] ?? null, fn ($q, $d) => $q->where('district_id', $d));
    }
}
