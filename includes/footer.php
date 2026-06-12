<?php
// footer.php — Closing scripts included at the bottom of every admin page.
$_bs_js_local = $GLOBALS['_bootstrap_js_local'] ?? file_exists(dirname(__DIR__) . '/assets/js/bootstrap.bundle.min.js');
?>
<?php if ($_bs_js_local): ?>
<script src="<?php echo BASE_URL; ?>assets/js/bootstrap.bundle.min.js"></script>
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
<script src="<?php echo BASE_URL; ?>assets/js/app.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/accessibility.js"></script>

<!-- Global phone input sync script (used by phone_input.php component) -->
<script>
function phoneInputSync(pid) {
    var sel   = document.getElementById(pid + '_country');
    var local = document.getElementById(pid + '_local');
    var full  = document.getElementById(pid + '_full');
    var hint  = document.getElementById(pid + '_hint');
    if (!sel || !local || !full) return;
    var code   = sel.value;
    var maxlen = parseInt(sel.selectedOptions[0]?.dataset?.maxlen || 15);
    var num    = local.value.replace(/\D/g, '');
    // Strip leading zero for countries where it's a trunk prefix (PH, UK, etc.)
    if (num.startsWith('0')) num = num.substring(1);
    if (num.length > maxlen) num = num.substring(0, maxlen);
    local.value = num;
    full.value  = num ? code + num : '';
    if (hint) hint.textContent = code + ' + local number without leading zero (max ' + maxlen + ' digits)';
}
// On page load, sync all phone inputs so hidden fields are pre-filled
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[id$="_wrap"].phone-input-wrap').forEach(function(wrap) {
        var pid = wrap.id.replace('_wrap', '');
        phoneInputSync(pid);
    });
});
</script>

<!-- PAGE TRANSITION — top bar only (Option D) -->
<div id="pbar" aria-hidden="true"></div>
<style>
#pbar {
    position: fixed;
    top: 0; left: 0;
    height: 3px;
    width: 0%;
    z-index: 99999;
    background: linear-gradient(90deg, #0d6e6e 0%, #14a8a8 55%, #c9a84c 100%);
    box-shadow: 0 0 8px rgba(13,110,110,0.5);
    border-radius: 0 2px 2px 0;
    opacity: 0;
    transition: width 0.35s ease, opacity 0.2s ease;
    pointer-events: none;
}
#pbar.running { opacity: 1; }
#pbar.done    { opacity: 0; width: 100% !important; transition: width 0.15s ease, opacity 0.3s ease 0.15s; }

/* ── Dark mode: fewer / simpler animations ───────────────── */
[data-theme="dark"] .animate-in,
[data-theme="dark"] .animate-in-delay-1,
[data-theme="dark"] .animate-in-delay-2,
[data-theme="dark"] .animate-in-delay-3,
[data-theme="dark"] .animate-in-delay-4 {
    animation: none !important;
    opacity: 1 !important;
    transform: none !important;
}
[data-theme="dark"] .stat-card:hover {
    transform: none !important;
    box-shadow: var(--shadow-md) !important;
}
[data-theme="dark"] .stat-card:hover .stat-icon {
    transform: none !important;
}
[data-theme="dark"] .stat-card::after {
    display: none !important;
}
[data-theme="dark"] .btn-primary:hover,
[data-theme="dark"] .btn-success:hover,
[data-theme="dark"] .btn-gold:hover,
[data-theme="dark"] .btn-warning:hover,
[data-theme="dark"] .btn-login:hover,
[data-theme="dark"] .btn-welcome-primary:hover,
[data-theme="dark"] .btn-welcome-outline:hover {
    transform: none !important;
}
[data-theme="dark"] .sidebar-brand-icon {
    transition: none !important;
}
[data-theme="dark"] #sidebar:hover .sidebar-brand-icon {
    transform: none !important;
}
[data-theme="dark"] .notif-dropdown.show {
    animation: none !important;
}
[data-theme="dark"] html.theme-transitioning,
[data-theme="dark"] html.theme-transitioning *,
[data-theme="dark"] html.theme-transitioning *::before,
[data-theme="dark"] html.theme-transitioning *::after {
    transition: background-color 0.15s ease, color 0.15s ease,
                border-color 0.15s ease !important;
}
</style>

<script>
(function () {
    var bar = document.getElementById('pbar');
    if (!bar) return;
    var w = 0, timer, safe;

    function start() {
        clearInterval(timer); clearTimeout(safe);
        bar.classList.remove('done');
        bar.classList.add('running');
        w = 0; bar.style.width = '0%';
        timer = setInterval(function () {
            w += w < 30 ? 5 : w < 60 ? 2.5 : w < 80 ? 1 : 0.3;
            bar.style.width = Math.min(w, 85) + '%';
            if (w >= 85) clearInterval(timer);
        }, 55);
        safe = setTimeout(finish, 9000);
    }

    function finish() {
        clearInterval(timer); clearTimeout(safe);
        bar.classList.add('done');
        setTimeout(function () {
            bar.classList.remove('running', 'done');
            bar.style.width = '0%';
        }, 480);
    }

    document.addEventListener('click', function (e) {
        var el = e.target;
        while (el && el.tagName !== 'A') el = el.parentElement;
        if (!el) return;
        var href = el.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript')
            || href.includes('logout') || href.includes('print/')
            || el.getAttribute('target') === '_blank'
            || el.hasAttribute('data-bs-toggle')
            || el.hasAttribute('data-bs-dismiss')
            || el.classList.contains('page-link')) return;
        try { if (new URL(href, location.href).origin !== location.origin) return; }
        catch(e) { return; }
        start();
    });

    document.addEventListener('submit', function (e) {
        var a = e.target.getAttribute('action') || '';
        if (!a.startsWith('#') && !e.target.getAttribute('data-no-loader')) start();
    });

    window.addEventListener('pageshow', finish);
    window.addEventListener('popstate', finish);
})();
</script>
