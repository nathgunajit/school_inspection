{{-- Standard background for the PDFs (23-11-2026): faint SSA logo with the document title and "SSA, ASSAM",
     centred and repeated on every page (position: fixed), turned 45° (bottom-left to top-right). Placed first in <body>,
     so the content prints over it. Used by every PDF of the project (inspection report and blank paper form).
     Vars: $logo (data URI, App\Support\PdfLogos::all()['ssa']), $title, optional $sub, optional $top (mm from the top of the content area). --}}
@php $sub ??= 'SSA, ASSAM'; $top ??= 58; @endphp
<style>
    .pdf-wm { position: fixed; left: 0; right: 0; top: {{ $top }}mm; text-align: center; z-index: -1;
        transform: rotate(-45deg); transform-origin: 50% 50%; }
    .pdf-wm img { width: 92mm; opacity: .07; }
    .pdf-wm .wt { margin-top: 5mm; font-size: 24pt; font-weight: bold; letter-spacing: 3pt; color: #1565c0; opacity: .08; }
    .pdf-wm .ws { margin-top: 1mm; font-size: 17pt; font-weight: bold; letter-spacing: 6pt; color: #1565c0; opacity: .08; }
</style>
<div class="pdf-wm" aria-hidden="true">
    @if ($logo)<img src="{{ $logo }}" alt="">@endif
    <div class="wt">{{ $title }}</div>
    <div class="ws">{{ $sub }}</div>
</div>
