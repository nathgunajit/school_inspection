{{-- 25-11-2026: State Administrator, clean test data by group. Each group shows its tables, rows and SQL, and has its own
     typed-DELETE form. Local server only (TestDataCleaner::enabled(); otherwise this page is a 404). --}}
@php $cleaned = session('cleaned'); $failed = old('group'); @endphp
<x-layouts.app title="Clean Test Data">
    <div class="alert alert-warning text-sm d-flex gap-2"><i class="bi bi-shield-exclamation fs-5"></i>
        <div><b>Local server only.</b> Use this to clean data made while testing. It is never available on the production server
            (the page does not exist there), and can be switched off here with <code>TEST_DATA_CLEANUP=false</code>. What is deleted cannot be brought back.
            Also from the command line: <code>php artisan data:clean-test {inspections|schedules|login_logs|audit_logs|all}</code>.</div></div>

    @if ($cleaned)
        <div class="card mb-3 border-success">
            <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi bi-check2-circle text-success"></i>Cleaned: {{ $cleaned['title'] }}
                <span class="text-xs text-secondary ms-auto">{{ number_format($cleaned['total']) }} row(s) and {{ $cleaned['files'] }} file(s) removed
                    @if ($cleaned['archive']) · archive <a href="{{ route('admin.audit.archive', $cleaned['archive']) }}" data-no-loader>{{ $cleaned['archive'] }}</a>@endif</span></div>
            @include('admin.test-data.table', ['rows' => $cleaned['rows'], 'countLabel' => 'Removed', 'total' => $cleaned['total']])
        </div>
    @endif

    @foreach ($groups as $g)
        <div class="card mb-3" id="clean-{{ $g['key'] }}">
            <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi {{ $g['icon'] }} text-danger"></i>{{ $g['title'] }}
                <span class="text-xs text-secondary ms-auto">Rows now: {{ number_format($g['total']) }}@if ($g['files']) · files on disk: {{ $g['files'] }}@endif</span></div>
            <div class="card-body pb-2">
                <p class="text-sm mb-0">{{ $g['text'] }}@if ($g['pre_sql']) First <code>{{ $g['pre_sql'] }}</code>, then:@endif</p>
            </div>
            @include('admin.test-data.table', ['rows' => $g['rows'], 'countLabel' => 'Rows now', 'total' => $g['total']])
            <div class="card-body border-top">
                @if ($g['total'] === 0)
                    <div class="text-sm text-secondary"><i class="bi bi-check2 me-1"></i>Nothing to clean.</div>
                @else
                    <form method="POST" action="{{ route('admin.test-data.clean') }}" class="row g-2 align-items-end"
                          data-confirm="Delete all {{ strtolower($g['title']) }} data? This cannot be undone.">
                        @csrf
                        <input type="hidden" name="group" value="{{ $g['key'] }}">
                        @php $mine = $failed === $g['key']; @endphp
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label" for="td-{{ $g['key'] }}">Type <b>DELETE</b> to confirm</label>
                            <input id="td-{{ $g['key'] }}" name="confirm" autocomplete="off" class="form-control {{ $mine && $errors->has('confirm') ? 'is-invalid' : '' }}" placeholder="DELETE" required>
                            @if ($mine && $errors->has('confirm'))<div class="invalid-feedback">{{ $errors->first('confirm') }}</div>@endif
                        </div>
                        <div class="col-sm-auto"><button class="btn btn-danger" data-loading-text="Cleaning…"><i class="bi bi-trash3"></i>Clean {{ strtolower($g['title']) }}</button></div>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
    <div class="text-xs text-secondary">Users, schools and the other masters, notices, holidays and settings are never touched here. Every clean-up is recorded in the Audit Log (<code>test_data_cleaned</code>).</div>
</x-layouts.app>
