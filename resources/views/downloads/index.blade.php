<x-layouts.app title="Downloads">
    <p class="text-secondary text-sm mb-3">
        Large Excel and PDF files are prepared in the background, so you can keep working. Each one downloads by itself when ready
        and stays here for {{ $keepHours }} hours.
    </p>

    <div class="card" data-downloads-page data-status-url="{{ route('downloads.status') }}">
        @if ($jobs->isEmpty())
            <x-empty-state icon="bi-cloud-arrow-down" title="No downloads yet" text="Files you download from the lists and reports appear here." />
        @else
            <div class="list-group list-group-flush">
                @foreach ($jobs as $job)
                    <div class="list-group-item d-flex gap-3 align-items-center flex-wrap" data-download-row="{{ $job->getRouteKey() }}" data-status="{{ $job->status }}">
                        <span class="download-icon is-{{ $job->status }}">
                            @if ($job->isActive())
                                <span class="spinner-border spinner-border-sm" role="status" aria-label="Preparing"></span>
                            @else
                                <i class="bi {{ $job->status === 'done' ? (str_ends_with((string) $job->file_name, '.pdf') ? 'bi-file-earmark-pdf' : 'bi-file-earmark-spreadsheet') : 'bi-exclamation-triangle' }}"></i>
                            @endif
                        </span>
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-block fw-semibold text-truncate">{{ $job->title }}</span>
                            <span class="d-block text-xs text-secondary">
                                {{ $job->created_at->format('d M Y, h:i A') }}
                                @if ($job->status === 'done') · {{ $job->file_name }} · {{ \App\Support\FileSize::human((int) ceil(($job->size_bytes ?: 0) / 1024)) }} · until {{ $job->expiresAt()->format('d M, h:i A') }}
                                @elseif ($job->status === 'failed') · <span class="text-danger">{{ $job->error }}</span>
                                @else · {{ $job->status === 'running' ? 'Preparing…' : ($job->isStalled() ? 'The background worker is not running, so this file is made here: press Make it now.' : 'Waiting to start…') }}
                                @endif
                            </span>
                        </span>
                        <span class="d-flex gap-2 ms-auto">
                            @if ($job->isReady())
                                <a href="{{ route('downloads.file', $job) }}" class="btn btn-primary btn-sm" data-no-loader><i class="bi bi-download"></i> Download</a>
                            @endif
                            @if ($job->isStalled())
                                <form method="POST" action="{{ route('downloads.run', $job) }}" data-loader="heavy" data-loader-text="Preparing {{ $job->title }}…">
                                    @csrf
                                    <button class="btn btn-primary btn-sm" data-loading-text="Preparing…"><i class="bi bi-play-fill"></i> Make it now</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('downloads.destroy', $job) }}" data-confirm="Remove this download?">
                                @csrf @method('DELETE')
                                <button class="btn btn-light btn-sm" title="Remove" aria-label="Remove {{ $job->title }}"><i class="bi bi-trash"></i></button>
                            </form>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
