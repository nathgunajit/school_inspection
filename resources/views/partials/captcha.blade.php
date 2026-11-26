<div class="mb-3 captcha-field">
    <label class="form-label" for="captcha"><i class="bi bi-shield-lock me-1 text-primary"></i>Security check <span class="req">*</span></label>
    <div class="captcha-box{{ ($inline ?? false) ? ' captcha-box-inline' : '' }}">
        <div class="captcha-img-wrap">
            <img src="{{ captcha_src('flat') }}" alt="CAPTCHA image" id="captcha-img">
            <button type="button" class="btn btn-light captcha-refresh" aria-label="New CAPTCHA" title="Get a new code"
                    onclick="document.getElementById('captcha-img').src='{{ captcha_src('flat') }}'+Math.random()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <input type="text" id="captcha" name="captcha" class="form-control captcha-input @error('captcha') is-invalid @enderror"
               placeholder="Type the code shown" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="10" required>
    </div>
    <div class="form-text">Can't read it? Tap <i class="bi bi-arrow-clockwise"></i> for a new code.</div>
    @error('captcha')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
