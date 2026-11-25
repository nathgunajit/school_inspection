<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeType;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 25-11-2026: Notices & Orders letter types (Masters → Notice Types). Add, edit, make inactive; never deleted,
 * and the code (stored on every notice) never changes.
 */
class NoticeTypeController extends Controller
{
    public function index()
    {
        return view('admin.notice-types.index', [
            'types' => NoticeType::query()->ordered()->get(),
            'used' => Notice::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $base = Str::of($data['name'])->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(26, '')->value() ?: 'type';
        $code = $base;
        for ($i = 2; NoticeType::where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }
        $type = NoticeType::create($data + ['code' => $code, 'is_active' => true, 'updated_by' => $request->user()->id]);
        AuditLogger::log('notice_type_created', $type, ['code' => $code] + $data);

        return back()->with('success', 'Added the notice type '.$type->name.'.');
    }

    public function update(Request $request, NoticeType $noticeType)
    {
        try { // edit errors go to their own bag, so the Edit window opens again with them
            $data = $this->validated($request, $noticeType);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
        $noticeType->update($data + ['updated_by' => $request->user()->id]);
        AuditLogger::log('notice_type_updated', $noticeType, ['code' => $noticeType->code] + $data);

        return back()->with('success', 'Updated '.$noticeType->name.'.');
    }

    /** Inactive: no longer offered when writing a notice; notices of this type keep showing it. */
    public function status(Request $request, NoticeType $noticeType)
    {
        if ($noticeType->is_active && NoticeType::active()->count() <= 1) {
            return back()->with('error', 'At least one notice type must stay active.');
        }
        $noticeType->update(['is_active' => ! $noticeType->is_active, 'updated_by' => $request->user()->id]);
        AuditLogger::log('notice_type_status', $noticeType, ['code' => $noticeType->code, 'is_active' => $noticeType->is_active]);

        return back()->with('success', $noticeType->name.' is now '.($noticeType->is_active ? 'active' : 'inactive').'.');
    }

    private function validated(Request $request, ?NoticeType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'icon' => ['required', Rule::in(array_keys(NoticeType::ICONS))],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['color.regex' => 'Pick a colour.']);
        $data['name'] = trim($data['name']);
        // one name, whatever the case (MySQL collation and SQLite behave alike)
        if (NoticeType::whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($type, fn ($q) => $q->whereKeyNot($type->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'There is already a notice type with this name.']);
        }
        $data['color'] = strtolower($data['color']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
