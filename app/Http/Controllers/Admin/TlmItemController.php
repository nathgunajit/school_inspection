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
        $items = TlmItem::query()
            ->when($class === self::ALL, fn ($q) => $q->whereNull('class'))
            ->when($class !== '' && $class !== self::ALL, fn ($q) => $q->where('class', $class))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->orderByRaw('class is null')->orderBy('class')->orderBy('sort')->orderBy('name')
            ->paginate(config('inspection.per_page'))->withQueryString();

        $counts = TlmItem::query()->get(['class'])->countBy(fn ($i) => $i->class ?? self::ALL);

        return view('admin.tlm.index', [
            'items' => $items, 'class' => $class, 'counts' => $counts, 'total' => $counts->sum(),
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

        $data = $this->validated($request, $tlmItem);
        $tlmItem->update($data);
        AuditLogger::log('tlm_item_updated', $tlmItem, $data);

        return back()->with('success', 'Updated '.$tlmItem->name.'.');
    }

    private function validated(Request $request, ?TlmItem $item = null): array
    {
        $data = $request->validate([
            'class' => ['required', Rule::in([self::ALL, ...array_keys(InspectionClassroomObservation::CLASSES)])],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'code' => ['nullable', 'string', 'max:60'],
            'variety' => ['nullable', 'integer', 'min:1', 'max:999'],
            'remarks' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['class.required' => 'Select the class.', 'class.in' => 'Select a class from the list.']);

        $data['class'] = $data['class'] === self::ALL ? null : $data['class'];
        $data['name'] = trim($data['name']);
        $data['code'] = isset($data['code']) && trim($data['code']) !== '' ? trim($data['code']) : null;
        // only class, name and code are on the form; variety, remarks and order keep their stored values
        foreach (['variety', 'sort', 'remarks'] as $k) {
            if (! $request->filled($k)) {
                unset($data[$k]);
            }
        }

        // one name per class (for "All classes" the DB unique key cannot catch it, since class is null)
        $dup = TlmItem::where('name', $data['name'])
            ->where(fn ($q) => $data['class'] === null ? $q->whereNull('class') : $q->where('class', $data['class']))
            ->when($item, fn ($q) => $q->whereKeyNot($item->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => 'This item is already in the list for this class.']);
        }

        return $data;
    }
}
