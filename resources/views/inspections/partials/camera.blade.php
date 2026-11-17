{{-- In-app camera (PRD 9.2): no gallery picker; live capture only --}}
<div class="modal fade camera-modal" id="cameraModal" tabindex="-1" aria-label="Camera" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content d-flex flex-column">
            <div class="d-flex align-items-center gap-2 p-2 px-3">
                <button type="button" class="btn btn-icon text-white" data-bs-dismiss="modal" aria-label="Close camera"><i class="bi bi-x-lg fs-5"></i></button>
                <select class="form-select form-select-sm bg-dark text-white border-secondary" id="cam-category" style="max-width:260px" aria-label="Photo category">
                    @foreach ($photoCategories as $pc)<option value="{{ $pc->code }}">{{ $pc->label }}</option>@endforeach
                </select>
                <div class="ms-auto text-xs" id="cam-gps"><i class="bi bi-geo-alt"></i> Locating…</div>
            </div>

            <div class="camera-stage">
                <video id="cam-video" playsinline autoplay muted></video>
                <img id="cam-preview" class="d-none" alt="Captured photograph preview">
                <div id="cam-error" class="d-none text-center p-4">
                    <i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>
                    <div id="cam-error-text">Camera not available.</div>
                    <div class="text-xs text-white-50 mt-2">Allow camera access in your browser settings. The camera needs HTTPS (or localhost).</div>
                </div>
                <div class="camera-meta" id="cam-meta"></div>
            </div>

            <div class="px-3 pb-2 d-none" id="cam-review-fields">
                <input class="form-control form-control-sm bg-dark text-white border-secondary mb-2" id="cam-caption" maxlength="150" placeholder="Caption (optional, up to 150 characters)">
                <input class="form-control form-control-sm bg-dark text-white border-warning d-none" id="cam-noloc" maxlength="255" placeholder="Location unavailable: enter reason (required)">
            </div>

            <div class="camera-controls">
                <button type="button" class="btn btn-icon text-white" id="cam-switch" aria-label="Switch camera"><i class="bi bi-arrow-repeat fs-4"></i></button>
                <button type="button" class="shutter" id="cam-shutter" aria-label="Take photo"></button>
                <button type="button" class="btn btn-outline-light d-none" id="cam-retake"><i class="bi bi-arrow-counterclockwise"></i>Retake</button>
                <button type="button" class="btn btn-success d-none" id="cam-use"><i class="bi bi-check-lg"></i>Use photo</button>
                <span style="width:44px" id="cam-spacer"></span>
            </div>
        </div>
    </div>
</div>
