<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserLevel;
use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\LoginMonitorService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** V6 Module 6: login user monitoring report for the State Administrator. */
class LoginReportController extends Controller
{
    public function __construct(private LoginMonitorService $monitor) {}

    public function index(Request $request)
    {
        $f = $this->monitor->filters($request);

        return view('admin.login-report.index', [
            'filters' => $f,
            'summary' => $this->monitor->summary($f),
            'logs' => $this->monitor->query($f)->paginate(config('inspection.per_page'))->withQueryString(),
            'levels' => array_filter(UserLevel::cases(), fn ($l) => $l !== UserLevel::StateAdmin),
            'districts' => District::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Modification V8 item 7: daily login report, one row per active officer with the current login status. */
    public function daily(Request $request)
    {
        $f = $this->monitor->dailyFilters($request);
        $rows = $this->monitor->daily($f)->paginate(config('inspection.per_page'))->withQueryString();

        return view('admin.login-report.daily', [
            'filters' => $f,
            'summary' => $this->monitor->dailySummary($f),
            'rows' => $rows,
            'minutes' => $this->monitor->dailyMinutes($rows->pluck('id')->all(), $f['date']),
            'levels' => UserLevel::registrable(),
            'districts' => District::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function dailyExport(Request $request)
    {
        $f = $this->monitor->dailyFilters($request);
        $users = $this->monitor->daily($f)->limit(20000)->get();
        $minutes = $this->monitor->dailyMinutes($users->pluck('id')->all(), $f['date']);
        $time = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('H:i') : '';
        $rows = $users->map(fn (User $u) => [
            $u->name, $u->mobile, $u->designation_label, $u->level->label(), $u->currentJurisdiction?->label(),
            LoginMonitorService::DAILY_STATES[$u->current_state][0] ?? $u->current_state,
            (int) $u->sign_ins, $time($u->first_in), $time($u->last_seen), $minutes[$u->id] ?? 0, (int) $u->refused,
        ])->all();

        return Excel::download(new ArrayExport(['Officer', 'Mobile', 'Designation', 'Level', 'Jurisdiction', 'Current status',
            'Sign-ins that day', 'First sign-in', 'Last activity', 'Minutes online', 'Refused attempts'], $rows), 'daily-login-report-'.$f['date'].'.xlsx');
    }

    /** One officer's sign-in history. */
    public function user(Request $request, User $user)
    {
        $user->load(['designation:id,code,label', 'currentJurisdiction.district:id,name', 'currentJurisdiction.block:id,name', 'currentJurisdiction.cluster:id,name', 'currentJurisdiction.state:id,name']);
        $base = UserLoginLog::where('user_id', $user->id);

        return view('admin.login-report.user', [
            'officer' => $user,
            'logs' => (clone $base)->latest('login_at')->latest('id')->paginate(config('inspection.per_page')),
            'totals' => [
                'logins' => (clone $base)->where('status', 'success')->count(),
                'refused' => (clone $base)->whereIn('status', ['failed', 'locked', 'denied'])->count(),
                'last30' => (clone $base)->where('status', 'success')->where('login_at', '>=', now()->subDays(30))->count(),
                'online' => $this->monitor->onlineScope(clone $base)->exists(),
                'devices' => (clone $base)->where('status', 'success')->whereNotNull('browser')
                    ->selectRaw("browser, platform, device, count(*) as n, max(login_at) as last_at")->groupBy('browser', 'platform', 'device')
                    ->orderByDesc('n')->limit(5)->get(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $f = $this->monitor->filters($request);
        $rows = $this->monitor->query($f)->limit(20000)->get()->map(fn (UserLoginLog $l) => [
            $l->login_at->format('d-m-Y H:i:s'),
            $l->user?->name ?? '—',
            $l->mobile,
            $l->user?->level->label(),
            $l->user?->currentJurisdiction?->label(),
            UserLoginLog::STATUSES[$l->status] ?? $l->status,
            $l->reason,
            LoginMonitorService::stateLabel($l->sessionState())[0],
            $l->logout_at?->format('d-m-Y H:i:s'),
            $l->last_seen_at?->format('d-m-Y H:i:s'),
            $l->durationMinutes(),
            $l->ip_address,
            trim($l->browser.' · '.$l->platform.' · '.$l->device, ' ·'),
        ])->all();

        return Excel::download(new ArrayExport(['Sign-in at', 'Officer', 'Mobile', 'Level', 'Jurisdiction', 'Result', 'Reason', 'Session', 'Signed out at',
            'Last activity', 'Duration (min)', 'IP address', 'Device'], $rows), 'login-report-'.$f['from'].'-to-'.$f['to'].'.xlsx');
    }
}
