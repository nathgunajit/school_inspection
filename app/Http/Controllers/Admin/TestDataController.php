<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TestDataCleaner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * 25-11-2026: State Administrator, clean test data by group (inspections, schedules, login log, audit log),
 * each shown as tables + SQL first. Local server only: when TestDataCleaner::enabled() is false the page is a 404.
 */
class TestDataController extends Controller implements HasMiddleware
{
    public function __construct(private TestDataCleaner $cleaner) {}

    public static function middleware(): array
    {
        return [new Middleware(function (Request $request, Closure $next) {
            abort_unless(TestDataCleaner::enabled(), 404); // hidden on production / when TEST_DATA_CLEANUP is off

            return $next($request);
        })];
    }

    public function index()
    {
        return view('admin.test-data.index', ['groups' => $this->cleaner->preview()]);
    }

    public function clean(Request $request)
    {
        $data = $request->validate([
            'group' => ['required', Rule::in(array_keys(TestDataCleaner::GROUPS))],
            'confirm' => ['required', 'in:DELETE'],
        ], ['confirm.required' => 'Type DELETE to confirm.', 'confirm.in' => 'Type DELETE (in capitals) to confirm.']);

        $result = $this->cleaner->run($data['group'], $request->user());

        return redirect()->to(route('admin.test-data.index').'#clean-'.$data['group'])->with('cleaned', $result)
            ->with('success', "{$result['title']} cleaned: {$result['total']} row(s) and {$result['files']} file(s) removed.");
    }
}
