{{-- 25-11-2026: State Administrator, clean the test inspection data. Each table, its rows and the SQL run; refused in production. --}}
@php $cleaned = session('cleaned'); @endphp
<x-layouts.app title="Clean Test Data">
    @if ($cleaned)
        <div class="card mb-3 border-success">
            <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi bi-check2-circle text-success"></i>Cleaned
                <span class="text-xs text-secondary ms-auto">{{ number_format($cleaned['total']) }} row(s) and {{ $cleaned['files'] }} file(s) removed</span></div>
            @include('admin.test-data.table', ['rows' => $cleaned['rows'], 'countLabel' => 'Removed', 'total' => $cleaned['total']])
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi bi-trash3 text-danger"></i>Clean test inspection data
            <span class="text-xs text-secondary ms-auto">Rows now: {{ number_format($preview['total']) }} · files on disk: {{ $preview['files'] }}</span></div>
        <div class="card-body pb-2">
            <p class="text-sm mb-2">This removes <b>every inspection</b> (drafts and submitted, with their answers, rows, photographs and ratings), the
                <b>Inspection Schedules</b> and the <b>Today's Schools</b> lists, so testing can start again. It cannot be undone.</p>
            <p class="text-sm mb-0">The tables below are emptied in this order, inside one transaction. First <code>{{ $preview['pre_sql'] }}</code>
                (an inspection can point to the one it was pre-filled from), then:</p>
        </div>
        @include('admin.test-data.table', ['rows' => $preview['rows'], 'countLabel' => 'Rows now', 'total' => $preview['total']])
        <div class="card-body border-top">
            <div class="text-xs text-secondary mb-1">Kept (not touched)</div>
            <div class="d-flex flex-wrap gap-1 mb-3">
                @foreach (\App\Services\TestDataCleaner::KEPT as $k)<span class="badge text-bg-light border fw-normal"><code class="text-body">{{ $k }}</code></span>@endforeach
            </div>

            @if ($production)
                <div class="alert alert-warning mb-0 text-sm"><i class="bi bi-shield-lock me-1"></i>This is the production server: test data cannot be cleaned here.</div>
            @elseif ($preview['total'] === 0)
                <div class="alert alert-light border mb-0 text-sm"><i class="bi bi-check2 me-1"></i>There is no inspection test data to clean.</div>
            @else
                <form method="POST" action="{{ route('admin.test-data.clean') }}" class="row g-2 align-items-end"
                      data-confirm="Delete every inspection, schedule and Today's Schools list? This cannot be undone.">
                    @csrf
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label" for="td-confirm">Type <b>DELETE</b> to confirm</label>
                        <input id="td-confirm" name="confirm" autocomplete="off" class="form-control @error('confirm') is-invalid @enderror" placeholder="DELETE" required>
                        @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-auto"><button class="btn btn-danger" data-loading-text="Cleaning…"><i class="bi bi-trash3"></i>Clean test data</button></div>
                </form>
                <div class="form-text mt-2">Also from the command line: <code>php artisan inspections:clean-test-data</code>. Every run is recorded in the Audit Log (<code>test_data_cleaned</code>).</div>
            @endif
        </div>
    </div>
</x-layouts.app>
