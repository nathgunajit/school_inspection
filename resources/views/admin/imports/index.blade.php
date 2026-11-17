<x-layouts.app title="Excel Upload">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-elevated">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Upload master data</h2>
                    <p class="text-secondary text-sm mb-4">Existing codes are updated and new codes are added. Records missing from the file are <strong>not</strong> removed.</p>
                    <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="choice-group mb-3">
                            <input type="radio" name="type" id="t_location" value="location" @checked(old('type') === 'location')><label for="t_location"><i class="bi bi-geo-alt"></i>Location Master</label>
                            <input type="radio" name="type" id="t_school" value="school" @checked(old('type', 'school') === 'school')><label for="t_school"><i class="bi bi-building"></i>School Master</label>
                        </div>
                        @error('type')<div class="text-danger text-sm mb-2">{{ $message }}</div>@enderror
                        <label class="form-label" for="file">Excel file</label>
                        <input type="file" name="file" id="file" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button class="btn btn-primary w-100 mt-3"><i class="bi bi-cloud-upload"></i>Upload &amp; validate</button>
                    </form>
                </div>
                <div class="card-footer bg-white">
                    <div class="section-title mb-2">Download templates</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="{{ route('admin.imports.template', 'location') }}" class="btn btn-sm btn-soft-primary"><i class="bi bi-download"></i>Location template</a>
                        <a href="{{ route('admin.imports.template', 'school') }}" class="btn btn-sm btn-soft-primary"><i class="bi bi-download"></i>School template</a>
                    </div>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-body text-sm">
                    <div class="section-title">Checks before saving</div>
                    <ul class="mb-0 ps-3 text-secondary">
                        <li>UDISE Code is exactly 11 digits</li>
                        <li>No duplicate codes in the file</li>
                        <li>District, block and cluster codes exist in the Location Master and belong together</li>
                        <li>Mandatory columns are filled</li>
                        <li>Upload the Location Master before the School Master</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Upload history</div>
                <div class="list-card">
                    @forelse ($batches as $b)
                        <div class="list-item flex-wrap {{ session('import_result') == $b->id ? 'bg-primary-subtle' : '' }}">
                            <span class="stat-icon tone-{{ $b->type === 'school' ? 'primary' : 'accent' }}" style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-{{ $b->type === 'school' ? 'building' : 'geo-alt' }}"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $b->file_name }}</div>
                                <div class="text-xs text-secondary">{{ ucfirst($b->type) }} master · {{ $b->created_at->format('d M Y, h:i A') }} · {{ $b->uploader?->name }}</div>
                                <div class="d-flex gap-2 mt-1 text-xs">
                                    <span class="badge-soft badge-soft-success">{{ $b->added }} added</span>
                                    <span class="badge-soft badge-soft-info">{{ $b->updated }} updated</span>
                                    <span class="badge-soft badge-soft-{{ $b->rejected ? 'danger' : 'secondary' }}">{{ $b->rejected }} rejected</span>
                                </div>
                            </div>
                            @if ($b->error_file_path)
                                <a href="{{ route('admin.imports.errors', $b) }}" class="btn btn-sm btn-soft-danger"><i class="bi bi-file-earmark-excel"></i>Error list</a>
                            @endif
                        </div>
                    @empty
                        <x-empty-state icon="bi-file-earmark-spreadsheet" title="No uploads yet" />
                    @endforelse
                </div>
            </div>
            <div class="mt-3">{{ $batches->links() }}</div>
        </div>
    </div>
</x-layouts.app>
