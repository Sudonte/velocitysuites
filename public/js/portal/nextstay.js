/*
 * "Your next stay" and "Stay calendar" for the guest dashboard - the app's own rules (NextStay, NextStayPeriods, StayCalendarModel):
 * days are hotel-local calendar dates; a transaction covers every day from its check-in to its check-out (both included); cancelled and
 * rejected ones are left out (the calendar can show them); a week starts on Sunday; each transaction is listed once per period.
 */
(function () {
    'use strict';
    var VS = window.VS, S = window.VSStay;
    var N = window.VSNextStay = {};
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // ------------------------------------------------------------------ dates (ISO yyyy-mm-dd, no time zones involved)
    function utc(iso) { var p = String(iso).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function iso(d) { return d.toISOString().slice(0, 10); }
    function add(isoDay, n) { var d = utc(isoDay); d.setUTCDate(d.getUTCDate() + n); return iso(d); }
    function diff(a, b) { return Math.round((utc(b) - utc(a)) / 86400000); }
    function weekStart(day) { return add(day, -utc(day).getUTCDay()); }
    function monthStart(day) { return day.slice(0, 7) + '-01'; }
    function monthEnd(day) { var d = utc(monthStart(day)); d.setUTCMonth(d.getUTCMonth() + 1); d.setUTCDate(0); return iso(d); }
    function addMonths(day, n) { var d = utc(monthStart(day)); d.setUTCMonth(d.getUTCMonth() + n); return iso(d); }
    function windowStart(mode, day) { return mode === 'weekly' ? weekStart(day) : mode === 'monthly' ? monthStart(day) : day; }
    function windowEnd(mode, day) { return mode === 'weekly' ? add(weekStart(day), 6) : mode === 'monthly' ? monthEnd(day) : day; }
    function shortMD(day) { var d = utc(day); return MONTHS[d.getUTCMonth()].slice(0, 3) + ' ' + d.getUTCDate(); }
    function weekRange(day) {
        var s = weekStart(day), e = add(s, 6);
        return s.slice(0, 7) === e.slice(0, 7) ? shortMD(s) + '–' + utc(e).getUTCDate() : shortMD(s) + '–' + shortMD(e);
    }
    function monthLong(day) { return MONTHS[+day.slice(5, 7) - 1] + ' ' + day.slice(0, 4); }
    function monthShort(day) { return MONTHS[+day.slice(5, 7) - 1].slice(0, 3) + ' ' + day.slice(0, 4); }
    function periodOffset(mode, anchor, today) {
        if (mode === 'weekly') return Math.round(diff(weekStart(today), weekStart(anchor)) / 7);
        var a = monthStart(anchor), t = monthStart(today);
        return (+a.slice(0, 4) - +t.slice(0, 4)) * 12 + (+a.slice(5, 7) - +t.slice(5, 7));
    }

    // ------------------------------------------------------------------ the transactions
    function span(x) {
        var it = x.it, st = it.stays && it.stays.length ? it.stays : null;
        if (!st) { var a = String(it.check_in).slice(0, 10), b = String(it.check_out).slice(0, 10); return { in: a, out: b < a ? a : b }; }
        var lo = st[0].check_in, hi = st[0].check_out;
        st.forEach(function (s) { if (s.check_in < lo) lo = s.check_in; if (s.check_out > hi) hi = s.check_out; });
        return { in: String(lo).slice(0, 10), out: String(hi).slice(0, 10) };
    }
    function key(x) { return S.status(x.it, x.kind).key; }
    function closed(x) { return /cancelled|rejected/.test(key(x)); }
    function label(x) { return S.idLabel(x.it, x.kind); }
    function byInHouseThenId(a, b) {
        var ah = key(a) === 'checkedin' ? 0 : 1, bh = key(b) === 'checkedin' ? 0 : 1;
        return ah - bh || (label(a) < label(b) ? -1 : label(a) > label(b) ? 1 : 0);
    }
    /** Every open transaction whose stay covers the day. */
    function forDay(items, day) {
        return items.filter(function (x) { if (closed(x)) return false; var s = span(x); return day >= s.in && day <= s.out; }).sort(byInHouseThenId);
    }
    /** Every transaction overlapping the window once, nearest check-in first (cancelled ones only when asked). */
    function forWindow(items, mode, anchor, includeClosed) {
        var a = windowStart(mode, anchor), b = windowEnd(mode, anchor), seen = {};
        return items.filter(function (x) {
            if (!includeClosed && closed(x)) return false;
            var s = span(x), k = x.kind + ':' + x.it.id;
            if (s.out < a || s.in > b || seen[k]) return false;
            seen[k] = 1; return true;
        }).sort(function (x, y) { var p = span(x).in, q = span(y).in; return p < q ? -1 : p > q ? 1 : (label(x) < label(y) ? -1 : 1); });
    }
    /** Today when it has a transaction; else the nearest later day that has one (120 days ahead); else null. */
    function nearestStayDay(items, today) {
        if (forDay(items, today).length) return today;
        for (var i = 1; i <= 120; i++) { var d = add(today, i); if (forDay(items, d).length) return d; }
        return null;
    }
    N.forDay = forDay; N.forWindow = forWindow; N.nearestStayDay = nearestStayDay; N.span = span;

    // ------------------------------------------------------------------ words (the app's)
    function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }
    function dayLine(selected, today, nearest, staying) {
        if (staying) return 'You’re staying with us';
        var d = diff(today, selected);
        if (nearest && d >= 0) return d === 0 ? 'Your next stay · today' : d === 1 ? 'Your next stay · tomorrow' : 'Your next stay · in ' + plural(d, 'day');
        if (d === 0) return 'Today'; if (d === 1) return 'Tomorrow'; if (d === -1) return 'Yesterday';
        return d > 1 ? 'In ' + plural(d, 'day') : plural(-d, 'day') + ' ago';
    }
    function periodTitle(mode, start, today) {
        if (mode === 'weekly') { var o = periodOffset(mode, start, today); return o === 0 ? 'This week' : o === 1 ? 'Next week' : o === -1 ? 'Last week' : weekRange(start); }
        return monthShort(start);
    }
    function periodSub(mode, start, today) {
        var o = periodOffset(mode, start, today);
        if (mode === 'weekly') return o >= -1 && o <= 1 ? weekRange(start) : add(start, 6).slice(0, 4);
        return o === 0 ? 'This month' : o === 1 ? 'Next month' : o === -1 ? 'Last month' : '';
    }
    function periodLabel(mode, anchor, today) { return mode === 'weekly' ? periodTitle(mode, weekStart(anchor), today) : monthLong(anchor); }
    function jumpLabel(mode) { return mode === 'weekly' ? 'This week' : mode === 'monthly' ? 'This month' : 'Today'; }

    // ------------------------------------------------------------------ the stay card (same on both)
    function guestsText(it) {
        var a = Number(it.adults || 0), c = Number(it.children || 0), n = a + c || Number(it.number_of_guests || 0);
        return n ? plural(n, 'guest') : '';
    }
    function card(x, today) {
        var it = x.it, st = S.status(it, x.kind), m = S.money(it), sp = span(x), k = st.key;
        var href = '/guest/' + (x.kind === 'reservation' ? 'reservations' : 'bookings') + '/' + it.id;
        var when = k === 'checkedin' ? (diff(today, sp.out) < 0 ? 'Check-out was due ' + VS.date(sp.out) : diff(today, sp.out) === 0 ? 'Checking out today' : 'Checking out ' + (diff(today, sp.out) === 1 ? 'tomorrow' : 'in ' + plural(diff(today, sp.out), 'day')))
            : /completed|noshow/.test(k) ? '' : (diff(today, sp.in) === 0 ? 'Check-in today' : diff(today, sp.in) === 1 ? 'Check-in tomorrow' : diff(today, sp.in) > 1 ? 'Check-in in ' + plural(diff(today, sp.in), 'day') : '');
        var rooms = S.firstRoomTypes(it), guests = guestsText(it);
        return '<a class="vs-card vs-nscard text-decoration-none" href="' + href + '" aria-label="' + VS.esc(label(x)) + ', ' + VS.esc(st.label) + '">' +
            '<div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold">' + VS.esc(label(x)) + '</div><div class="small text-muted">' + VS.esc(S.dates(it)) + '</div></div><span class="vs-chip ' + st.cls + '">' + VS.esc(st.label) + '</span></div>' +
            '<div class="vs-facts mt-2">' + (rooms ? '<div><i class="fas fa-bed" aria-hidden="true"></i><span>' + VS.esc(rooms) + '</span></div>' : '') + (guests ? '<div><i class="fas fa-user-group" aria-hidden="true"></i><span>' + VS.esc(guests) + '</span></div>' : '') + '</div>' +
            (when ? '<div class="vs-countdown mt-2"><i class="fas fa-hourglass-half" aria-hidden="true"></i> ' + VS.esc(when) + '</div>' : '') +
            '<div class="vs-row mt-2"><span>Balance</span><span class="vs-num fw-bold">' + (m.remaining > 0.009 ? VS.money(m.remaining) : 'Paid') + '</span></div></a>';
    }

    function chipHtml(attrs, title, sub, count, selected, current) {
        return '<button type="button" class="vs-chipday' + (selected ? ' sel' : '') + (current ? ' cur' : '') + '" ' + attrs + ' aria-pressed="' + selected + '" aria-label="' + VS.esc(title + (sub ? ', ' + sub : '') + ', ' + plural(count, 'transaction')) + '">' +
            '<span class="t">' + VS.esc(title) + '</span><span class="s">' + VS.esc(sub || ' ') + '</span><span class="n' + (count ? '' : ' zero') + '">' + count + '</span></button>';
    }
    function modeButtons(mode) {
        return '<div class="btn-group" role="group" aria-label="Period">' + [['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']].map(function (m) {
            return '<button type="button" class="btn btn-sm ' + (mode === m[0] ? 'btn-velocity' : 'btn-outline-velocity') + '" data-mode="' + m[0] + '" aria-pressed="' + (mode === m[0]) + '">' + m[1] + '</button>';
        }).join('') + '</div>';
    }
    function keepMode(k) { try { var v = localStorage.getItem(k); return /^(daily|weekly|monthly)$/.test(v || '') ? v : 'daily'; } catch (e) { return 'daily'; } }
    function saveMode(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage off */ } }

    // ------------------------------------------------------------------ Your next stay
    N.nextStay = function (host, items, today) {
        var mode = keepMode('vs_ns_mode'), sel = null, scrollTo = true;
        function days() {
            var first = add(today, -14), last = add(today, 30);
            items.forEach(function (x) {
                if (closed(x)) return; var s = span(x);
                if (s.in < first && s.in > add(today, -60)) first = s.in;
                if (s.out > last && s.out < add(today, 120)) last = s.out;
            });
            var out = []; for (var d = first; d <= last; d = add(d, 1)) out.push(d); return out;
        }
        function starts() {
            var weekly = mode === 'weekly', first = weekly ? add(today, -28) : addMonths(today, -3), last = weekly ? add(today, 56) : addMonths(today, 6);
            items.forEach(function (x) {
                if (closed(x)) return; var s = span(x);
                if (s.in < first && s.in > add(today, -400)) first = s.in;
                if (s.out > last && s.out < add(today, 400)) last = s.out;
            });
            var out = [], end = windowStart(mode, last);
            for (var d = windowStart(mode, first); d <= end; d = weekly ? add(d, 7) : addMonths(d, 1)) out.push(d);
            return out;
        }
        function paint() {
            if (!sel) sel = nearestStayDay(items, today) || today;
            var list, strip, ctx, current;
            if (mode === 'daily') {
                var ds = days(); if (sel < ds[0]) sel = ds[0]; if (sel > ds[ds.length - 1]) sel = ds[ds.length - 1];
                strip = ds.map(function (d) {
                    var diffd = diff(today, d), friendly = diffd >= -1 && diffd <= 1, n = forDay(items, d).length;
                    var title = friendly ? (diffd === 0 ? 'Today' : diffd === 1 ? 'Tomorrow' : 'Yesterday') : WEEKDAYS[utc(d).getUTCDay()];
                    return chipHtml('data-d="' + d + '"', title, shortMD(d), n, d === sel, diffd === 0);
                }).join('');
                list = forDay(items, sel);
                ctx = dayLine(sel, today, sel === nearestStayDay(items, today), list.some(function (x) { return key(x) === 'checkedin'; }));
                current = sel === today;
            } else {
                var selStart = windowStart(mode, sel);
                strip = starts().map(function (s0) {
                    var n = forWindow(items, mode, s0).length;
                    return chipHtml('data-d="' + s0 + '"', periodTitle(mode, s0, today), periodSub(mode, s0, today), n, s0 === selStart, periodOffset(mode, s0, today) === 0);
                }).join('');
                list = forWindow(items, mode, sel);
                ctx = periodLabel(mode, sel, today) + ' · ' + plural(list.length, 'stay');
                current = periodOffset(mode, sel, today) === 0;
            }
            host.innerHTML = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' + modeButtons(mode) + '</div>' +
                '<div class="vs-strip" role="group" aria-label="' + (mode === 'daily' ? 'Days' : mode === 'weekly' ? 'Weeks' : 'Months') + '" tabindex="0">' + strip + '</div>' +
                '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 my-2"><div class="fw-semibold" aria-live="polite" id="vsNsCtx">' + VS.esc(ctx) + '</div>' +
                (current ? '' : '<button type="button" class="btn btn-sm btn-outline-velocity" data-today>' + jumpLabel(mode) + '</button>') + '</div>' +
                (list.length ? '<div class="vs-nscards">' + list.map(function (x) { return card(x, today); }).join('') + '</div>'
                    : '<p class="text-muted mb-0">' + (mode === 'daily' ? 'Nothing booked for this day. Pick another day above.' : 'Nothing booked in this period. Pick another above.') + '</p>');
            host.querySelectorAll('[data-mode]').forEach(function (b) { b.onclick = function () { mode = b.dataset.mode; saveMode('vs_ns_mode', mode); scrollTo = true; paint(); }; });
            host.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { sel = b.dataset.d; paint(); }; });
            var t = host.querySelector('[data-today]'); if (t) t.onclick = function () { sel = today; scrollTo = true; paint(); };
            var scroller = host.querySelector('.vs-strip'), chip = scroller.querySelector('.sel');
            if (scrollTo && chip) { scroller.scrollLeft = Math.max(0, chip.offsetLeft - scroller.clientWidth / 2 + chip.offsetWidth / 2); scrollTo = false; }
            else if (host._scroll != null) scroller.scrollLeft = host._scroll;
            scroller.addEventListener('scroll', function () { host._scroll = scroller.scrollLeft; }, { passive: true });
        }
        paint();
        return { select: function (d) { sel = d; paint(); } };
    };

    // ------------------------------------------------------------------ Stay calendar
    N.calendar = function (host, items, today, opts) {
        opts = opts || {};
        var mode = keepMode('vs_cal_mode'), sel = today, month = monthStart(today), showClosed = false;
        // a day gets a dot when a stay arrives or leaves on it; every covered day is shaded
        function dots(d) { return items.some(function (x) { if (!showClosed && closed(x)) return false; var s = span(x); return s.in === d || s.out === d; }); }
        function covered(d) { return items.some(function (x) { if (!showClosed && closed(x)) return false; var s = span(x); return d >= s.in && d <= s.out; }); }
        function paint() {
            var a = windowStart(mode, sel), b = windowEnd(mode, sel);
            var first = utc(month), startPad = first.getUTCDay(), len = +monthEnd(month).slice(8), cells = '';
            for (var i = 0; i < startPad; i++) cells += '<div></div>';
            for (var d = 1; d <= len; d++) {
                var day = month.slice(0, 7) + '-' + String(d).padStart(2, '0'), inWin = day >= a && day <= b;
                cells += '<button type="button" class="vs-calday' + (day === sel ? ' sel' : '') + (inWin ? ' win' : '') + (day === today ? ' today' : '') + (covered(day) ? ' stay' : '') + '" data-day="' + day + '" aria-pressed="' + (day === sel) + '" aria-label="' + VS.esc(VS.date(day) + (dots(day) ? ', has check-in or check-out' : '')) + '">' + d + (dots(day) ? '<i class="vs-dot" aria-hidden="true"></i>' : '') + '</button>';
            }
            var list = forWindow(items, mode, sel, showClosed);
            host.innerHTML = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' + modeButtons(mode) +
                '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="vsShowCx"' + (showClosed ? ' checked' : '') + '><label class="form-check-label small" for="vsShowCx">Show cancelled</label></div></div>' +
                '<div class="d-flex justify-content-between align-items-center mb-1"><button type="button" class="btn btn-sm btn-light" data-prev aria-label="Previous month">‹</button><strong>' + monthLong(month) + '</strong><button type="button" class="btn btn-sm btn-light" data-nextm aria-label="Next month">›</button></div>' +
                '<div class="vs-cal" role="group" aria-label="' + monthLong(month) + '">' + WEEKDAYS.map(function (w) { return '<div class="text-muted small text-center">' + w.charAt(0) + '</div>'; }).join('') + cells + '</div>' +
                '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 mb-2"><div class="fw-semibold" aria-live="polite">' + VS.esc(mode === 'daily' ? VS.date(sel) : periodLabel(mode, sel, today)) + ' · ' + plural(list.length, 'transaction') + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-velocity" data-today>' + jumpLabel(mode) + '</button></div>' +
                (list.length ? '<div class="vs-nscards">' + list.map(function (x) { return card(x, today); }).join('') + '</div>' : '<p class="text-muted mb-0">Nothing scheduled in this period.</p>');
            host.querySelectorAll('[data-mode]').forEach(function (bt) { bt.onclick = function () { mode = bt.dataset.mode; saveMode('vs_cal_mode', mode); paint(); }; });
            host.querySelectorAll('[data-day]').forEach(function (bt) { bt.onclick = function () { sel = bt.dataset.day; paint(); }; });
            host.querySelector('[data-prev]').onclick = function () { month = addMonths(month, -1); paint(); };
            host.querySelector('[data-nextm]').onclick = function () { month = addMonths(month, 1); paint(); };
            host.querySelector('[data-today]').onclick = function () { sel = today; month = monthStart(today); paint(); };
            host.querySelector('#vsShowCx').onchange = function (e) { showClosed = e.target.checked; paint(); };
        }
        paint();
    };
})();
