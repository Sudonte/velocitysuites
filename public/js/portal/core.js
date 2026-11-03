/*
 * Velocity Suites guest web - shared helpers (VS).
 *
 * Every guest page talks to the SAME /api/* endpoints the Android app uses, so totals, promos, ID discount, payment limits,
 * cancel/delete rules and receipts are the app's own rules - nothing is calculated twice. This file holds only plumbing:
 * the API client, formatting, toasts, confirmation dialogs and double-submit protection.
 */
(function () {
    'use strict';

    var VS = window.VS = window.VS || {};

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    };

    VS.token = function () { return meta('portal-token'); };
    VS.csrf = function () { return meta('csrf-token'); };
    VS.reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---- text and numbers ----

    VS.esc = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /** ₱1,234.00 - the one money format. */
    VS.money = function (n) {
        var v = Number(n);
        if (!isFinite(v)) v = 0;
        var s = Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (v < 0 ? '−' : '') + '₱' + s;
    };

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** "Oct 28, 2026" from a date or an ISO instant (instants are read in Manila time, the hotel's clock). */
    VS.date = function (value) {
        if (!value) return '';
        var s = String(value);
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (m) return MONTHS[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
        var d = new Date(s);
        if (isNaN(d)) return s;
        var p = manilaParts(d);
        return MONTHS[p.month - 1] + ' ' + p.day + ', ' + p.year;
    };

    VS.dateTime = function (value) {
        if (!value) return '';
        var d = new Date(String(value));
        if (isNaN(d)) return String(value);
        var p = manilaParts(d);
        var h = p.hour % 12 || 12;
        return MONTHS[p.month - 1] + ' ' + p.day + ', ' + p.year + ' · ' + h + ':' + String(p.minute).padStart(2, '0') + ' ' + (p.hour < 12 ? 'AM' : 'PM');
    };

    function manilaParts(d) {
        var f = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour12: false, year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric' });
        var o = {};
        f.formatToParts(d).forEach(function (x) { o[x.type] = +x.value; });
        if (o.hour === 24) o.hour = 0;
        return o;
    }

    /** Today in the hotel's time zone, as YYYY-MM-DD. */
    VS.today = function () {
        var p = manilaParts(new Date());
        return p.year + '-' + String(p.month).padStart(2, '0') + '-' + String(p.day).padStart(2, '0');
    };

    VS.addDays = function (iso, n) {
        var d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    };

    VS.nights = function (a, b) {
        return Math.round((new Date(b + 'T00:00:00Z') - new Date(a + 'T00:00:00Z')) / 86400000);
    };

    VS.plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); };

    // ---- the API ----

    /**
     * VS.api('/guest/bookings', { method: 'POST', body: {...} | FormData, query: {...} })
     * Resolves { ok, status, data, message, errors }; never throws for an HTTP error (a network failure resolves status 0).
     */
    VS.api = function (path, opts) {
        opts = opts || {};
        var url = '/api' + path;
        if (opts.query) {
            var qs = Object.keys(opts.query).filter(function (k) { return opts.query[k] !== '' && opts.query[k] != null; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); }).join('&');
            if (qs) url += (url.indexOf('?') < 0 ? '?' : '&') + qs;
        }
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var token = VS.token();
        if (token) headers['Authorization'] = 'Bearer ' + token;
        var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (opts.body instanceof FormData) {
            init.body = opts.body;
        } else if (opts.body != null) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                var errors = data && data.errors ? data.errors : null;
                var message = data && data.message ? data.message : '';
                if (errors && !message) message = firstError(errors);
                if (res.status === 422 && errors) message = firstError(errors) || message;
                if (!res.ok && !message) message = res.status >= 500 ? 'Something went wrong on our side. Please try again later.' : 'We could not complete that. Please try again.';
                if (res.status === 401 && token && !opts.quiet401) {
                    VS.sessionExpired();
                }
                return { ok: res.ok, status: res.status, data: data, message: message, errors: errors, code: data && data.code };
            });
        }).catch(function () {
            return { ok: false, status: 0, data: null, message: 'No connection. Check your internet and try again.', errors: null };
        });
    };

    function firstError(errors) {
        var k = Object.keys(errors)[0];
        return k ? [].concat(errors[k])[0] : '';
    }

    VS.sessionExpired = function () {
        if (VS._expiring) return;
        VS._expiring = true;
        window.location.href = '/login?expired=1';
    };

    // ---- feedback: toasts and confirmation ----

    function host() {
        var h = document.getElementById('vs-toasts');
        if (!h) {
            h = document.createElement('div');
            h.id = 'vs-toasts';
            h.className = 'vs-toasts';
            h.setAttribute('aria-live', 'polite');
            document.body.appendChild(h);
        }
        return h;
    }

    VS.toast = function (message, type) {
        var t = document.createElement('div');
        t.className = 'vs-toast vs-toast-' + (type || 'info');
        t.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        t.innerHTML = '<i class="fas ' + ({ success: 'fa-circle-check', danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation' }[type] || 'fa-circle-info') + '"></i><span>' + VS.esc(message) + '</span>';
        host().appendChild(t);
        setTimeout(function () { t.classList.add('vs-toast-out'); setTimeout(function () { t.remove(); }, 300); }, type === 'danger' ? 6000 : 3500);
    };

    /** VS.confirm({ title, message, confirmText, cancelText, danger }) -> Promise<boolean>. */
    VS.confirm = function (o) {
        return new Promise(function (resolve) {
            var wrap = document.createElement('div');
            wrap.className = 'modal fade';
            wrap.tabIndex = -1;
            wrap.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content vs-modal">' +
                '<div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold">' + VS.esc(o.title || 'Are you sure?') + '</h2></div>' +
                '<div class="modal-body">' + (o.html || '<p class="mb-0">' + VS.esc(o.message || '') + '</p>') + '</div>' +
                '<div class="modal-footer border-0 pt-0">' +
                '<button type="button" class="btn btn-light" data-act="no">' + VS.esc(o.cancelText || 'Cancel') + '</button>' +
                '<button type="button" class="btn ' + (o.danger ? 'btn-danger' : 'btn-velocity') + '" data-act="yes">' + VS.esc(o.confirmText || 'Confirm') + '</button>' +
                '</div></div></div>';
            document.body.appendChild(wrap);
            var modal = new bootstrap.Modal(wrap, { backdrop: 'static' });
            var answer = false;
            wrap.addEventListener('click', function (e) {
                var b = e.target.closest('[data-act]');
                if (!b) return;
                answer = b.getAttribute('data-act') === 'yes';
                modal.hide();
            });
            wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); resolve(answer); });
            modal.show();
        });
    };

    /** A message dialog with one button (success / information). */
    VS.notice = function (o) {
        return VS.confirm({ title: o.title, message: o.message, html: o.html, confirmText: o.button || 'OK', cancelText: 'Close' }).then(function () { return true; });
    };

    // ---- forms ----

    /**
     * Double-submit protection: runs fn once while it is pending; the button shows a spinner and is disabled.
     * Usage: VS.busy(button, function () { return VS.api(...); }).then(...)
     */
    VS.busy = function (btn, fn) {
        if (btn && btn.dataset.busy === '1') return Promise.resolve(null);
        var label = btn ? btn.innerHTML : '';
        if (btn) {
            btn.dataset.busy = '1';
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + (btn.dataset.busyText || 'Please wait');
        }
        var done = function () {
            if (btn) { btn.dataset.busy = '0'; btn.disabled = false; btn.innerHTML = label; }
        };
        return Promise.resolve().then(fn).then(function (r) { done(); return r; }, function (e) { done(); throw e; });
    };

    /** Shows an inline error under a field (by element or id) and marks it invalid; VS.clearErrors(form) removes them. */
    VS.fieldError = function (el, message) {
        if (typeof el === 'string') el = document.getElementById(el);
        if (!el) return;
        el.classList.add('is-invalid');
        var fb = el.nextElementSibling && el.nextElementSibling.classList.contains('vs-fb') ? el.nextElementSibling : null;
        if (!fb && el.parentElement && el.parentElement.querySelector(':scope > .vs-fb')) fb = el.parentElement.querySelector(':scope > .vs-fb');
        if (!fb) { fb = document.createElement('div'); fb.className = 'invalid-feedback vs-fb'; el.insertAdjacentElement('afterend', fb); }
        fb.textContent = message;
        fb.style.display = 'block';
    };

    VS.clearErrors = function (root) {
        (root || document).querySelectorAll('.is-invalid').forEach(function (e) { e.classList.remove('is-invalid'); });
        (root || document).querySelectorAll('.vs-fb').forEach(function (e) { e.remove(); });
    };

    VS.alertBox = function (target, message, type) {
        if (typeof target === 'string') target = document.getElementById(target);
        if (!target) return;
        if (!message) { target.innerHTML = ''; return; }
        target.innerHTML = '<div class="alert alert-' + (type || 'danger') + ' vs-alert" role="alert">' + VS.esc(message) + '</div>';
    };

    VS.debounce = function (fn, ms) {
        var t;
        return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms || 250); };
    };

    /** Skeleton block for loading states. */
    VS.skeleton = function (rows) {
        var s = '';
        for (var i = 0; i < (rows || 3); i++) s += '<div class="vs-skel mb-2" style="height:' + (i === 0 ? 22 : 14) + 'px;width:' + (100 - i * 12) + '%"></div>';
        return '<div class="vs-skel-wrap" aria-busy="true">' + s + '</div>';
    };

    VS.empty = function (icon, title, text, actionHtml) {
        return '<div class="vs-empty"><i class="fas ' + icon + '"></i><h3 class="h6 fw-bold mb-1">' + VS.esc(title) + '</h3><p class="text-muted mb-2">' + VS.esc(text || '') + '</p>' + (actionHtml || '') + '</div>';
    };

    VS.errorState = function (message, retryId) {
        return '<div class="vs-empty"><i class="fas fa-circle-exclamation"></i><h3 class="h6 fw-bold mb-1">Something went wrong</h3><p class="text-muted mb-2">' + VS.esc(message) + '</p>' +
            (retryId ? '<button class="btn btn-velocity btn-sm" id="' + retryId + '">Try again</button>' : '') + '</div>';
    };

    // ---- small bits of motion (skipped when the user prefers reduced motion) ----

    VS.reveal = function (root) {
        if (VS.reducedMotion || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('vs-in'); io.unobserve(e.target); } });
        }, { threshold: 0.08 });
        (root || document).querySelectorAll('.vs-reveal').forEach(function (el) { io.observe(el); });
    };
    document.addEventListener('DOMContentLoaded', function () {
        if (VS.reducedMotion) document.documentElement.classList.add('vs-reduced');
        VS.reveal();
    });

    VS.qs = function (name) { return new URLSearchParams(window.location.search).get(name); };
})();
