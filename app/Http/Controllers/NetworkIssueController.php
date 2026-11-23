<?php

namespace App\Http\Controllers;

use App\Enums\InspectionStatus;
use App\Models\District;
use App\Models\InspectionAnswer;
use App\Services\JurisdictionService;
use Illuminate\Http\Request;

/**
 * Modification V11 item 2: inspections where the officer reported an internet / network problem
 * at the school (NET_01 = yes, with a remark), so the administration can arrange and raise it.
 * Scoped through JurisdictionService; drafts are included (the problem is often met mid-inspection).
 */
class NetworkIssueController extends Controller
{
    public function __construct(private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'district_id' => ['nullable', 'integer'],
        ]);

        // NET_01 = yes, read through the model cast (the JSON column compares differently on MySQL and SQLite)
        $yes = InspectionAnswer::where('question_code', 'NET_01')->whereNotNull('value')
            ->whereIn('inspection_id', $this->j->inspections($user)->select('id'))
            ->get(['inspection_id', 'value'])->filter(fn ($a) => $a->value === 'yes')->pluck('inspection_id');

        $query = $this->j->inspections($user)
            ->where('status', '!=', InspectionStatus::Cancelled)
            ->whereIn('id', $yes)
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('inspection_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('inspection_date', '<=', $d))
            ->when($f['district_id'] ?? null, fn ($q, $d) => $q->where('district_id', $d));

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
}
