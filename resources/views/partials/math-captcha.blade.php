{{-- Math CAPTCHA (App\Support\MathCaptcha): the sum is an image, the answer stays in the session --}}
<div class="mb-3 math-captcha" data-math-captcha data-src="{{ route('login.math-captcha') }}">
    <label class="form-label" for="math_answer"><i class="bi bi-calculator me-1 text-primary"></i>Enter the correct answer <span class="req">*</span></label>
    <div class="math-captcha-row">
        <div class="math-captcha-img">
            <img src="{{ route('login.math-captcha') }}?t={{ now()->timestamp }}" alt="A short sum to solve" width="200" height="56" data-math-img>
            <button type="button" class="math-captcha-refresh" aria-label="Show a new sum" title="New sum" data-math-refresh><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <input type="text" id="math_answer" name="math_answer" inputmode="numeric" pattern="-?[0-9]*" maxlength="4"
               class="form-control math-captcha-input @error('math_answer') is-invalid @enderror" placeholder="Answer" autocomplete="off" required>
    </div>
    @error('math_answer')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
<script>
(function () {
    var box = document.currentScript.previousElementSibling, img = box.querySelector('[data-math-img]');
    box.querySelector('[data-math-refresh]').addEventListener('click', function () {
        this.classList.add('is-spinning');
        img.onload = img.onerror = function () { box.querySelector('[data-math-refresh]').classList.remove('is-spinning'); };
        img.src = box.dataset.src + '?new=1&t=' + Date.now();
        var input = box.querySelector('input'); input.value = ''; input.focus();
    });
    // Back/forward cache: the page is shown again, so fetch a fresh sum
    window.addEventListener('pageshow', function (e) { if (e.persisted) img.src = box.dataset.src + '?new=1&t=' + Date.now(); });
})();
</script>
