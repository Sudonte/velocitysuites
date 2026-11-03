/*
 * My stays (the list) and Booking / Reservation details on the web. Everything shown comes from the app's own endpoints
 * (GET /api/guest/reservations, /api/guest/bookings and their show endpoints): the grand total, paid, waiting and remaining figures
 * are the server's payment_summary, the same numbers the app shows. Paid means verified only.
 */
(function () {
    'use strict';
    var VS = window.VS;
    var S = window.VSStay = {};

    // ------------------------------------------------------------------ status and money

    S.status = function (it, kind) {
        var b = kind === 'reservation' ? it.booking : it;
        var bs = b ? b.booking_status : null;
        var rs = kind === 'reservation' ? it.status : null;
        var ps = it.payment_summary || {};
        var waiting = Number(ps.waiting_for_verification || 0) > 0.009;
        if (rs === 'CANCELLED_RESERVATION' || bs === 'CANCELLED_BOOKING') return { label: 'Cancelled', cls: 'bad', key: 'cancelled' };
        if (rs === 'REJECTED_RESERVATION') return { label: 'Rejected', cls: 'bad', key: 'rejected' };
        if (bs === 'NO_SHOW_BOOKING') return { label: 'No-show', cls: 'gray', key: 'noshow' };
        if (bs === 'COMPLETED_BOOKING') return { label: 'Completed', cls: 'gray', key: 'completed' };
        if (bs === 'CHECKED_IN') return { label: 'Checked in', cls: 'ok', key: 'checkedin' };
        if (bs === 'ACTIVE_BOOKING') {
            if (!b.verified_at) return { label: 'Pending', cls: 'wait', key: 'pending', note: 'Waiting for front desk verification' };
            return { label: 'Confirmed', cls: 'ok', key: 'confirmed' };
        }
        if (rs === 'CONVERTED_TO_BOOKING') return { label: 'Confirmed', cls: 'ok', key: 'confirmed' };
        return { label: 'Pending', cls: 'wait', key: 'pending', note: waiting ? 'Waiting for front desk verification' : null };
    };

    S.money = function (it) {
        var ps = it.payment_summary || {};
        var total = Number(ps.grand_total != null ? ps.grand_total : (it.total_amount_due || 0));
        var paid = Number(ps.total_amount_paid || 0), waiting = Number(ps.waiting_for_verification || 0);
        var remaining = ps.remaining_balance != null ? Number(ps.remaining_balance) : Math.max(0, total - paid);
        return { total: total, paid: paid, waiting: waiting, remaining: Math.max(0, remaining) };
    };

    S.firstRoomTypes = function (it) {
        var set = {};
        (it.stays || []).forEach(function (s) { (s.rooms || []).forEach(function (r) { set[r.room_type] = (set[r.room_type] || 0) + r.quantity; }); });
        return Object.keys(set).map(function (k) { return k + ' × ' + set[k]; }).join(', ');
    };

    function idLabel(it, kind) {
        if (kind === 'reservation') return 'Reservation #' + it.id;
        return 'Booking #' + it.id;
    }
    function dates(it) {
        var st = it.stays && it.stays.length ? it.stays : null;
        if (!st) return VS.date(String(it.check_in).slice(0, 10)) + ' → ' + VS.date(String(it.check_out).slice(0, 10));
        if (st.length === 1) return VS.date(st[0].check_in) + ' → ' + VS.date(st[0].check_out) + ' · ' + VS.plural(st[0].nights, 'night');
        return VS.plural(st.length, 'date range') + ' · ' + VS.date(st[0].check_in) + ' → ' + VS.date(st[st.length - 1].check_out);
    }
    function nextCheckIn(it) { return (it.stays && it.stays[0] ? it.stays[0].check_in : String(it.check_in).slice(0, 10)); }
    S.dates = dates; S.idLabel = idLabel;

    // ------------------------------------------------------------------ the list

    function loadAll() {
        return Promise.all([
            VS.api('/guest/reservations', { query: { per_page: 200 } }),
            VS.api('/guest/bookings', { query: { per_page: 200 } })
        ]).then(function (rs) {
            var items = [], failed = [];
            if (rs[0].ok) (rs[0].data.data || []).forEach(function (r) { items.push({ kind: 'reservation', it: r }); }); else failed.push(rs[0].message);
            if (rs[1].ok) (rs[1].data.data || []).forEach(function (b) { items.push({ kind: 'booking', it: b }); }); else failed.push(rs[1].message);
            return { items: items, error: failed.length === 2 ? failed[0] : null, partial: failed.length === 1 };
        });
    }
    S.loadAll = loadAll;

    // ------------------------------------------------------------------ the app's Bookings / Reservations split
    // The same rules as the app (ApiMapper.displayStatus + TransactionCategorizer): a reservation that has become a booking is a
    // Booking, and also stays behind as a finished (frozen) Reservation; Active also holds stays that are still to come.

    function appStatus(x) {
        var it = x.it, b = x.kind === 'reservation' ? it.booking : it, bs = b ? b.booking_status : null, rs = x.kind === 'reservation' ? it.status : null;
        if (bs) return { ACTIVE_BOOKING: 'Confirmed', CHECKED_IN: 'Checked-In', COMPLETED_BOOKING: 'Checked-Out', CANCELLED_BOOKING: 'Cancelled' }[bs] || bs;
        if (!rs) return 'Pending';
        return { REJECTED_RESERVATION: 'Rejected', CANCELLED_RESERVATION: 'Cancelled', CONVERTED_TO_BOOKING: 'Confirmed' }[rs] || 'Pending';
    }
    /** The app's ProfileCompleteness: name, email, mobile, gender, birth date and photo; and the "Add your ..." line. */
    S.completeness = function (u, g) {
        u = u || {}; g = g || {};
        var has = function (v) { return v != null && String(v).trim() !== ''; };
        var list = [['your name', has(u.first_name) && has(u.last_name)], ['your email', has(u.email)], ['your phone number', has(g.mobile_number || u.mobile_number)],
            ['your gender', has(g.gender || u.gender)], ['your birth date', has(g.date_of_birth || u.date_of_birth)], ['a profile photo', has(g.profile_picture || u.profile_picture)]];
        var missing = list.filter(function (f) { return !f[1]; }).map(function (f) { return f[0]; });
        var line = null;
        if (missing.length === 1) line = missing[0]; else if (missing.length === 2) line = missing[0] + ' and ' + missing[1]; else if (missing.length > 2) line = missing[0] + ', ' + missing[1] + ' and ' + (missing.length - 2) + ' more';
        return { pct: Math.floor((list.length - missing.length) * 100 / list.length), line: line ? 'Add ' + line + ' to complete your profile' : null };
    };
    /** The app's PaymentEligibility.payActionFor: NONE | PAY_NOW | UNDER_REVIEW | FRONT_DESK (and whether cash reservations say "Pay at the hotel"). */
    S.payAction = function (x) {
        var it = x.it, m = S.money(it), key = S.status(it, x.kind).key;
        if (/cancelled|rejected|completed|noshow/.test(key) || m.remaining <= 0.009) return { a: 'NONE' };
        if (m.waiting > 0.009) return { a: 'UNDER_REVIEW', text: 'Payment under review' };
        var reservation = x.kind === 'reservation' && !it.booking && it.status !== 'CONVERTED_TO_BOOKING';
        if (reservation && it.payment_method === 'gcash') return { a: 'PAY_NOW', href: '/guest/pay/reservation/' + it.id, text: 'Pay now' };
        if (reservation) return { a: 'FRONT_DESK', text: 'Pay at the hotel' };
        return { a: 'FRONT_DESK', text: 'Balance of ' + VS.money(m.remaining) + ' is paid at the front desk' };
    };
    S.appKind = function (x) { return (x.kind === 'booking' || x.it.booking) ? 'booking' : 'reservation'; };
    S.appCategory = function (x) {
        var st = appStatus(x);
        if (st === 'Cancelled' || st === 'Rejected') return 'cancelled';
        if (st === 'Checked-Out') return 'completed';
        var verified = x.kind === 'reservation' ? (x.it.booking ? x.it.booking.verified_at : x.it.verified_at) : x.it.verified_at;
        if (S.appKind(x) === 'reservation' && verified) return 'completed';
        return 'active';
    };
    /** Every row of the two tabs: { tab: 'booking' | 'reservation', cat, x, historical } */
    S.classify = function (items) {
        var rows = [];
        items.forEach(function (x) {
            rows.push({ tab: S.appKind(x), cat: S.appCategory(x), x: x, historical: false });
            if (x.kind === 'reservation' && x.it.booking && !x.it.snapshot_hidden_at) rows.push({ tab: 'reservation', cat: 'completed', x: x, historical: true });
        });
        return rows;
    };
    S.counts = function (rows) {
        var c = { booking: { active: 0, completed: 0, cancelled: 0, all: 0 }, reservation: { active: 0, completed: 0, cancelled: 0, all: 0 } };
        rows.forEach(function (r) { c[r.tab][r.cat]++; c[r.tab].all++; });
        return c;
    };
    var TAB_TEXT = {
        booking: { all: 'All bookings', active: 'Confirmed booking list', completed: 'Completed booking list', cancelled: 'Cancelled or rejected booking list', title: 'Bookings',
            none: { all: 'You have no bookings yet.', active: 'No confirmed bookings.', completed: 'No completed bookings.', cancelled: 'No cancelled or rejected bookings.' }, empty: 'No bookings found', match: 'No bookings match your filters' },
        reservation: { all: 'All reservations', active: 'Pending/confirmed reservation list', completed: 'Completed reservation list', cancelled: 'Cancelled or rejected reservation list', title: 'Reservations',
            none: { all: 'No reservations found. Your reservations will be displayed here.', active: 'No pending or confirmed reservations.', completed: 'No completed reservations.', cancelled: 'No cancelled or rejected reservations.' }, empty: 'No reservations found', match: 'No reservations match your filters' }
    };

    S.list = function (root) {
        var host = root.querySelector('#vsStayCards'), form = root.querySelector('#vsStayFilters'), all = [], rows = [];
        var qs = new URLSearchParams(window.location.search);
        var tab = qs.get('tab') === 'reservations' ? 'reservation' : 'booking';
        var state = { booking: { status: '', q: '', sort: 'newest', room: '' }, reservation: { status: '', q: '', sort: 'newest', room: '' } };
        var pre = qs.get('status'); if (/^(active|completed|cancelled)$/.test(pre || '')) state[tab].status = pre;
        var tabs = root.querySelectorAll('#vsStayTabs [data-tab]');

        function readForm() { var f = form.elements; state[tab] = { status: f.status.value, q: f.q.value, sort: f.sort.value, room: f.room.value }; }
        function writeForm() {
            var f = form.elements, st = state[tab];
            f.status.innerHTML = '<option value="">' + TAB_TEXT[tab].all + '</option><option value="active">Active</option><option value="completed">Completed</option><option value="cancelled">Cancelled / rejected</option>';
            f.status.value = st.status; f.q.value = st.q; f.sort.value = st.sort;
            var types = {}; rows.filter(function (r) { return r.tab === tab; }).forEach(function (r) { (r.x.it.stays || []).forEach(function (s) { (s.rooms || []).forEach(function (rm) { types[rm.room_type] = 1; }); }); });
            f.room.innerHTML = '<option value="">All room types</option>' + Object.keys(types).sort().map(function (t) { return '<option>' + VS.esc(t) + '</option>'; }).join('');
            f.room.value = st.room;
        }
        function syncUrl() {
            var p = new URLSearchParams(); p.set('tab', tab === 'reservation' ? 'reservations' : 'bookings'); if (state[tab].status) p.set('status', state[tab].status);
            history.replaceState(null, '', window.location.pathname + '?' + p.toString());
        }
        function paintTabs() {
            var c = S.counts(rows);
            tabs.forEach(function (t) {
                var k = t.dataset.tab, on = k === tab;
                t.textContent = TAB_TEXT[k].title + ' (' + c[k].all + ')';
                t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
            });
        }
        function card(r) {
            var x = r.x, it = x.it, s = r.historical ? { label: 'Completed', cls: 'gray', key: 'completed', note: 'This reservation became a booking.' } : S.status(it, x.kind), m = S.money(it);
            var id = r.historical || x.kind === 'reservation' ? 'Reservation #' + it.id : 'Booking #' + it.id;
            if (!r.historical && x.kind === 'reservation' && it.booking && r.tab === 'booking') id = 'Booking #' + (it.booking.id || it.id);
            var href = '/guest/' + (r.historical || (x.kind === 'reservation' && r.tab === 'reservation') ? 'reservations' : (x.kind === 'reservation' ? 'reservations' : 'bookings')) + '/' + it.id;
            return '<a class="vs-card vs-stay-card d-block text-decoration-none vs-reveal" href="' + href + '">' +
                '<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold">' + VS.esc(id) + '</div><div class="text-muted small">' + VS.esc(dates(it)) + '</div><div class="small">' + VS.esc(S.firstRoomTypes(it)) + '</div></div>' +
                '<span class="vs-chip ' + s.cls + '">' + s.label + '</span></div>' +
                (s.note ? '<div class="small text-muted mt-1">' + VS.esc(s.note) + '</div>' : '') +
                (function () { if (r.historical) return ''; var pa = S.payAction(x); return pa.a === 'PAY_NOW' ? '<div class="mt-2"><span class="btn btn-velocity btn-sm" data-pay="' + pa.href + '">Pay now</span></div>' : pa.a === 'NONE' ? '' : '<div class="small text-muted mt-2"><i class="fas fa-circle-info me-1"></i>' + VS.esc(pa.text) + '</div>'; })() +
                '<div class="row text-center g-2 mt-2"><div class="col-4"><div class="small text-muted">Total</div><div class="vs-num fw-bold">' + VS.money(m.total) + '</div></div><div class="col-4"><div class="small text-muted">Paid</div><div class="vs-num fw-bold">' + VS.money(m.paid) + '</div></div><div class="col-4"><div class="small text-muted">Balance</div><div class="vs-num fw-bold">' + VS.money(m.remaining) + '</div></div></div></a>';
        }
        function paint() {
            paintTabs(); syncUrl();
            var st = state[tab], q = st.q.trim().toLowerCase(), T = TAB_TEXT[tab];
            var list = rows.filter(function (r) {
                if (r.tab !== tab) return false;
                if (st.status && r.cat !== st.status) return false;
                if (st.room && S.firstRoomTypes(r.x.it).indexOf(st.room) < 0) return false;
                if (q) { var it = r.x.it, hay = ((r.historical || r.x.kind === 'reservation' ? 'reservation #' : 'booking #') + it.id + ' ' + S.firstRoomTypes(it) + ' ' + S.status(it, r.x.kind).label + ' ' + dates(it) + ' ' + (it.guest_first_name || '') + ' ' + (it.guest_last_name || '')).toLowerCase(); if (hay.indexOf(q) < 0) return false; }
                return true;
            });
            list.sort(function (a, b) { var d = new Date(b.x.it.created_at) - new Date(a.x.it.created_at); return st.sort === 'oldest' ? -d : d; });
            var filtered = st.status || q || st.room;
            root.querySelector('#vsStayCount').textContent = VS.plural(list.length, 'result');
            root.querySelector('#vsStayListTitle').textContent = st.status ? T[st.status] : T.title;
            host.innerHTML = list.length ? list.map(card).join('')
                : VS.empty('fa-suitcase', filtered ? T.match : T.empty, filtered ? (st.status && !q && !st.room ? T.none[st.status] : 'Try another transaction number, room type, or date.') : T.none.all, filtered ? '<button class="btn btn-light btn-sm" data-clear2>Clear filters</button>' : '<a class="btn btn-velocity" href="/rooms">Browse rooms</a>');
            var c2 = host.querySelector('[data-clear2]'); if (c2) c2.onclick = clear;
            host.querySelectorAll('[data-pay]').forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); window.location.href = b.getAttribute('data-pay'); }); });
            VS.reveal(host);
        }
        function clear() { state[tab] = { status: '', q: '', sort: 'newest', room: '' }; writeForm(); paint(); }
        function load() {
            host.innerHTML = VS.skeleton(4);
            loadAll().then(function (r) {
                if (r.error) { host.innerHTML = VS.errorState(r.error, 'vsStaysRetry'); document.getElementById('vsStaysRetry').onclick = load; return; }
                if (r.partial) VS.toast('Part of your list could not be loaded. Try again in a moment.', 'warning');
                all = r.items; rows = S.classify(all);
                writeForm(); paint();
            });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () { readForm(); tab = t.dataset.tab; writeForm(); paint(); });
            t.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { var o = [].filter.call(tabs, function (x) { return x !== t; })[0]; o.click(); o.focus(); } });
        });
        form.addEventListener('input', VS.debounce(function () { readForm(); paint(); }, 120)); form.addEventListener('change', function () { readForm(); paint(); }); form.addEventListener('submit', function (e) { e.preventDefault(); });
        form.querySelector('[data-clear]').onclick = clear;
        load();
    };

    // ------------------------------------------------------------------ details

    function nz(v) { return Number(v || 0); }

    S.progress = function (it, m) {
        var tx = it.payment_transactions || [];
        var online = null, rejected = null, atIn = null, atOut = null;
        tx.forEach(function (p) {
            if (/gcash/i.test(p.payment_method)) {
                var rej = p.verification_status === 'rejected' || /rejected|failed/i.test(p.payment_status);
                if (rej) rejected = p; else if (!online) online = p; return;
            }
            if (!/completed/i.test(p.payment_status)) return;
            if (p.reference_number && /^CHECKIN-/.test(p.reference_number)) { if (!atIn) atIn = p; } else if (!atOut) atOut = p;
        });
        var b = it.booking || it, bs = b.booking_status;
        var checkedIn = bs === 'CHECKED_IN' || bs === 'COMPLETED_BOOKING', finished = bs === 'COMPLETED_BOOKING';
        var fully = m.remaining <= 0.009 && m.total > 0.009, steps = [];
        if (online) {
            var ver = !!online.verified_at || online.verification_status === 'verified';
            steps.push({ label: ver ? 'Paid online (GCash)' : 'Payment submitted (GCash)', when: online.payment_date, done: true });
            steps.push({ label: 'Verified by the front desk', when: ver ? online.verified_at : null, done: ver });
        } else if (rejected) steps.push({ label: 'Payment rejected' + (rejected.rejection_reason ? ': ' + rejected.rejection_reason : ''), when: rejected.payment_date, done: false });
        if (atIn || (!checkedIn && !fully)) steps.push({ label: 'Paid at check-in', when: atIn ? atIn.payment_date : null, done: !!atIn });
        if (atOut || (checkedIn && !finished && !fully) || (finished && atOut)) steps.push({ label: 'Paid at check-out', when: atOut ? atOut.payment_date : null, done: !!atOut });
        steps.push({ label: 'Fully paid', when: null, done: fully });
        var cur = steps.find(function (s) { return !s.done; }) || steps[steps.length - 1]; if (cur) cur.current = true;
        return steps;
    };

    function progressHtml(it, m, st) {
        if (st.key === 'cancelled' || st.key === 'rejected') return '';
        var steps = S.progress(it, m), pct = m.total > 0.009 ? Math.floor(Math.min(1, Math.min(m.paid, m.total) / m.total) * 100 + 1e-9) : 0;
        return '<section class="vs-card"><h2>Payment progress</h2><div class="fw-bold mb-1">' + pct + '% paid</div>' + (m.waiting > 0.009 ? '<div class="text-muted small mb-2">' + VS.money(m.waiting) + ' waiting for verification</div>' : '') +
            '<div class="progress mb-3" style="height:10px" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:' + pct + '%;background:var(--vs-red)"></div></div>' +
            steps.map(function (s) { return '<div class="py-1 ' + (s.done || s.current ? 'fw-semibold' : 'text-muted') + '">' + (s.done ? '✓ ' : '○ ') + VS.esc(s.label) + (s.when ? ' <span class="text-muted fw-normal">· ' + VS.esc(VS.dateTime(s.when)) + '</span>' : '') + '</div>'; }).join('') + '</section>';
    }

    function stayHtml(it) {
        var stays = it.stays || [];
        return '<section class="vs-card"><h2>Your stay</h2>' + stays.map(function (s) {
            var statePill = s.state === 'checked_in' ? ' <span class="vs-chip ok">Checked in</span>' : s.state === 'checked_out' ? ' <span class="vs-chip gray">Checked out</span>' : s.state === 'no_show' ? ' <span class="vs-chip gray">No-show</span>' : '';
            return '<div class="mb-3"><div class="fw-bold">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + ' · ' + VS.plural(s.nights, 'night') + statePill + '</div>' +
                (s.rooms || []).map(function (r) {
                    var promoOff = r.rate_after_promo != null && nz(r.rate_after_promo) < nz(r.price_per_night);
                    return '<div class="vs-row"><span>' + VS.esc(r.room_type) + ' × ' + r.quantity + ' · ' + (promoOff ? VS.money(r.price_per_night) + ' → ' + VS.money(r.rate_after_promo) + ' per night (Promo)' : VS.money(r.price_per_night) + ' × ' + VS.plural(r.nights, 'night')) + '</span><span>' + VS.money(r.subtotal) + '</span></div>' +
                        ((r.assigned_room_numbers || []).length ? '<div class="small text-muted">Room ' + VS.esc(r.assigned_room_numbers.join(', ')) + '</div>' : '');
                }).join('') +
                ((s.amenities || []).length ? '<div class="small fw-semibold mt-1">Amenities for this stay</div>' + s.amenities.map(function (a) { return '<div class="vs-row vs-sub"><span>' + VS.esc(a.amenity_name) + ' × ' + a.quantity + (a.room_label ? ' <span class="small">(' + VS.esc(a.room_label) + ')</span>' : '') + '</span><span>' + VS.money(a.subtotal) + '</span></div>'; }).join('') : '') +
                (stays.length > 1 ? '<div class="vs-row vs-strong"><span>Subtotal</span><span>' + VS.money(s.subtotal) + '</span></div>' : '') + '</div>';
        }).join('') + '</section>';
    }

    function guestsHtml(it) {
        var rep = [it.guest_first_name, it.guest_middle_name, it.guest_last_name].filter(Boolean).join(' ');
        var minors = (it.additional_guest_details || it.additional_guests || []);
        return '<section class="vs-card"><h2>Guests</h2><div class="vs-row"><span>Representative</span><span>' + VS.esc(rep) + ' — must present 1 valid ID</span></div><div class="vs-row"><span>Adults</span><span>' + (it.adults || 0) + '</span></div>' +
            '<div class="vs-row"><span>Minors</span><span>' + (it.children || 0) + '</span></div>' +
            (minors.length ? minors.map(function (g) { return '<div class="vs-row vs-sub"><span>' + VS.esc(g.name) + '</span><span>' + VS.esc(g.age != null ? 'Age ' + g.age : '') + '</span></div>'; }).join('') : '') + '</section>';
    }

    S.bill = function (it) {
        var b = it.booking || it, sb = b.stay_bill || it.stay_bill || null, m = S.money(it);
        var rows = [], rooms, amen, promo = 0, promoName = null, disc = 0, discName = null, other = [];
        if (sb) {
            rooms = nz(sb.room_charge); amen = nz(sb.amenity_charge); promo = nz(sb.promo_discount); promoName = sb.promo_name; disc = nz(sb.discount); discName = sb.discount_name;
            other = (sb.additional_charges || []).map(function (c) { return { label: c.description, amount: nz(c.amount) }; });
            if (nz(sb.additional_guest_fee) > 0) other.unshift({ label: 'Additional guest fee', amount: nz(sb.additional_guest_fee) });
        } else {
            rooms = (it.stays || []).reduce(function (a, s) { return a + nz(s.rooms_subtotal); }, 0);
            amen = (it.stays || []).reduce(function (a, s) { return a + nz(s.amenities_subtotal); }, 0);
            var pi = it.promo_info || {}; if (pi.applied) { promo = nz(pi.applied.amount); promoName = pi.applied.title; }
            var dp = it.discount_preview || {}; if (dp.status === 'approved' || dp.approved) { disc = nz(dp.discount || dp.amount); discName = dp.name; }
        }
        return { rooms: rooms, amenities: amen, promo: promo, promoName: promoName, discount: disc, discountName: discName, other: other, total: m.total, paid: m.paid, waiting: m.waiting, remaining: m.remaining, settledAtCheckin: !!(sb && sb.receipt_breakdown) };
    };

    function billHtml(it, st) {
        var bl = S.bill(it);
        var h = '<section class="vs-card"><h2>Bill</h2><div class="vs-row"><span>Room charges</span><span>' + VS.money(bl.rooms) + '</span></div>';
        if (bl.promo > 0.009) h += '<div class="vs-row"><span>Promo applied' + (bl.promoName ? ': ' + VS.esc(bl.promoName) : '') + '</span><span class="vs-neg">−' + VS.money(bl.promo) + '</span></div>';
        h += '<div class="vs-row"><span>Amenities</span><span>' + VS.money(bl.amenities) + '</span></div>';
        if (bl.discount > 0.009) h += '<div class="vs-row"><span>Discount' + (bl.discountName ? ' (' + VS.esc(bl.discountName) + ')' : '') + '</span><span class="vs-neg">−' + VS.money(bl.discount) + '</span></div><div class="small text-muted">on rooms and amenities</div>';
        bl.other.forEach(function (c) { h += '<div class="vs-row"><span>Other charges: ' + VS.esc(c.label) + '</span><span>' + VS.money(c.amount) + '</span></div>'; });
        h += '<hr class="vs-rule"><div class="vs-row vs-strong"><span>Grand total</span><span>' + VS.money(bl.total) + '</span></div><div class="vs-row"><span>Paid so far</span><span>' + VS.money(bl.paid) + '</span></div>';
        if (bl.waiting > 0.009) h += '<div class="vs-row"><span>Waiting for verification</span><span>' + VS.money(bl.waiting) + '</span></div><div class="small text-muted">' + VS.money(bl.waiting) + ' will be deducted once the front desk verifies your payment.</div>';
        var pi = it.promo_info || {};
        if ((pi.available || []).length && !bl.promo) h += '<div class="mt-3 fw-semibold">Promos for your rooms (verified at check-in)</div>' + pi.available.map(function (p) { return '<div class="small">' + VS.esc(p.title) + ' · ' + VS.esc(p.label || '') + (p.estimated_saving ? '<div class="text-muted">Estimated saving ' + VS.money(p.estimated_saving) + '. Not subtracted until the front desk verifies it.</div>' : '') + '</div>'; }).join('');
        return h + '</section>';
    }

    function paymentsHtml(it) {
        var tx = it.payment_transactions || [];
        if (!tx.length) return '<section class="vs-card"><h2>Payments</h2><p class="text-muted mb-0">No payments yet.</p></section>';
        return '<section class="vs-card"><h2>Payments</h2>' + tx.map(function (p) {
            var ver = p.verification_status === 'verified' || p.verified_at, rej = p.verification_status === 'rejected' || /rejected|failed/i.test(p.payment_status);
            var kind = /gcash/i.test(p.payment_method) ? 'GCash · Paid online' : (p.reference_number && /^CHECKIN-/.test(p.reference_number) ? 'Cash · Paid at check-in' : 'Cash · Paid at check-out');
            var chip = rej ? '<span class="vs-chip bad">Rejected</span>' : (/gcash/i.test(p.payment_method) && !ver ? '<span class="vs-chip wait">Waiting for verification</span>' : '<span class="vs-chip ok">Verified</span>');
            return '<div class="py-2 border-bottom"><div class="vs-row"><span class="fw-semibold">' + VS.esc(kind) + '</span><span>' + VS.money(p.amount_paid) + '</span></div><div class="small text-muted">' + VS.esc(VS.dateTime(p.payment_date)) + (p.reference_number && !/^CHECKIN-/.test(p.reference_number) ? ' · Ref. ' + VS.esc(p.reference_number) : '') + ' ' + chip + '</div>' +
                (p.cash_received != null ? '<div class="small text-muted">Cash received ' + VS.money(p.cash_received) + ' · Change ' + VS.money(p.change_given || 0) + '</div>' : '') +
                (p.proof_url ? '<a class="small" href="' + VS.esc(p.proof_url) + '" target="_blank" rel="noopener">View proof of payment</a>' : '') + (rej && p.rejection_reason ? '<div class="small text-danger">' + VS.esc(p.rejection_reason) + '</div>' : '') + '</div>';
        }).join('') + '</section>';
    }

    S.receiptLine = function (r, stage) {
        var money = VS.money(r.amount);
        if (stage === 1) return /gcash/i.test(r.payment_method) ? 'Paid online ' + money : /cash/i.test(r.payment_method) ? 'Paid in cash ' + money : 'Paid ' + money;
        if (stage === 2) return nz(r.amount) > 0.009 ? 'Paid at check-in ' + money : 'No payment at check-in';
        return r.fully_paid === true ? 'Total ' + money + ' · Fully paid' : r.fully_paid === false ? 'Total ' + money + ' · Balance still due' : 'Total ' + money;
    };
    S.stageOf = function (t) { return /PARTIAL|FULL_PAYMENT/.test(t) ? 1 : /CHECKIN/.test(t) ? 2 : /OFFICIAL/.test(t) ? 3 : 0; };

    function receiptsHtml(it, st) {
        if (st.key === 'cancelled' || st.key === 'rejected') return '';
        var by = {}; (it.receipts || []).forEach(function (r) { var s = S.stageOf(r.receipt_type); if (s && !by[s]) by[s] = r; });
        var names = { 1: 'Receipt 1 · Payment verified', 2: 'Receipt 2 · Check-in', 3: 'Receipt 3 · Check-out (final)' };
        var later = { 1: 'Issued after the front desk verifies your payment', 2: 'Issued at check-in', 3: 'Issued at check-out' };
        return '<section class="vs-card"><h2>Receipts</h2>' + [1, 2, 3].map(function (s) {
            var r = by[s];
            if (!r) return '<div class="vs-rcpt vs-rcpt-off"><div class="fw-semibold text-muted">' + names[s] + '</div><div class="small text-muted">' + later[s] + '</div></div>';
            return '<a class="vs-rcpt d-block text-decoration-none" href="/guest/receipts/' + encodeURIComponent(r.receipt_number) + '"><div class="d-flex justify-content-between"><span class="fw-bold">' + names[s] + '</span><span class="vs-chip ok">Issued</span></div>' +
                '<div class="small text-muted">' + VS.esc(r.receipt_number) + (r.issued_at ? ' · ' + VS.esc(VS.dateTime(r.issued_at)) : '') + '</div><div class="fw-semibold">' + VS.esc(S.receiptLine(r, s)) + '</div></a>';
        }).join('') + '</section>';
    }

    // the timeline, newest first: one entry per event; cash taken at the desk is one entry, GCash is submitted then verified
    S.timeline = function (it, kind, amenityRequests) {
        var out = [], b = it.booking || (kind === 'booking' ? it : null);
        function add(when, title, detail) { if (when) out.push({ at: new Date(when).getTime(), title: title, detail: detail }); }
        add(it.created_at, kind === 'booking' ? 'Booking created' : 'Reservation created');
        add(it.edited_at, 'Reservation modified');
        if (kind === 'reservation' && b) add(b.verified_at || b.confirmed_at, 'Reservation converted to a booking', 'Booking #' + b.id);
        else if (kind === 'booking') add(it.verified_at, 'Booking verified by the front desk');
        if (b) { add(b.checked_in_at, 'Checked in'); add(b.checked_out_at, 'Checked out'); add(b.completed_at, 'Final balance settled'); }
        if (it.cancelled_at || (b && b.cancelled_at)) add(it.cancelled_at || b.cancelled_at, kind === 'booking' ? 'Booking cancelled' : 'Reservation cancelled');
        if (it.status === 'REJECTED_RESERVATION') add(it.updated_at, 'Rejected by the front desk', it.rejection_reason);
        (it.payment_transactions || []).forEach(function (p) {
            var detail = VS.money(p.amount_paid) + ' · ' + (/gcash/i.test(p.payment_method) ? 'GCash' : 'Cash');
            var rej = p.verification_status === 'rejected' || /rejected|failed/i.test(p.payment_status), ver = p.verification_status === 'verified' || p.verified_at;
            var atIn = p.reference_number && /^CHECKIN-/.test(p.reference_number), atOut = /CHECKOUT/i.test(p.transaction_type || '');
            var cashAtDesk = /cash/i.test(p.payment_method) && /completed/i.test(p.payment_status || '') && !rej;
            var inHouse = b && (b.checked_in_at && new Date(p.payment_date) >= new Date(b.checked_in_at));
            if (cashAtDesk && (atIn || atOut || inHouse)) { add(p.verified_at || p.payment_date, (atIn ? 'Paid at check-in' : 'Paid at check-out') + ' · Cash', VS.money(p.amount_paid)); return; }
            if (cashAtDesk) { add(p.verified_at || p.payment_date, 'Payment verified by the front desk', detail); return; }
            add(p.payment_date, 'Payment submitted', detail);
            if (rej) add(p.verified_at || p.payment_date, 'Payment rejected by the front desk', p.rejection_reason ? detail + ' · ' + p.rejection_reason : detail);
            else if (ver) add(p.verified_at, 'Payment verified by the front desk', detail);
        });
        (amenityRequests || []).forEach(function (r) {
            var d = r.amenity_name + ' × ' + r.quantity;
            add(r.created_at, 'Amenity requested', d);
            if (r.status === 'approved') add(r.decided_at, 'Amenity request approved', d); else if (r.status === 'rejected') add(r.decided_at, 'Amenity request not approved', d + (r.rejection_reason ? ' · ' + r.rejection_reason : ''));
        });
        out.sort(function (a, b2) { return b2.at - a.at; });
        return out;
    };
    function timelineHtml(tl) {
        if (!tl.length) return '<section class="vs-card"><h2>Transaction timeline</h2><p class="text-muted mb-0">Nothing has happened on this transaction yet.</p></section>';
        return '<section class="vs-card"><h2>Transaction timeline</h2><ol class="vs-tl list-unstyled mb-0">' + tl.map(function (e, i) {
            return '<li class="' + (i === 0 ? 'now' : '') + '"><div class="fw-semibold">' + VS.esc(e.title) + '</div>' + (e.detail ? '<div class="small text-muted">' + VS.esc(e.detail) + '</div>' : '') + '<div class="small text-muted">' + VS.esc(VS.dateTime(new Date(e.at).toISOString())) + '</div></li>';
        }).join('') + '</ol></section>';
    }

    // the pinned summary: Grand total / Remaining balance / Amount to pay
    S.summaryHtml = function (it, kind, st) {
        var m = S.money(it), b = it.booking || it, bs = b.booking_status;
        if (st.key === 'cancelled' || st.key === 'rejected') return '<div class="vs-row"><span>Grand total</span><span>' + VS.money(0) + '</span></div><div class="vs-row vs-strong"><span>Amount to pay</span><span class="vs-pay">Cancelled</span></div>';
        var pay, settled = false;
        if (st.key === 'completed' || m.remaining <= 0.009) { pay = VS.money(0) + ' · Nothing to pay'; settled = true; }
        else if (m.waiting > 0.009) pay = VS.money(0) + ' · waiting for verification of ' + VS.money(m.waiting);
        else if (bs === 'CHECKED_IN') pay = VS.money(m.remaining) + ' · at check-out';
        else if (bs === 'NO_SHOW_BOOKING') pay = VS.money(m.remaining) + ' · at the front desk';
        else if (it.status === 'CONVERTED_TO_BOOKING' || kind === 'booking' || m.paid > 0.009) pay = VS.money(m.remaining) + ' · at check-out';
        else pay = it.payment_method === 'gcash' ? 'Pay now ' + VS.money(m.total * 0.2) + ' – ' + VS.money(m.total * 0.5) : 'Pay at the hotel';
        return '<div class="vs-row"><span>Grand total</span><span>' + VS.money(m.total) + '</span></div><div class="vs-row"><span>Remaining balance</span><span>' + VS.money(m.remaining) + '</span></div>' +
            '<div class="vs-row vs-strong"><span>Amount to pay</span><span class="vs-pay' + (settled ? ' settled' : '') + '">' + VS.esc(pay) + '</span></div>';
    };

    S.details = function (root, kind, id) {
        var host = root.querySelector('#vsDetails');
        function load() {
            host.innerHTML = VS.skeleton(6);
            var path = '/guest/' + (kind === 'reservation' ? 'reservations' : 'bookings') + '/' + id;
            Promise.all([VS.api(path), VS.api(path + '/additional-amenities', { quiet401: true })]).then(function (rs) {
                var r = rs[0];
                if (!r.ok) { host.innerHTML = VS.errorState(r.status === 404 || r.status === 403 ? 'We could not find that transaction.' : r.message, 'vsDetRetry'); var rb = document.getElementById('vsDetRetry'); if (rb) rb.onclick = load; return; }
                var it = r.data, requests = rs[1].ok ? (rs[1].data.requests || rs[1].data.data || rs[1].data || []) : [];
                if (!Array.isArray(requests)) requests = [];
                render(it, requests);
            });
        }
        function render(it, requests) {
            var st = S.status(it, kind), m = S.money(it), b = it.booking || (kind === 'booking' ? it : null);
            var title = idLabel(it, kind) + (kind === 'reservation' && b ? ' · Booking #' + b.id : '');
            var line = st.key === 'cancelled' ? 'This transaction was cancelled' : st.key === 'rejected' ? 'This transaction was not accepted' : st.key === 'noshow' ? 'Marked as a no-show' : st.key === 'completed' ? 'Checked out' : st.key === 'checkedin' ? 'Checked in' : st.note || (st.key === 'confirmed' ? 'Confirmed' : 'Waiting for front desk verification');
            var actions = [];
            var pa = S.payAction({ kind: kind, it: it });
            if (pa.a === 'PAY_NOW') actions.push('<a class="btn btn-velocity" href="' + pa.href + '">Pay now</a>');
            else if (pa.a === 'UNDER_REVIEW' || pa.a === 'FRONT_DESK') actions.push('<span class="small text-muted align-self-center"><i class="fas fa-circle-info me-1"></i>' + VS.esc(pa.text) + '</span>');
            if ((b || kind === 'booking') && /CHECKED_IN/.test((b || it).booking_status || '')) actions.push('<a class="btn btn-outline-velocity" href="#vsAmenityReq" data-open-amenity>Request amenities</a>');
            if (!/cancelled|rejected|completed|checkedin|noshow/.test(st.key)) actions.push('<button class="btn btn-outline-danger" id="vsCancelBtn">Cancel ' + kind + '</button>');
            if (/cancelled|rejected|completed|noshow/.test(st.key)) actions.push('<button class="btn btn-outline-danger" id="vsDeleteBtn">Delete</button>');
            host.innerHTML = '<section class="vs-card"><div class="d-flex justify-content-between align-items-start gap-2"><div><h1 class="h4 fw-bold mb-1">' + VS.esc(title) + '</h1><div class="text-muted small">Created ' + VS.esc(VS.dateTime(it.created_at)) + '</div><div class="mt-1">' + VS.esc(line) + '</div></div><span class="vs-chip ' + st.cls + '">' + st.label + '</span></div>' +
                (actions.length ? '<div class="d-flex flex-wrap gap-2 mt-3">' + actions.join('') + '</div>' : '') + '</section>' +
                progressHtml(it, m, st) + stayHtml(it) + guestsHtml(it) + discountHtml(it) + billHtml(it, st) + paymentsHtml(it) + receiptsHtml(it, st) +
                '<div id="vsAmenityReq"></div>' + timelineHtml(S.timeline(it, kind, requests)) +
                '<div class="vs-summary" id="vsPinned">' + S.summaryHtml(it, kind, st) + '</div>';
            wire(it, st, b);
            if (window.VSAmenities) window.VSAmenities.mount(host.querySelector('#vsAmenityReq'), it, kind, b, requests, load);
        }
        function discountHtml(it) {
            if (!it.discount_requested && !it.discount_id) return '';
            var s = (it.discount_verification_status || 'pending'), name = it.id_card_type || 'ID discount';
            var chip = s === 'approved' ? '<span class="vs-chip ok">Verified</span>' : s === 'rejected' ? '<span class="vs-chip bad">Not accepted</span>' : '<span class="vs-chip wait">To be verified at check-in</span>';
            return '<section class="vs-card"><h2>Discount ID</h2><div class="vs-row"><span>Discount type</span><span>' + VS.esc(name) + '</span></div><div class="vs-row"><span>Status</span><span>' + chip + '</span></div>' +
                (s === 'rejected' && it.discount_rejection_reason ? '<div class="small text-danger">' + VS.esc(it.discount_rejection_reason) + '</div>' : '') + '</section>';
        }
        function wire(it, st, b) {
            var cancel = host.querySelector('#vsCancelBtn'), del = host.querySelector('#vsDeleteBtn');
            if (cancel) cancel.onclick = function () {
                var paid = S.money(it).paid > 0.009;
                VS.confirm({ title: 'Cancel this ' + kind + '?', message: paid ? 'A payment was already made. Cancelling does not refund what was already paid.' : 'This cannot be undone.', confirmText: 'Cancel ' + kind, cancelText: 'Keep it', danger: true }).then(function (yes) {
                    if (!yes) return;
                    VS.busy(cancel, function () { return VS.api('/guest/' + (kind === 'reservation' ? 'reservations' : 'bookings') + '/' + it.id + '/cancel', { method: 'PUT' }); }).then(function (r) {
                        if (!r) return; if (r.ok) { VS.toast('Cancelled.', 'success'); load(); } else VS.toast(r.message, 'danger');
                    });
                });
            };
            if (del) del.onclick = function () {
                VS.confirm({ title: 'Delete this ' + kind + '?', message: 'It is removed from your list. It stays in your Transaction History.', confirmText: 'Delete', danger: true }).then(function (yes) {
                    if (!yes) return;
                    VS.busy(del, function () { return VS.api('/guest/' + (kind === 'reservation' ? 'reservations' : 'bookings') + '/' + it.id, { method: 'DELETE' }); }).then(function (r) {
                        if (!r) return; if (r.ok) { VS.toast('Deleted.', 'success'); window.location.href = '/guest/reservations'; } else VS.toast(r.message, 'danger');
                    });
                });
            };
        }
        load();
    };
})();
