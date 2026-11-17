<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\MasterImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function index()
    {
        return view('admin.imports.index', [
            'batches' => ImportBatch::with('uploader')->latest()->paginate(config('inspection.per_page')),
        ]);
    }

    public function template(string $type)
    {
        abort_unless(in_array($type, ['location', 'school'], true), 404);

        return Excel::download(MasterImportService::template($type), $type.'_master_template.xlsx');
    }

    public function store(Request $request, MasterImportService $service)
    {
        $data = $request->validate([
            'type' => ['required', 'in:location,school'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $batch = $service->import($data['type'], $request->file('file'), $request->user());

        return redirect()->route('admin.imports.index')->with('import_result', $batch->id)->with(
            $batch->rejected ? 'warning' : 'success',
            "Upload complete: {$batch->added} added, {$batch->updated} updated, {$batch->rejected} rejected."
        );
    }

    public function errors(ImportBatch $batch)
    {
        abort_unless($batch->error_file_path && Storage::disk('private')->exists($batch->error_file_path), 404);

        return Storage::disk('private')->download($batch->error_file_path, "import_{$batch->id}_errors.xlsx");
    }
}
