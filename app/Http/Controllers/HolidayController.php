<?php

namespace App\Http\Controllers;

use App\Exports\HolidayTemplateExport;
use App\Models\District;
use App\Models\Holiday;
use App\Models\HolidayWithdrawal;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use App\Services\HolidayCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * V14 item 3 (25-11-2026): the holiday calendar. Every officer sees the state holidays and their own district's
 * local holidays; the State Admin adds state and district holidays, a District officer with Manage users adds
 * their own district's (gate manage_holidays, HolidayCalendar::canManage()). Holidays can be imported from Excel.
 */
class HolidayController extends Controller
{
    /** 27-11-2026: the longest From – To range one Add makes (one row per day). */
    public const MAX_RANGE_DAYS = 31;

    public function __construct(private HolidayCalendar $calendar) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $month = $this->month($request->query('month'));
        $year = (int) $request->query('year', $month->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : $month->year;
        $own = $this->calendar->districtOf($user);
        // State-wide users may look at one district's calendar; others always see their own district
        $district = $own ?? ($request->filled('district') ? (int) $request->query('district') : null);

        $showRestricted = $request->query('restricted') !== '0';
        $holidays = Holiday::with('district:id,name,code')
            ->whereBetween('date', ["$year-01-01", "$year-12-31"])
            ->when(! $showRestricted, fn ($q) => $q->where('type', '!=', 'restricted'))
            // 27-11-2026: in a district's calendar, state holidays withdrawn for it are working days there
            ->where(fn ($q) => $q->where(fn ($s) => $s->whereNull('district_id')->when($district, fn ($x) => $x->whereDoesntHave('withdrawals', fn ($w) => $w->where('district_id', $district))))
                ->when($district, fn ($w) => $w->orWhere('district_id', $district))
                ->when(! $district && $user->isStateAdmin() && $request->query('district') === 'all', fn ($w) => $w->orWhereNotNull('district_id')))
            ->orderBy('date')->orderByRaw('district_id is not null')->orderBy('name')->get();

        $editableDistricts = $this->calendar->editableDistricts($user);
        // 27-11-2026: withdrawals of state holidays for districts (the district in view, or all for the State Admin's state view)
        $withdrawals = $this->calendar->withdrawalsFor($district, $year);
        $withdrawnCounts = $district ? collect() : $withdrawals->groupBy('holiday_id')->map(fn ($g) => $g->map(fn ($w) => $w->district?->label)->filter()->values()->all());
        $stateNames = $editableDistricts->isNotEmpty()
            ? Holiday::whereNull('district_id')->whereIn('type', Holiday::OFF_TYPES)->whereBetween('date', ["$year-01-01", "$year-12-31"])
                ->selectRaw('name, min(date) as first, max(date) as last, count(*) as days')->groupBy('name')->orderBy('first')->get()
            : collect();

        return view('holidays.index', [
            'month' => $month,
            'year' => $year,
            'holidays' => $holidays,
            'byDate' => $holidays->groupBy('date'),
            'district' => $district,
            'ownDistrict' => $own,
            'districts' => $user->level->isStateWide() ? District::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']) : collect(),
            'editableDistricts' => $editableDistricts,
            'canManage' => $user->can('manage_holidays'),
            'weeklyOff' => $this->calendar->weeklyOff(),
            'calendar' => $this->calendar,
            'showRestricted' => $showRestricted,
            'withdrawals' => $withdrawals,
            'withdrawnIn' => $withdrawnCounts,
            'stateNames' => $stateNames,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $to = $data['date_to'] ?? null;
        unset($data['date_to']);
        if ($to && $to !== $data['date']) {
            return $this->storeRange($request, $data, $to);
        }
        $holiday = Holiday::create($data + ['created_by' => $request->user()->id]);
        AuditLogger::log('holiday_created', $holiday, $this->meta($holiday));
        $msg = 'Added '.$holiday->name.' on '.Carbon::parse($holiday->date)->format('d M Y').'.';

        // 25-11-2026: added from the dashboard calendar's day popup: go back there
        if ($request->input('back') === 'dashboard') {
            return redirect()->route('dashboard')->with('success', $msg);
        }

        return redirect()->route('holidays.index', ['month' => substr($holiday->date, 0, 7)] + $this->keepDistrict($holiday))->with('success', $msg);
    }

    /**
     * 27-11-2026: one holiday over a From – To range (e.g. a 3-day local festival): one row per day, the same name, type
     * and area. Days that already have it are skipped; when every day has it, nothing is added.
     */
    private function storeRange(Request $request, array $data, string $to)
    {
        $days = collect(\Carbon\CarbonPeriod::create($data['date'], $to))->map(fn ($d) => $d->toDateString());
        $have = Holiday::whereIn('date', $days)->where('name', $data['name'])
            ->where(fn ($q) => $data['district_id'] ? $q->where('district_id', $data['district_id']) : $q->whereNull('district_id'))
            ->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $new = $days->reject(fn ($d) => in_array($d, $have, true))->values();
        if ($new->isEmpty()) {
            throw ValidationException::withMessages(['name' => 'This holiday is already in the calendar on every day of that range.']);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($new, $data, $to, $request) {
            foreach ($new as $day) {
                $h = Holiday::create(['date' => $day] + $data + ['created_by' => $request->user()->id]);
                AuditLogger::log('holiday_created', $h, $this->meta($h) + ['range' => ['from' => $data['date'], 'to' => $to]]);
            }
        });
        $from = Carbon::parse($data['date']);
        $end = Carbon::parse($to);
        $msg = 'Added '.$data['name'].' on '.$new->count().' day'.($new->count() > 1 ? 's' : '').' ('.$from->format($from->isSameYear($end) ? 'd M' : 'd M Y').' – '.$end->format('d M Y').')'
            .(count($have) ? ', '.count($have).' day'.(count($have) > 1 ? 's' : '').' already there skipped' : '').'.';
        if ($request->input('back') === 'dashboard') {
            return redirect()->route('dashboard')->with('success', $msg);
        }
        $first = new Holiday(['district_id' => $data['district_id']]);

        return redirect()->route('holidays.index', ['month' => substr($new->first(), 0, 7)] + $this->keepDistrict($first))->with('success', $msg);
    }

    public function update(Request $request, Holiday $holiday)
    {
        abort_unless($this->calendar->canManage($request->user(), $holiday->district_id), 403);
        try {
            $data = $this->validated($request, $holiday);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
        unset($data['date_to']);
        $holiday->update($data);
        AuditLogger::log('holiday_updated', $holiday, $this->meta($holiday));

        return back()->with('success', 'Updated '.$holiday->name.'.');
    }

    public function destroy(Request $request, Holiday $holiday)
    {
        abort_unless($this->calendar->canManage($request->user(), $holiday->district_id), 403);
        AuditLogger::log('holiday_deleted', $holiday, $this->meta($holiday));
        $holiday->delete();

        return back()->with('success', 'Deleted '.$holiday->name.'.');
    }

    /**
     * 27-11-2026: withdraw a state holiday for one district over a date range (e.g. a shorter summer vacation where
     * Durga Puja holidays were given instead): that district works on those days. One row per state-holiday day;
     * days already withdrawn are skipped. State Admin (any district) or the district's own manager (District Programmer).
     */
    public function withdraw(Request $request)
    {
        try {
            $data = $request->validate([
                'w_name' => ['required', 'string', 'max:150'],
                'w_district_id' => ['required', 'integer', 'exists:districts,id'],
                'w_from' => ['nullable', 'date_format:Y-m-d'],
                'w_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:w_from'],
                'w_reason' => ['required', 'string', 'min:3', 'max:255'],
                'year' => ['nullable', 'integer', 'between:2000,2100'],
            ], ['w_name.required' => 'Pick the state holiday to withdraw.', 'w_district_id.required' => 'Pick the district.',
                'w_reason.required' => 'Write why it is withdrawn (e.g. the order number).', 'w_to.after_or_equal' => 'The To date must be on or after the From date.']);
        } catch (ValidationException $e) {
            throw $e->errorBag('withdraw');
        }
        $district = (int) $data['w_district_id'];
        abort_unless($this->calendar->canManage($request->user(), $district), 403);
        $year = (int) ($data['year'] ?? substr($data['w_from'] ?? today()->toDateString(), 0, 4));
        $days = Holiday::whereNull('district_id')->where('name', $data['w_name'])->whereIn('type', Holiday::OFF_TYPES)
            ->when($data['w_from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v), fn ($q) => $q->where('date', '>=', "$year-01-01"))
            ->when($data['w_to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v), fn ($q) => $q->where('date', '<=', "$year-12-31"))
            ->whereDoesntHave('withdrawals', fn ($w) => $w->where('district_id', $district))
            ->orderBy('date')->get(['id', 'date', 'name']);
        if ($days->isEmpty()) {
            throw ValidationException::withMessages(['w_name' => 'No day of this state holiday falls in that range (or every day is already withdrawn for the district).'])->errorBag('withdraw');
        }
        $reason = trim($data['w_reason']);
        DB::transaction(function () use ($days, $district, $reason, $request) {
            foreach ($days as $h) {
                HolidayWithdrawal::create(['holiday_id' => $h->id, 'district_id' => $district, 'reason' => $reason, 'created_by' => $request->user()->id]);
            }
        });
        $label = District::find($district)?->label;
        AuditLogger::log('holiday_withdrawn', null, ['name' => $data['w_name'], 'district_id' => $district, 'dates' => $days->pluck('date')->all(), 'days' => $days->count(), 'reason' => $reason]);
        $from = Carbon::parse($days->first()->date);
        $to = Carbon::parse($days->last()->date);

        return redirect()->route('holidays.index', ['month' => $from->format('Y-m')] + ($request->user()->level->isStateWide() ? ['district' => $district] : []))
            ->with('success', $data['w_name'].' withdrawn for '.$label.' on '.$days->count().' day'.($days->count() > 1 ? 's' : '')
                .' ('.($from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M').' – '.$to->format('d M Y')).'). Offices there work on those days.');
    }

    /** 27-11-2026: restore a withdrawn state holiday day for the district. */
    public function restoreWithdrawal(Request $request, HolidayWithdrawal $withdrawal)
    {
        abort_unless($this->calendar->canManage($request->user(), $withdrawal->district_id), 403);
        $withdrawal->loadMissing('holiday:id,date,name', 'district:id,name,code');
        AuditLogger::log('holiday_withdrawal_restored', $withdrawal->holiday, ['name' => $withdrawal->holiday?->name, 'date' => $withdrawal->holiday?->date,
            'district_id' => $withdrawal->district_id, 'reason' => $withdrawal->reason]);
        $withdrawal->delete();

        return back()->with('success', ($withdrawal->holiday?->name ?? 'The holiday').' on '.Carbon::parse($withdrawal->holiday?->date)->format('d M Y').' is a holiday again in '.$withdrawal->district?->label.'.');
    }

    /** Excel template: date, name, type, district_code (blank = state holiday), plus value lists; ?fill=assam-2026 = the Assam list 2026. */
    public function template(Request $request)
    {
        if ($request->query('fill') === 'assam-2026') {
            return Excel::download(HolidayTemplateExport::assam2026(), 'holiday-list-assam-2026.xlsx');
        }
        $own = $this->calendar->districtOf($request->user());

        return Excel::download(HolidayTemplateExport::example($own ? District::whereKey($own)->value('code') : null), 'holiday-template.xlsx');
    }

    /** All rows are checked first; nothing is saved while any row has an error. Rows already in the calendar are skipped. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:'.(int) config('inspection.holidays.import_max_kb', 2048)]],
            ['file.mimes' => 'Upload the Excel template (.xlsx, .xls or .csv).']);
        $user = $request->user();

        $rows = Excel::toCollection(new class implements \Maatwebsite\Excel\Concerns\WithHeadingRow, \Maatwebsite\Excel\Concerns\ToCollection {
            public function collection(Collection $rows) {}
        }, $request->file('file'))->first() ?? collect();
        $rows = $rows->map(fn ($r) => collect($r)->map(fn ($v) => is_string($v) ? trim($v) : $v))
            ->filter(fn ($r) => $r->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())->values();
        if ($rows->isEmpty() || ! $rows->first()->has('date') || ! $rows->first()->has('name')) {
            throw ValidationException::withMessages(['file' => 'The file needs the columns date, name, type and district_code (download the template).']);
        }

        $districts = District::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [(string) $code => (int) $id]);
        $errors = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $date = $this->parseDate($r->get('date'));
            $name = trim((string) $r->get('name'));
            $code = trim((string) ($r->get('district_code') ?? ''));
            $type = $this->parseType($r->get('type'));
            $districtId = $code === '' ? null : ($districts[$code] ?? false);
            if (! $date) {
                $errors[] = "Row $line: the date is missing or not a date (use YYYY-MM-DD).";
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = "Row $line: the holiday name is missing or longer than 150 characters.";
            }
            if ($type === null) {
                $errors[] = "Row $line: type '".$r->get('type')."' is not known (use gazetted, restricted or half; blank = gazetted).";
            }
            if ($districtId === false) {
                $errors[] = "Row $line: district code $code is not in the District Master.";
            } elseif (! $this->calendar->canManage($user, $districtId)) {
                $errors[] = "Row $line: you can add only ".($user->isStateAdmin() ? '' : 'your own district\'s ').'holidays'.($districtId === null && ! $user->isStateAdmin() ? ' (fill in your district code)' : '').'.';
            }
            if ($date && $name !== '' && $districtId !== false && $type !== null) {
                $clean[$date.'|'.($districtId ?? '').'|'.mb_strtolower($name)] = ['date' => $date, 'name' => $name, 'type' => $type, 'district_id' => $districtId];
            }
        }
        if ($errors) {
            return back()->with('import_errors', array_slice($errors, 0, 50))->with('error', count($errors).' problem(s) found. Nothing was imported: correct the file and upload it again.');
        }

        $added = 0;
        foreach ($clean as $row) {
            $exists = Holiday::where('date', $row['date'])->where('name', $row['name'])
                ->where(fn ($q) => $row['district_id'] ? $q->where('district_id', $row['district_id']) : $q->whereNull('district_id'))->exists();
            if (! $exists) {
                Holiday::create($row + ['created_by' => $user->id]);
                $added++;
            }
        }
        AuditLogger::log('holidays_imported', null, ['file' => mb_substr($request->file('file')->getClientOriginalName(), 0, 150), 'rows' => count($clean), 'added' => $added]);

        return back()->with('success', "Imported $added holiday(s)".($added < count($clean) ? ' ('.(count($clean) - $added).' already in the calendar)' : '').'.');
    }

    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'date_to' => $holiday ? ['prohibited'] : ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'], // 27-11-2026: Add only
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'type' => ['nullable', Rule::in(array_keys(Holiday::TYPES))],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
        ], ['date.date_format' => 'Pick the holiday date.', 'date_to.after_or_equal' => 'The To date must be on or after the From date.', 'date_to.date_format' => 'Pick the To date, or leave it empty for one day.']);
        $data['date_to'] = $data['date_to'] ?? null;
        if ($data['date_to'] && Carbon::parse($data['date'])->diffInDays(Carbon::parse($data['date_to'])) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'A holiday range can be at most '.self::MAX_RANGE_DAYS.' days.']);
        }
        $data['name'] = trim($data['name']);
        $data['type'] = $data['type'] ?? 'gazetted';
        $data['district_id'] = isset($data['district_id']) ? (int) $data['district_id'] : null;
        if (! $this->calendar->canManage($request->user(), $data['district_id'])) {
            throw ValidationException::withMessages(['district_id' => $request->user()->isStateAdmin() ? 'Pick a district.' : 'You can add holidays for your own district only.']);
        }
        $dup = (! $data['date_to'] || $data['date_to'] === $data['date']) && Holiday::where('date', $data['date'])->where('name', $data['name'])
            ->where(fn ($q) => $data['district_id'] ? $q->where('district_id', $data['district_id']) : $q->whereNull('district_id'))
            ->when($holiday, fn ($q) => $q->whereKeyNot($holiday->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => 'This holiday is already in the calendar on that date.']);
        }

        return $data;
    }

    /** gazetted (blank, holiday, gazetted, full), restricted (restricted, rh, r.h.) or half; null = unknown. */
    private function parseType(mixed $v): ?string
    {
        $t = preg_replace('/[^a-z]/', '', mb_strtolower(trim((string) $v)));

        return match (true) {
            in_array($t, ['', 'holiday', 'gazetted', 'gazettedholiday', 'full', 'fullholiday', 'publicholiday'], true) => 'gazetted',
            in_array($t, ['restricted', 'restrictedholiday', 'rh'], true) => 'restricted',
            in_array($t, ['half', 'halfholiday', 'halfday'], true) => 'half',
            default => null,
        };
    }

    private function parseDate(mixed $v): ?string
    {
        try {
            if (is_numeric($v)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
            }
            $v = trim((string) $v);
            foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd M Y', 'j M Y'] as $f) {
                $d = \DateTime::createFromFormat('!'.$f, $v);
                if ($d && $d->format($f) === $v) {
                    return $d->format('Y-m-d');
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function month(?string $m): Carbon
    {
        return $m && preg_match('/^\d{4}-\d{2}$/', $m) ? Carbon::createFromFormat('!Y-m', $m) : today()->startOfMonth();
    }

    private function meta(Holiday $h): array
    {
        return ['date' => $h->date, 'name' => $h->name, 'type' => $h->type, 'district_id' => $h->district_id];
    }

    private function keepDistrict(Holiday $h): array
    {
        return $h->district_id && request()->user()->level->isStateWide() ? ['district' => $h->district_id] : [];
    }
}
