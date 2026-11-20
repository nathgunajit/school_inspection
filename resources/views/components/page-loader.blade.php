{{-- Branded loader (only on a slow load or a heavy action), thin top bar, slow/offline banner holder.
     Critical CSS is inline so it shows before app.css arrives on slow networks. --}}
<style>
#si-loader{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1rem;
  background:radial-gradient(circle at 30% 20%,rgba(21,101,192,.10),transparent 55%),radial-gradient(circle at 75% 85%,rgba(30,155,75,.10),transparent 55%),#fff;
  opacity:0;pointer-events:none;animation:siLdIn .3s ease 1.5s forwards;transition:opacity .35s ease,visibility .35s;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
#si-loader.is-instant{animation:none;opacity:1;pointer-events:auto}
#si-loader.is-hidden{opacity:0!important;visibility:hidden;animation:none;pointer-events:none}
.si-ld-logo{position:relative;width:112px;height:112px;display:grid;place-items:center}
.si-ld-logo img{width:84px;height:84px;object-fit:contain;border-radius:50%;background:#fff;padding:6px;box-shadow:0 8px 24px rgba(21,101,192,.18)}
.si-ld-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(21,101,192,.12);border-top-color:#1565c0;border-right-color:#f39223;animation:siLdSpin 1s linear infinite}
.si-ld-title{margin:0;font-size:1.3rem;font-weight:800;letter-spacing:.01em;color:#1565c0}
.si-ld-sub{margin:-.7rem 0 0;font-size:.78rem;color:#64748b;letter-spacing:.04em}
.si-ld-msg{margin:-.3rem 0 0;font-size:.82rem;font-weight:600;color:#1e9b4b;min-height:1.1em;text-align:center;padding:0 1rem}
.si-ld-dots{display:flex;gap:.4rem}.si-ld-dots span{width:7px;height:7px;border-radius:50%;background:#1e9b4b;animation:siLdDot 1s ease-in-out infinite}
.si-ld-dots span:nth-child(2){animation-delay:.15s;background:#f39223}.si-ld-dots span:nth-child(3){animation-delay:.3s;background:#1565c0}
#si-topbar{position:fixed;top:0;left:0;height:3px;width:0;z-index:2001;background:linear-gradient(90deg,#1565c0,#1e9b4b);box-shadow:0 0 6px rgba(21,101,192,.5);opacity:0;pointer-events:none}
#si-topbar.is-on{opacity:1;width:85%;transition:width 8s cubic-bezier(.1,.6,.2,1),opacity .2s}
@keyframes siLdIn{to{opacity:1;pointer-events:auto}}@keyframes siLdSpin{to{transform:rotate(360deg)}}
@keyframes siLdDot{0%,100%{transform:translateY(0);opacity:.5}50%{transform:translateY(-6px);opacity:1}}
@media (prefers-reduced-motion:reduce){.si-ld-ring,.si-ld-dots span{animation:none}}
</style>
<div id="si-loader" role="status" aria-live="polite" aria-label="Loading School Inspection">
    <div class="si-ld-logo"><span class="si-ld-ring"></span><img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha"></div>
    <p class="si-ld-title">School Inspection</p>
    <p class="si-ld-sub">Samagra Shiksha, Assam</p>
    <p class="si-ld-msg" data-loader-msg></p>
    <div class="si-ld-dots" aria-hidden="true"><span></span><span></span><span></span></div>
</div>
<div id="si-topbar" aria-hidden="true"></div>
<div id="si-net-banner" class="si-net-banner" role="status" aria-live="polite" hidden></div>
<script>
(function () {
    var el = document.getElementById('si-loader'), msg = el.querySelector('[data-loader-msg]'), started = Date.now(), ss = null;
    try { ss = window.sessionStorage; } catch (e) {}
    var get = function (k) { try { return ss && ss.getItem(k); } catch (e) { return null; } };
    var set = function (k, v) { try { ss && (v === null ? ss.removeItem(k) : ss.setItem(k, v)); } catch (e) {} };
    // Handoff from the previous page: one continuous loader instead of hide + show again (the "double load")
    var handoff = get('si-loader-handoff');
    if (handoff) { el.classList.add('is-instant'); msg.textContent = handoff === '1' ? '' : handoff; set('si-loader-handoff', null); }
    window.SI_LOADER = {
        hide: function () { el.classList.add('is-hidden'); },
        show: function (text) {
            msg.textContent = text || '';
            el.classList.remove('is-hidden'); el.classList.add('is-instant');
            set('si-loader-handoff', text || '1');
        },
        slowLoad: get('si-slow') === '1'
    };
    var slow = function () { window.SI_LOADER.slowLoad = true; set('si-slow', '1'); document.dispatchEvent(new Event('si-slow-load')); };
    var safety = setTimeout(function () { slow(); window.SI_LOADER.hide(); }, 12000);
    window.addEventListener('load', function () {
        clearTimeout(safety);
        if (Date.now() - started > 8000) slow(); else { window.SI_LOADER.slowLoad = false; set('si-slow', null); }
        window.SI_LOADER.hide();
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { window.SI_LOADER.hide(); set('si-loader-handoff', null); } });
})();
</script>
