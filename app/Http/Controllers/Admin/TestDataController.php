<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TestDataCleaner;
use Illuminate\Http\Request;

/** 25-11-2026: State Administrator, clean the test inspection data (table + SQL preview). Not in production. */
class TestDataController extends Controller
{
    public function __construct(private TestDataCleaner $cleaner) {}

    public function index()
    {
        return view('admin.test-data.index', [
            'production' => app()->isProduction(),
            'preview' => $this->cleaner->preview(),
        ]);
    }

    public function clean(Request $request)
    {
        abort_if(app()->isProduction(), 403, 'Test data cannot be cleaned in production.');
        $request->validate(['confirm' => ['required', 'in:DELETE']],
            ['confirm.required' => 'Type DELETE to confirm.', 'confirm.in' => 'Type DELETE (in capitals) to confirm.']);

        $result = $this->cleaner->run($request->user());

        return redirect()->route('admin.test-data.index')->with('cleaned', $result)
            ->with('success', "Test data cleaned: {$result['total']} row(s) and {$result['files']} file(s) removed.");
    }
}
