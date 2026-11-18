{{-- Full-size photo viewer used by .rv-photo tiles; script in resources/js/review.js --}}
<div class="modal fade" id="photoViewer" tabindex="-1" aria-label="Photo viewer" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content pv-content">
        <div class="pv-top">
            <div class="min-w-0"><div class="pv-cap" id="pv-cap"></div><div class="pv-meta" id="pv-meta"></div></div>
            <span class="pv-pos" id="pv-pos"></span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="pv-stage">
            <button type="button" class="pv-nav prev" data-pv="-1" aria-label="Previous photo"><i class="bi bi-chevron-left"></i></button>
            <img id="pv-img" alt="">
            <button type="button" class="pv-nav next" data-pv="1" aria-label="Next photo"><i class="bi bi-chevron-right"></i></button>
        </div>
    </div></div>
</div>
