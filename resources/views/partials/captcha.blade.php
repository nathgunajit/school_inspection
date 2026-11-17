<div class="mb-3">
    <label class="form-label" for="captcha">Security check</label>
    <div class="captcha-box">
        <img src="{{ captcha_src('flat') }}" alt="CAPTCHA image" id="captcha-img">
        <button type="button" class="btn btn-light btn-icon" aria-label="New CAPTCHA"
                onclick="document.getElementById('captcha-img').src='{{ captcha_src('flat') }}'+Math.random()"><i class="bi bi-arrow-clockwise"></i></button>
        <input type="text" id="captcha" name="captcha" class="form-control @error('captcha') is-invalid @enderror" placeholder="Enter code" autocomplete="off" required>
    </div>
    @error('captcha')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
