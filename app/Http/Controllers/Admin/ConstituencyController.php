<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssemblyConstituency;
use App\Models\ParliamentaryConstituency;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AC / PC Master: Assembly (AC) and Parliamentary (PC, Lok Sabha) constituencies, each with its own code.
 * Schools link to both (`schools.ac_id`, `schools.pc_id`). Records are never deleted, only made inactive.
 */
class ConstituencyController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'pc' ? 'pc' : 'ac';
        $pcFilter = (int) $request->query('pc');

        $acs = AssemblyConstituency::query()->with('pc:id,code,name')->withCount('schools')
            ->when($pcFilter, fn ($q) => $q->where('pc_id', $pcFilter))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->orderBy('code')->paginate(config('inspection.per_page'))->withQueryString();

        $pcs = ParliamentaryConstituency::query()->withCount(['acs', 'schools'])->orderBy('code')->get();

        return view('admin.constituencies.index', [
            'tab' => $tab, 'acs' => $acs, 'pcs' => $pcs, 'pcFilter' => $pcFilter,
            'acTotal' => AssemblyConstituency::count(),
        ]);
    }

    public function store(Request $request, string $type)
    {
        abort_unless(in_array($type, ['ac', 'pc'], true), 404);
        if ($type === 'ac') {
            $data = $this->acData($request);
            $ac = AssemblyConstituency::create($data + ['is_active' => true]);
            AuditLogger::log('ac_created', $ac, $data);

            return back()->with('success', 'Added AC '.$ac->label.'.');
        }
        $data = $this->pcData($request);
        $pc = ParliamentaryConstituency::create($data + ['is_active' => true]);
        AuditLogger::log('pc_created', $pc, $data);

        return redirect()->route('admin.constituencies.index', ['tab' => 'pc'])->with('success', 'Added PC '.$pc->label.'.');
    }

    public function updateAc(Request $request, AssemblyConstituency $ac)
    {
        if ($request->has('toggle')) {
            $ac->update(['is_active' => ! $ac->is_active]);
            AuditLogger::log('ac_'.($ac->is_active ? 'activated' : 'deactivated'), $ac);

            return back()->with('success', $ac->label.' is now '.($ac->is_active ? 'active' : 'inactive').'.');
        }
        try {
            $data = $this->acData($request, $ac);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
        $old = $ac->only(['code', 'name', 'pc_id']);
        $ac->update($data);
        // schools follow their AC's PC
        if ($old['pc_id'] !== $ac->pc_id) {
            $ac->schools()->update(['pc_id' => $ac->pc_id]);
        }
        AuditLogger::log('ac_updated', $ac, ['old' => $old, 'new' => $data]);

        return back()->with('success', 'Updated '.$ac->label.'.');
    }

    public function updatePc(Request $request, ParliamentaryConstituency $pc)
    {
        if ($request->has('toggle')) {
            $pc->update(['is_active' => ! $pc->is_active]);
            AuditLogger::log('pc_'.($pc->is_active ? 'activated' : 'deactivated'), $pc);

            return back()->with('success', $pc->label.' is now '.($pc->is_active ? 'active' : 'inactive').'.');
        }
        try {
            $data = $this->pcData($request, $pc);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
        $old = $pc->only(['code', 'name']);
        $pc->update($data);
        AuditLogger::log('pc_updated', $pc, ['old' => $old, 'new' => $data]);

        return back()->with('success', 'Updated '.$pc->label.'.');
    }

    private function acData(Request $request, ?AssemblyConstituency $ac = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->code)), 'name' => trim(preg_replace('/\s+/', ' ', (string) $request->name))]);

        return $request->validate([
            'code' => ['required', 'regex:/^AC\d{1,8}$/', Rule::unique('assembly_constituencies', 'code')->ignore($ac?->id)],
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'pc_id' => ['required', Rule::exists('parliamentary_constituencies', 'id')],
        ], ['code.regex' => 'AC code is "AC" followed by digits, e.g. AC18002.', 'pc_id.required' => 'Select the PC this AC belongs to.']);
    }

    private function pcData(Request $request, ?ParliamentaryConstituency $pc = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->code)), 'name' => trim(preg_replace('/\s+/', ' ', (string) $request->name))]);

        return $request->validate([
            'code' => ['required', 'regex:/^PC\d{1,8}$/', Rule::unique('parliamentary_constituencies', 'code')->ignore($pc?->id)],
            'name' => ['required', 'string', 'min:2', 'max:60', Rule::unique('parliamentary_constituencies', 'name')->ignore($pc?->id)],
        ], ['code.regex' => 'PC code is "PC" followed by digits, e.g. PC1801.']);
    }

    /** Modification V13 item 3: delete an AC no school uses. */
    public function destroyAc(AssemblyConstituency $ac)
    {
        $reason = app(\App\Services\MasterDeleteGuard::class)->delete($ac, 'ac_deleted', $ac->label);

        return $reason ? back()->with('error', $reason) : back()->with('success', 'Deleted '.$ac->label.'.');
    }

    /** Modification V13 item 3: delete a PC with no AC and no school. */
    public function destroyPc(ParliamentaryConstituency $pc)
    {
        $reason = app(\App\Services\MasterDeleteGuard::class)->delete($pc, 'pc_deleted', $pc->label);

        return $reason ? back()->with('error', $reason) : back()->with('success', 'Deleted '.$pc->label.'.');
    }
}
