<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\District;
use App\Models\Holiday;
use App\Services\AuditLogger;
use App\Services\HolidayCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $holidays = Holiday::with('district:id,name,code')
            ->whereBetween('date', ["$year-01-01", "$year-12-31"])
            ->where(fn ($q) => $q->whereNull('district_id')->when($district, fn ($w) => $w->orWhere('district_id', $district))
                ->when(! $district && $user->isStateAdmin() && $request->query('district') === 'all', fn ($w) => $w->orWhereNotNull('district_id')))
            ->orderBy('date')->orderByRaw('district_id is not null')->orderBy('name')->get();

        $editableDistricts = $user->isStateAdmin()
            ? District::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code'])
            : ($own && $user->can('manage_holidays') ? District::whereKey($own)->get(['id', 'name', 'code']) : collect());

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
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $holiday = Holiday::create($data + ['created_by' => $request->user()->id]);
        AuditLogger::log('holiday_created', $holiday, $this->meta($holiday));

        return redirect()->route('holidays.index', ['month' => substr($holiday->date, 0, 7)] + $this->keepDistrict($holiday))
            ->with('success', 'Added '.$holiday->name.' on '.Carbon::parse($holiday->date)->format('d M Y').'.');
    }

    public function update(Request $request, Holiday $holiday)
    {
        abort_unless($this->calendar->canManage($request->user(), $holiday->district_id), 403);
        try {
            $data = $this->validated($request, $holiday);
        } catch (ValidationException $e) {
            throw $e->errorBag('edit');
        }
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

    /** Excel template: date, name, district_code (blank = state holiday), plus a list of district codes. */
    public function template(Request $request)
    {
        $own = $this->calendar->districtOf($request->user());
        $code = $own ? District::whereKey($own)->value('code') : '';
        $rows = [
            [now()->year.'-01-14', 'Magh Bihu', $own ? $code : ''],
            [now()->year.'-08-15', 'Independence Day', $own ? $code : ''],
        ];

        return Excel::download(new ArrayExport(['date', 'name', 'district_code'], $rows), 'holiday-template.xlsx');
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
            throw ValidationException::withMessages(['file' => 'The file needs the columns date, name and district_code (download the template).']);
        }

        $districts = District::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [(string) $code => (int) $id]);
        $errors = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $date = $this->parseDate($r->get('date'));
            $name = trim((string) $r->get('name'));
            $code = trim((string) ($r->get('district_code') ?? ''));
            $districtId = $code === '' ? null : ($districts[$code] ?? false);
            if (! $date) {
                $errors[] = "Row $line: the date is missing or not a date (use YYYY-MM-DD).";
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = "Row $line: the holiday name is missing or longer than 150 characters.";
            }
            if ($districtId === false) {
                $errors[] = "Row $line: district code $code is not in the District Master.";
            } elseif (! $this->calendar->canManage($user, $districtId)) {
                $errors[] = "Row $line: you can add only ".($user->isStateAdmin() ? '' : 'your own district\'s ').'holidays'.($districtId === null && ! $user->isStateAdmin() ? ' (fill in your district code)' : '').'.';
            }
            if ($date && $name !== '' && $districtId !== false) {
                $clean[$date.'|'.($districtId ?? '').'|'.mb_strtolower($name)] = ['date' => $date, 'name' => $name, 'district_id' => $districtId];
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
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
        ], ['date.date_format' => 'Pick the holiday date.']);
        $data['name'] = trim($data['name']);
        $data['district_id'] = isset($data['district_id']) ? (int) $data['district_id'] : null;
        if (! $this->calendar->canManage($request->user(), $data['district_id'])) {
            throw ValidationException::withMessages(['district_id' => $request->user()->isStateAdmin() ? 'Pick a district.' : 'You can add holidays for your own district only.']);
        }
        $dup = Holiday::where('date', $data['date'])->where('name', $data['name'])
            ->where(fn ($q) => $data['district_id'] ? $q->where('district_id', $data['district_id']) : $q->whereNull('district_id'))
            ->when($holiday, fn ($q) => $q->whereKeyNot($holiday->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => 'This holiday is already in the calendar on that date.']);
        }

        return $data;
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
        return ['date' => $h->date, 'name' => $h->name, 'district_id' => $h->district_id];
    }

    private function keepDistrict(Holiday $h): array
    {
        return $h->district_id && request()->user()->level->isStateWide() ? ['district' => $h->district_id] : [];
    }
}
