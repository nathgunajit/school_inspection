<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\HousekeepingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

/** Audit search for the State Administrator (PRD 12), plus the V13 archive-and-purge and cache clean-up. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->when($request->action, fn ($q, $v) => $q->where('action', $v))
            ->when($request->q, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->withTrashed()->where('name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%")))
            ->when($request->ip, fn ($q, $v) => $q->where('ip_address', $v))
            ->when($request->date('from'), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->latest('id')
            ->paginate(50)->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'archives' => app(HousekeepingService::class)->archives(),
            'oldCount' => AuditLog::where('created_at', '<', now()->subDays((int) config('inspection.audit_retention_days')))->count(),
        ]);
    }

    /** Modification V13 item 4: archive to Excel, then delete entries older than N days. */
    public function purge(Request $request)
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', 'min:0', 'max:3650']])['days'];
        $r = app(HousekeepingService::class)->purgeAudit($days);

        return back()->with('success', number_format($r['count']).' audit entr'.($r['count'] === 1 ? 'y' : 'ies').' older than '.$days.' day(s) removed.'.($r['file'] ? ' A copy is saved as '.$r['file'].'.' : ''));
    }

    public function clearCache()
    {
        app(HousekeepingService::class)->clearCache();

        return back()->with('success', 'Cache cleared.');
    }

    /** Download an audit archive file (State Admin only, private disk). */
    public function archive(string $file)
    {
        abort_unless(preg_match('/^audit_until_[\d_\-]+\.xlsx$/', $file), 404);
        $path = HousekeepingService::ARCHIVE_DIR.'/'.$file;
        abort_unless(Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->download($path);
    }
}
