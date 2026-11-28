<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Http\Controllers\NetworkIssueController;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 28-11-2026: internet connectivity recorded against each school (school survey), built from the inspections:
 * NET_01 "Internet / network problem at the school during this inspection". For each school the LATEST non-cancelled
 * inspection that answered NET_01 decides its state: 'no' (Yes, no internet) or 'ok' (No problem); a school no
 * inspection asked about is "not surveyed" (null). Also: times reported without internet, the last such date and
 * remark, and the no-internet declaration of that inspection (NET_02 tick, signed PDF).
 * Scoped like every inspection query: JurisdictionService::inspections() (area + InspectionVisibility).
 */
class ConnectivitySurvey
{
    public const STATES = ['no' => 'No internet', 'ok' => 'Internet OK'];

    public function __construct(private JurisdictionService $j) {}

    /**
     * @param  int[]|null  $schoolIds  null = every school of the viewer's area (use areaCounts() for big areas)
     * @return array<int, array{state: string, reports: int, last_date: ?string, last_remark: ?string, last_no_id: ?int, last_checked: string, declared: bool, signed_pdf: bool}>
     */
    public function forSchools(User $viewer, ?array $schoolIds, ?string $from = null, ?string $to = null): array
    {
        if ($schoolIds === []) {
            return [];
        }
        $rows = $this->base($viewer, $from, $to)
            ->when($schoolIds !== null, fn ($q) => $q->whereIn('inspections.school_id', $schoolIds))
            ->get(['inspection_answers.value', 'inspection_answers.remarks', 'inspections.id as iid', 'inspections.school_id', 'inspections.inspection_date as idate']);

        $out = $this->reduce($rows);
        $lastNo = array_values(array_filter(array_column($out, 'last_no_id')));
        $decl = NetworkIssueController::declarations($lastNo);
        foreach ($out as &$s) {
            $d = $s['last_no_id'] ? ($decl[$s['last_no_id']] ?? null) : null;
            $s['declared'] = (bool) ($d['ticked'] ?? false);
            $s['signed_pdf'] = $d ? $d['docs']->isNotEmpty() : false;
        }

        return $out;
    }

    /**
     * Schools with no internet / with internet per area (schools.{district|block|cluster}_id), for the map.
     *
     * @return array<int, array{no: int, ok: int}>
     */
    public function areaCounts(User $viewer, string $col, array $areaIds, ?string $from = null, ?string $to = null): array
    {
        if (! $areaIds || ! in_array($col, ['district_id', 'block_id', 'cluster_id'], true)) {
            return [];
        }
        $rows = $this->base($viewer, $from, $to)
            ->join('schools', 'schools.id', '=', 'inspections.school_id')
            ->whereIn("schools.$col", $areaIds)
            ->get(['inspection_answers.value', 'inspection_answers.remarks', 'inspections.id as iid', 'inspections.school_id', 'inspections.inspection_date as idate', "schools.$col as area_id"]);

        $areaOf = $rows->pluck('area_id', 'school_id');
        $out = [];
        foreach ($this->reduce($rows) as $schoolId => $s) {
            $a = (int) $areaOf[$schoolId];
            $out[$a] ??= ['no' => 0, 'ok' => 0];
            $out[$a][$s['state']]++;
        }

        return $out;
    }

    public static function label(?string $state): string
    {
        return self::STATES[$state] ?? 'Not surveyed';
    }

    /** NET_01 answers of the viewer's non-cancelled inspections, oldest first. */
    private function base(User $viewer, ?string $from, ?string $to): Builder
    {
        return InspectionAnswer::query()
            ->join('inspections', 'inspections.id', '=', 'inspection_answers.inspection_id')
            ->where('inspection_answers.question_code', 'NET_01')
            ->whereNotNull('inspection_answers.value')
            ->where('inspections.status', '!=', InspectionStatus::Cancelled->value)
            ->whereNull('inspections.deleted_at')
            ->whereIn('inspections.id', $this->j->inspections($viewer)->select('inspections.id'))
            ->when($from, fn ($q, $d) => $q->where('inspections.inspection_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('inspections.inspection_date', '<=', $d))
            ->orderBy('inspections.inspection_date')->orderBy('inspections.id');
    }

    /** Oldest first in, one state per school out (the latest answer wins). */
    private function reduce($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $v = is_array($r->value) ? ($r->value[0] ?? null) : $r->value; // read through the model cast (JSON differs by database)
            if (! in_array($v, ['yes', 'no'], true)) {
                continue;
            }
            $date = substr((string) $r->getRawOriginal('idate'), 0, 10);
            $s = $out[(int) $r->school_id] ?? ['state' => 'ok', 'reports' => 0, 'last_date' => null, 'last_remark' => null, 'last_no_id' => null, 'last_checked' => $date];
            $s['state'] = $v === 'yes' ? 'no' : 'ok';
            $s['last_checked'] = $date;
            if ($v === 'yes') {
                $s['reports']++;
                $s['last_date'] = $date;
                $s['last_remark'] = filled($r->remarks) ? (string) $r->remarks : $s['last_remark'];
                $s['last_no_id'] = (int) $r->iid;
            }
            $out[(int) $r->school_id] = $s;
        }

        return $out;
    }
}
