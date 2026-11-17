<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Read-only audit search for the State Administrator (PRD 12). */
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
        ]);
    }
}
