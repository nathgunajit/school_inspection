{{-- Find School results (also returned by the live search, see SchoolController::index) --}}
@php
    // Highlight the searched text (escaped first, so the result is safe HTML)
    $hl = function (?string $text) use ($q) {
        $safe = e((string) $text);
        if ($q === '' || mb_strlen($q) < 2) {
            return $safe;
        }

        return preg_replace('/('.preg_quote(e($q), '/').')/iu', '<mark>$1</mark>', $safe);
    };
@endphp
@if ($schools === null)
    <div class="find-empty">
        <div class="ico"><i class="bi bi-building"></i></div>
        <h3>Find a school to inspect</h3>
        <p class="mb-0">Start typing a school name or UDISE Code. Results appear as you type.<br>Only schools inside your jurisdiction are shown.</p>
    </div>
@elseif ($schools->isEmpty())
    <div class="find-empty">
        <div class="ico"><i class="bi bi-search"></i></div>
        <h3>No school found{{ $q !== '' ? ' for “'.$q.'”' : '' }}</h3>
        <p class="mb-0">Check the spelling or the UDISE Code, or clear the filters.<br>If the school is missing, please contact the State Administrator.</p>
    </div>
@else
    <div class="find-meta">
        <span><strong>{{ number_format($schools->total()) }}</strong> school{{ $schools->total() === 1 ? '' : 's' }} found
            @if ($schools->lastPage() > 1) · showing {{ $schools->firstItem() }}–{{ $schools->lastItem() }} @endif</span>
        <span class="d-none d-sm-inline">Tap a school to open it</span>
    </div>
    <div class="school-list">
        @foreach ($schools as $s)
            <a href="{{ route('schools.show', $s) }}" class="school-card {{ $s->isActive() ? '' : 'is-closed' }}">
                <span class="school-badge"><i class="bi bi-building"></i></span>
                <span class="flex-grow-1 min-w-0">
                    <span class="d-block school-name">{!! $hl($s->name) !!}</span>
                    <span class="school-sub">
                        <span class="udise">{!! ctype_digit($q) ? $hl($s->udise_code) : e($s->udise_code) !!}</span>
                        <span><i class="bi bi-geo-alt"></i> {{ $s->cluster?->name }}{{ $s->block ? ', '.$s->block->name : '' }}</span>
                    </span>
                    <span class="school-tags">
                        @if ($s->category)<span class="school-tag">{{ $s->category->label }}</span>@endif
                        @if ($r = $s->latestRating())<x-rating-badge :rating="$r" class="school-rating" title="Rating of the inspection on {{ $s->latestRatedInspection->inspection_date->format('d M Y') }}" />@endif
                        @if ($s->mine_today)<span class="school-tag warn"><i class="bi bi-pencil-square"></i> Your inspection today</span>@endif
                        @if ($s->other_draft)<span class="school-tag lock"><i class="bi bi-hourglass-split"></i> Draft by another officer</span>
                        @elseif ($s->other_this_month)<span class="school-tag lock-done"><i class="bi bi-patch-check"></i> Inspected this month</span>@endif
                        @if ($s->last_inspected_on)<span class="school-tag ok"><i class="bi bi-check2"></i> Last inspected {{ \Illuminate\Support\Carbon::parse($s->last_inspected_on)->format('d M Y') }}</span>
                        @else<span class="school-tag">Not inspected yet</span>@endif
                        @unless ($s->isActive())<span class="school-tag off">Closed</span>@endunless
                    </span>
                </span>
                <span class="school-go"><span>Open</span><i class="bi bi-chevron-right"></i></span>
            </a>
        @endforeach
    </div>
    @if ($schools->hasPages())<div class="mt-3">{{ $schools->links() }}</div>@endif
@endif
