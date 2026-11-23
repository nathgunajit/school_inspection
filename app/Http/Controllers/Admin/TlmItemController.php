<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionClassroomObservation;
use App\Models\TlmItem;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FLN TLM master (Documents/FLN TLM list_2026-27.docx): the TLM items offered on a classroom observation
 * when "Use of TLM by teacher" = Yes. Items are never deleted, only made inactive (older rows keep them).
 */
class TlmItemController extends Controller
{
    /** Filter / form value for items offered to every class (class = null). */
    public const ALL = 'all';

    public function index(Request $request)
    {
        $class = (string) $request->query('class', '');
        $list = array_key_exists((string) $request->query('list'), TlmItem::LISTS) ? (string) $request->query('list') : '';
        $items = TlmItem::query()
            ->when($list !== '', fn ($q) => $q->where('list', $list))
            ->when($class === self::ALL, fn ($q) => $q->whereNull('class'))
            ->when($class !== '' && $class !== self::ALL, fn ($q) => $q->where('class', $class))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->orderByRaw("list = 'ecce'")->orderByRaw('class is null')->orderBy('class')->orderBy('sort')->orderBy('name')
            ->paginate(config('inspection.per_page'))->withQueryString();

        $all = TlmItem::query()->get(['list', 'class']);
        $counts = $all->when($list !== '', fn ($c) => $c->where('list', $list))->countBy(fn ($i) => $i->class ?? self::ALL);

        return view('admin.tlm.index', [
            'items' => $items, 'class' => $class, 'list' => $list, 'counts' => $counts, 'total' => $counts->sum(),
            'listCounts' => $all->countBy('list'), 'lists' => TlmItem::LISTS,
            'classes' => InspectionClassroomObservation::CLASSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $item = TlmItem::create($data + ['is_active' => true]);
        AuditLogger::log('tlm_item_created', $item, $data);

        return back()->with('success', 'Added '.$item->name.' ('.$item->classLabel().').');
    }

    public function update(Request $request, TlmItem $tlmItem)
    {
        if ($request->has('toggle')) {
            // Inactive: no longer offered on new observations; rows that have it keep it
            $tlmItem->update(['is_active' => ! $tlmItem->is_active]);
            AuditLogger::log('tlm_item_'.($tlmItem->is_active ? 'activated' : 'deactivated'), $tlmItem);

            return back()->with('success', $tlmItem->name.' is now '.($tlmItem->is_active ? 'active' : 'inactive').'.');
        }

        try { // V12 item 7: edit errors go to their own bag, so they show in the Edit window, not under Add
            $data = $this->validated($request, $tlmItem);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
        $tlmItem->update($data);
        AuditLogger::log('tlm_item_updated', $tlmItem, $data);

        return back()->with('success', 'Updated '.$tlmItem->name.'.');
    }

    private function validated(Request $request, ?TlmItem $item = null): array
    {
        $data = $request->validate([
            'list' => ['nullable', Rule::in(array_keys(TlmItem::LISTS))],
            'section' => ['nullable', 'string', 'max:60'],
            'class' => ['required', Rule::in([self::ALL, ...array_keys(InspectionClassroomObservation::CLASSES)])],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'code' => ['nullable', 'string', 'max:60'],
            'variety' => ['nullable', 'integer', 'min:1', 'max:999'],
            'remarks' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['class.required' => 'Select the class.', 'class.in' => 'Select a class from the list.']);

        $data['list'] = $data['list'] ?? ($item?->list ?? 'fln');
        $data['section'] = isset($data['section']) && trim($data['section']) !== '' ? trim($data['section']) : null;
        $data['class'] = $data['class'] === self::ALL ? null : $data['class'];
        $data['name'] = trim($data['name']);
        $data['code'] = isset($data['code']) && trim($data['code']) !== '' ? trim($data['code']) : null;
        $data['variety'] = (int) ($data['variety'] ?? 1);
        $data['sort'] = (int) ($data['sort'] ?? 0);
        $data['remarks'] = isset($data['remarks']) && trim($data['remarks']) !== '' ? trim($data['remarks']) : null;

        // one name per list and class (for "All classes" the DB unique key cannot catch it, since class is null)
        $dup = TlmItem::where('list', $data['list'])->where('name', $data['name'])
            ->where(fn ($q) => $data['class'] === null ? $q->whereNull('class') : $q->where('class', $data['class']))
            ->when($item, fn ($q) => $q->whereKeyNot($item->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => 'This item is already in the '.(TlmItem::LISTS[$data['list']] ?? 'TLM').' list for this class.']);
        }

        return $data;
    }

    /** Modification V13 item 3: delete an item that no classroom observation has ticked. */
    public function destroy(TlmItem $tlmItem)
    {
        $reason = app(\App\Services\MasterDeleteGuard::class)->delete($tlmItem, 'tlm_item_deleted', $tlmItem->name);

        return $reason ? back()->with('error', $reason) : back()->with('success', 'Deleted '.$tlmItem->name.'.');
    }
}
