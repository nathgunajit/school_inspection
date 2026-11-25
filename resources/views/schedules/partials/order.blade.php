{{-- V14 item 4: who assigned the visits and the attached order --}}
@php $assigned = $s->created_by && $s->created_by !== $s->officer_id; @endphp
@if ($assigned || $s->order_ref || $s->attachment_path)
    <div class="sched-order {{ $class ?? 'text-sm mt-1' }}">
        <i class="bi bi-file-earmark-text me-1"></i>
        @if ($assigned)Assigned by <b>{{ $s->creator?->name ?? 'a higher officer' }}</b>@endif
        @if ($s->order_ref){{ $assigned ? ' · ' : '' }}Order {{ $s->order_ref }}@endif
        @if ($s->attachment_path)
            {{ $assigned || $s->order_ref ? ' · ' : '' }}<a href="{{ route('schedules.attachment', $s) }}" target="_blank" rel="noopener" data-no-loader><i class="bi bi-paperclip"></i>{{ $s->attachment_name }}</a>
        @endif
    </div>
@endif
