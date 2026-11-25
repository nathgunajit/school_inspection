{{-- 25-11-2026: the tables cleaned by TestDataCleaner, with the row count and the SQL statement --}}
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th class="ps-3">No.</th><th>Table</th><th>What it holds</th><th class="text-end">{{ $countLabel }}</th><th class="pe-3">SQL</th></tr></thead>
        <tbody>
            @foreach ($rows as $i => $r)
                <tr>
                    <td class="ps-3 text-secondary">{{ $i + 1 }}</td>
                    <td><code class="text-body fw-semibold">{{ $r['table'] }}</code></td>
                    <td class="text-sm">{{ $r['label'] }}</td>
                    <td class="text-end fw-semibold {{ $r['rows'] ? '' : 'text-secondary' }}">{{ number_format($r['rows']) }}</td>
                    <td class="pe-3"><code class="user-select-all text-danger">{{ $r['sql'] }}</code></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><th class="ps-3" colspan="3">Total</th><th class="text-end">{{ number_format($total) }}</th><th></th></tr></tfoot>
    </table>
</div>
