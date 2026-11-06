/*
 * One full-screen photo viewer for every room photo (admin, receptionist, guest web).
 *   VSPhotoViewer.open(photos, startIndex, { title })    photos: [url | { url, label }]
 * It shows the ORIGINAL file, whole (object-fit: contain, centered on a dark background, never stretched or cropped), honours the photo's
 * EXIF orientation, has Previous / Next (buttons, arrow keys, swipe), a counter ("2 of 4"), Esc / close, a spinner while the full image
 * loads and an "Image unavailable" placeholder when a file fails (never a broken-image icon).
 */
(function () {
    'use strict';
    if (window.VSPhotoViewer) return;

    var css = '' +
        '.vspv{position:fixed;inset:0;z-index:2000;background:#0a0a0c;display:flex;flex-direction:column;color:#fff;touch-action:pan-y;-webkit-user-select:none;user-select:none}' +
        '.vspv-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem .9rem;flex:0 0 auto}' +
        '.vspv-title{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}' +
        '.vspv-count{font-variant-numeric:tabular-nums;background:rgba(255,255,255,.14);border-radius:999px;padding:.15rem .7rem;font-size:.9rem;white-space:nowrap}' +
        '.vspv-btn{border:0;background:rgba(255,255,255,.14);color:#fff;width:2.8rem;height:2.8rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer}' +
        '.vspv-btn:hover,.vspv-btn:focus-visible{background:rgba(255,255,255,.3);outline:2px solid #fff;outline-offset:2px}' +
        '.vspv-stage{position:relative;flex:1 1 auto;min-height:0;display:flex;align-items:center;justify-content:center;padding:0 .5rem .75rem}' +
        '.vspv-img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;image-orientation:from-image;image-rendering:auto;display:block;margin:auto;opacity:0;transition:opacity .15s ease}' +
        '.vspv-img.ready{opacity:1}' +
        '.vspv-spin{position:absolute;width:2.6rem;height:2.6rem;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:vspv-r .8s linear infinite}' +
        '@keyframes vspv-r{to{transform:rotate(360deg)}}' +
        '.vspv-fail{position:absolute;display:flex;flex-direction:column;align-items:center;gap:.5rem;text-align:center;color:#d0d0d6;padding:1rem}' +
        '.vspv-fail i{font-size:3rem;opacity:.7}' +
        '.vspv-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:2}' +
        '.vspv-prev{left:.75rem}.vspv-next{right:.75rem}' +
        '.vspv[data-single="1"] .vspv-nav{display:none}' +
        '@media (max-width:575px){.vspv-nav{width:2.4rem;height:2.4rem;opacity:.85}.vspv-prev{left:.35rem}.vspv-next{right:.35rem}}' +
        '@media (prefers-reduced-motion:reduce){.vspv-img{transition:none}.vspv-spin{animation-duration:2s}}';
    var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    var current = null;

    function open(photos, start, opts) {
        if (current) current.close();
        opts = opts || {};
        var list = (photos || []).map(function (p) { return typeof p === 'string' ? { url: p, label: '' } : { url: p.url || p.image_url || '', label: p.label || p.room_label || '' }; }).filter(function (p) { return p.url; });
        if (!list.length) return null;
        var idx = Math.min(Math.max(0, start || 0), list.length - 1), opener = document.activeElement, token = 0;

        var root = document.createElement('div');
        root.className = 'vspv'; root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', (opts.title || 'Photos') + ' - full screen');
        root.setAttribute('data-single', list.length === 1 ? '1' : '0');
        root.innerHTML = '<div class="vspv-top"><div class="vspv-title">' + esc(opts.title || '') + '<span class="vspv-label small fw-normal ms-2 opacity-75"></span></div>' +
            '<div class="d-flex align-items-center gap-2"><span class="vspv-count" aria-live="polite"></span><button type="button" class="vspv-btn vspv-close" aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button></div></div>' +
            '<div class="vspv-stage"><button type="button" class="vspv-btn vspv-nav vspv-prev" aria-label="Previous photo"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>' +
            '<div class="vspv-spin" role="status" aria-label="Loading photo"></div><div class="vspv-fail" hidden><i class="fas fa-image" aria-hidden="true"></i><strong>Image unavailable</strong><span class="small">This photo could not be loaded.</span></div>' +
            '<img class="vspv-img" alt="">' +
            '<button type="button" class="vspv-btn vspv-nav vspv-next" aria-label="Next photo"><i class="fas fa-chevron-right" aria-hidden="true"></i></button></div>';
        document.body.appendChild(root);
        var prevOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
        var img = root.querySelector('.vspv-img'), spin = root.querySelector('.vspv-spin'), fail = root.querySelector('.vspv-fail'),
            count = root.querySelector('.vspv-count'), label = root.querySelector('.vspv-label');

        function show(i) {
            idx = (i + list.length) % list.length;
            var my = ++token, p = list[idx];
            count.textContent = (idx + 1) + ' of ' + list.length;
            label.textContent = p.label ? '· ' + p.label : '';
            img.classList.remove('ready'); fail.hidden = true; spin.hidden = false;
            img.alt = (opts.title || 'Room') + ' photo ' + (idx + 1);
            var probe = new Image();
            probe.onload = function () { if (my !== token) return; img.src = p.url; img.classList.add('ready'); spin.hidden = true; preload(idx + 1); preload(idx - 1); };
            probe.onerror = function () { if (my !== token) return; img.removeAttribute('src'); spin.hidden = true; fail.hidden = false; };
            probe.src = p.url;
        }
        function preload(i) { if (list.length < 2) return; var n = new Image(); n.src = list[(i + list.length) % list.length].url; }
        function step(d) { if (list.length > 1) show(idx + d); }
        function close() {
            document.removeEventListener('keydown', onKey, true); root.remove(); document.body.style.overflow = prevOverflow; current = null;
            if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* gone */ } }
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
            else if (e.key === 'Tab') { // keep focus inside the viewer
                var f = [].slice.call(root.querySelectorAll('button')).filter(function (b) { return b.offsetParent !== null; });
                if (!f.length) return; var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        document.addEventListener('keydown', onKey, true);
        root.querySelector('.vspv-close').onclick = close;
        root.querySelector('.vspv-prev').onclick = function () { step(-1); };
        root.querySelector('.vspv-next').onclick = function () { step(1); };
        root.querySelector('.vspv-stage').addEventListener('click', function (e) { if (e.target === e.currentTarget) close(); });
        // swipe on phones
        var sx = null, sy = null;
        root.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; } }, { passive: true });
        root.addEventListener('touchend', function (e) {
            if (sx == null) return; var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy; sx = null;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) step(dx < 0 ? 1 : -1);
        }, { passive: true });
        show(idx);
        root.querySelector('.vspv-close').focus();
        current = { close: close, step: step, show: show };
        return current;
    }

    window.VSPhotoViewer = { open: open, close: function () { if (current) current.close(); } };
})();
