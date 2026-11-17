<x-layouts.app title="Inspection Submitted">
    <div class="row justify-content-center">
        <div class="col-lg-6 text-center">
            <div class="card card-elevated">
                <div class="card-body p-4 p-md-5">
                    <div class="mx-auto mb-4 d-grid rounded-circle tone-success" style="width:88px;height:88px;place-items:center"><i class="bi bi-check2-circle" style="font-size:2.75rem"></i></div>
                    <h2 class="h4 fw-bold mb-1">Inspection submitted</h2>
                    <p class="text-secondary mb-4">{{ $inspection->school->name }}</p>
                    <div class="p-3 rounded-4 bg-light mb-4">
                        <div class="text-xs text-secondary text-uppercase fw-semibold" style="letter-spacing:.08em">Inspection ID</div>
                        <div class="fs-4 fw-bold font-monospace text-primary">{{ $inspection->inspection_no }}</div>
                        <div class="text-xs text-secondary">{{ $inspection->submitted_at->format('d M Y, h:i:s A') }}</div>
                    </div>
                    <div class="d-grid gap-2">
                        <a href="{{ route('inspections.pdf', $inspection) }}" target="_blank" class="btn btn-primary btn-lg"><i class="bi bi-file-earmark-pdf"></i>Download PDF report</a>
                        <a href="{{ route('inspections.show', $inspection) }}" class="btn btn-light"><i class="bi bi-eye"></i>View report</a>
                        <a href="{{ route('schools.index') }}" class="btn btn-link">Start another inspection</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
