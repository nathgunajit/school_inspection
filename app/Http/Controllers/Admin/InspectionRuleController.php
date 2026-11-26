<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionStartSetting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 26-11-2026: Admin Settings → Inspection Rules (State Admin only, route group can:state_admin).
 * How long a school stays blocked for officers of the same level after a submitted inspection, and whether
 * officers of different levels may inspect the same school on the same day. Read by InspectionStartGuard
 * and ScheduleService::blockedSchools() (plans and Today's Schools).
 */
class InspectionRuleController extends Controller
{
    public function edit()
    {
        $setting = InspectionStartSetting::current()->load('updater:id,name');

        return view('admin.inspection-rules', [
            'setting' => $setting,
            // what each window means today, for the help text and the live summary
            'examples' => collect(InspectionStartSetting::WINDOWS)->keys()->mapWithKeys(function ($w) {
                $s = new InspectionStartSetting(['repeat_window' => $w]);
                $win = $s->window(today());

                return [$w => $win ? ($win[0]->isSameDay($win[1]) ? $win[0]->format('d M Y') : $win[0]->format('d M').' – '.$win[1]->format('d M Y'))
                    .'; open again from '.$s->reopensOn(today())->format('d M Y') : null];
            })->all(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'repeat_window' => ['required', Rule::in(array_keys(InspectionStartSetting::WINDOWS))],
        ]);
        $data['cross_level'] = $request->boolean('cross_level');

        $row = InspectionStartSetting::current();
        $old = $row->only(['repeat_window', 'cross_level']);
        $row->update($data + ['updated_by' => $request->user()->id]);
        AuditLogger::log('inspection_rules_updated', $row, ['old' => $old, 'new' => $data]);

        return back()->with('success', 'Inspection rules saved. '.self::sentence($row));
    }

    /** The rules as plain sentences (page summary and success message). */
    public static function sentence(InspectionStartSetting $s): string
    {
        $repeat = match ($s->repeat_window) {
            'none' => 'After an inspection is submitted, another officer of the same level may inspect the school again at once.',
            'day' => 'After an inspection is submitted, other officers of the same level may inspect the school again from the next day.',
            'week' => 'After an inspection is submitted, other officers of the same level may inspect the school again from the next Monday.',
            default => 'After an inspection is submitted, other officers of the same level may inspect the school again from the 1st of next month.',
        };

        return $repeat.' '.($s->cross_level
            ? 'Officers of different levels (Cluster, Block, District) may inspect the same school, even on the same day.'
            : 'Officers of different levels may not inspect the same school on the same day.');
    }
}
