/*
 * The guest dashboard, in the app's order: greeting, Quick actions, Booking & reservation summary, Your next stay, Stay calendar,
 * Other recent & upcoming stays, Pending & confirmed stays, Recent bookings, Notifications, Payment status, Account summary, Need help.
 * All figures come from the same endpoints as the app (/api/guest/reservations, /api/guest/bookings, /api/notifications, /api/guest/profile).
 */
(function () {
    'use strict';
    var VS = window.VS, S = window.VSStay;
    var D = window.VSDash = {};

    function part() { var p = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', hour12: false }).format(new Date()); return +p; }
    function greeting() { var h = part() % 24; return h < 12 ? 'Good morning,' : h < 18 ? 'Good afternoon,' : 'Good evening,'; }
    function dateLong() { return new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(new Date()); }
    function timeNow() { return new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date()); }

    function stayEvents(items) {
        var ev = [];
        items.forEach(function (x) {
            var st = S.status(x.it, x.kind);
            if (/cancelled|rejected/.test(st.key)) return;
            (x.it.stays || []).forEach(function (s) {
                ev.push({ date: s.check_in, kind: 'Check-in', label: S.idLabel(x.it, x.kind), x: x });
                ev.push({ date: s.check_out, kind: 'Check-out', label: S.idLabel(x.it, x.kind), x: x });
            });
        });
        return ev;
    }
    function weekStart(iso) { var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() - d.getUTCDay()); return d.toISOString().slice(0, 10); }
    function linkOf(x) { return '/guest/' + (x.kind === 'reservation' ? 'reservations' : 'bookings') + '/' + x.it.id; }

    function hint(x, today) {
        var s = (x.it.stays && x.it.stays[0]) || { check_in: String(x.it.check_in).slice(0, 10), check_out: String(x.it.check_out).slice(0, 10) };
        var st = S.status(x.it, x.kind), d = VS.nights(today, s.check_in);
        if (st.key === 'checkedin') { var o = VS.nights(today, s.check_out); return o === 0 ? 'Checking out today' : o === 1 ? 'Checking out tomorrow' : o > 1 ? 'Checking out in ' + o + ' days' : 'Check-out was due on ' + VS.date(s.check_out); }
        return d === 0 ? 'Check-in today' : d === 1 ? 'Check-in tomorrow' : d > 1 && d < 15 ? 'Check-in in ' + d + ' days' : 'Check-in on ' + VS.date(s.check_in);
    }

    /** What to pay next for a stay: Pay now (GCash reservation), under review, or where the balance is paid. */
    function nextPay(x) {
        var pa = S.payAction(x);
        if (pa.a === 'NONE') return '<div class="small text-success mt-1"><i class="fas fa-circle-check me-1"></i>Nothing to pay</div>';
        return '<div class="vs-row"><span>Pay next</span><span class="text-end">' + (pa.a === 'PAY_NOW' ? '<a class="btn btn-velocity btn-sm" href="' + pa.href + '">Pay now</a>' : VS.esc(pa.text)) + '</span></div>';
    }
    /** The most recent payment across every stay, with its status. */
    function latestPayment(items) {
        var all = [];
        items.forEach(function (x) { (x.it.payment_transactions || []).forEach(function (p) { all.push({ p: p, x: x }); }); });
        all.sort(function (a, b) { return new Date(b.p.payment_date) - new Date(a.p.payment_date); });
        if (!all.length) return '<div class="vs-row"><span>Latest payment</span><span class="text-muted">None yet</span></div>';
        var p = all[0].p, ver = p.verification_status === 'verified' || p.verified_at, rej = p.verification_status === 'rejected' || /rejected|failed/i.test(p.payment_status);
        var chip = rej ? '<span class="vs-chip bad">Rejected</span>' : (/gcash/i.test(p.payment_method) && !ver ? '<span class="vs-chip wait">Waiting for verification</span>' : '<span class="vs-chip ok">Verified</span>');
        return '<div class="vs-row"><span>Latest payment</span><span class="vs-num">' + VS.money(p.amount_paid) + '</span></div><div class="small text-muted text-end">' + VS.esc(VS.dateTime(p.payment_date)) + ' ' + chip + '</div>';
    }

    function stayRow(x) {
        var st = S.status(x.it, x.kind), m = S.money(x.it);
        return '<a class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom text-decoration-none" style="color:inherit" href="' + linkOf(x) + '"><div><div class="fw-semibold">' + VS.esc(S.idLabel(x.it, x.kind)) + '</div><div class="small text-muted">' + VS.esc(S.dates(x.it)) + '</div></div>' +
            '<div class="text-end"><span class="vs-chip ' + st.cls + '">' + st.label + '</span><div class="small vs-num">' + (m.remaining > 0.009 ? VS.money(m.remaining) + ' due' : 'Paid') + '</div></div></a>';
    }
    /** One row per transaction that still owes money: Pay now (GCash reservation), Payment under review, or Pay at the hotel / front desk. */
    function payRows(list) {
        var rows = list.map(function (x) { return { x: x, pa: S.payAction(x) }; }).filter(function (r) { return r.pa.a !== 'NONE'; }).slice(0, 6);
        if (!rows.length) return '';
        return '<hr class="vs-rule">' + rows.map(function (r) {
            var m = S.money(r.x.it);
            return '<div class="d-flex justify-content-between align-items-center gap-2 py-1"><a class="text-decoration-none" style="color:inherit" href="' + linkOf(r.x) + '"><div class="fw-semibold">' + VS.esc(S.idLabel(r.x.it, r.x.kind)) + '</div><div class="small text-muted vs-num">' + VS.money(m.remaining) + ' due</div></a>' +
                (r.pa.a === 'PAY_NOW' ? '<a class="btn btn-velocity btn-sm" href="' + r.pa.href + '">Pay now</a>' : '<span class="small text-muted text-end">' + VS.esc(r.pa.text) + '</span>') + '</div>';
        }).join('');
    }
    function card(title, html, extra, desc) { return '<section class="vs-card vs-reveal" ' + (extra || '') + '><h2' + (desc ? ' class="mb-0"' : '') + '>' + title + '</h2>' + (desc ? '<p class="text-muted small mb-2">' + desc + '</p>' : '') + html + '</section>'; }

    D.page = function (root, firstName) {
        var host = root.querySelector('#vsDash');
        host.innerHTML = '<div class="vs-dash-top"><section class="vs-card vs-greet"><div class="text-muted">' + greeting() + '</div><h1 class="h3 fw-bold mb-1">' + VS.esc(firstName) + '</h1><p class="mb-0 mt-1">Here’s a quick look at your stays and payments.</p></section>' +
            '<a class="vs-card vs-clockcard text-decoration-none" href="/guest/calendar" title="Open the calendar" aria-label="Today and the time. Open the calendar"><div class="text-muted small">Today</div><div class="fw-bold" id="vsDate"></div><div class="vs-clock vs-num" id="vsClock" aria-hidden="true"></div></a></div>' +
            card('Quick actions', '<div class="vs-qa">' + [['/guest/rooms', 'fa-bed', 'Browse rooms'], ['/guest/reservations?tab=bookings', 'fa-calendar-check', 'My bookings'], ['/guest/reservations?tab=reservations', 'fa-bookmark', 'My reservations'], ['/guest/history', 'fa-clock-rotate-left', 'Transaction history'], ['/guest/book/new', 'fa-plus', 'New booking'], ['/guest/reserve/new', 'fa-plus', 'New reservation']].map(function (q) {
                return '<a class="btn btn-outline-velocity" href="' + q[0] + '"><i class="fas ' + q[1] + '"></i><span>' + q[2] + '</span></a>'; }).join('') + '</div>', '', 'Shortcuts to common tasks') +
            '<div id="vsDashBody">' + VS.skeleton(4) + '</div>';
        // the live clock ticks every second without reloading anything, and stops while the tab is hidden
        var timer = null;
        function tick() { var c = root.querySelector('#vsClock'), d = root.querySelector('#vsDate'); if (d) d.textContent = dateLong(); if (c) c.textContent = timeNow(); }
        function startClock() { if (timer || document.hidden) return; tick(); timer = setInterval(tick, 1000); }
        function stopClock() { if (timer) { clearInterval(timer); timer = null; } }
        document.addEventListener('visibilitychange', function () { if (document.hidden) stopClock(); else startClock(); });
        window.addEventListener('pagehide', stopClock);
        tick(); startClock();

        function load() {
            var body = root.querySelector('#vsDashBody');
            Promise.all([S.loadAll(), VS.api('/notifications', { query: { per_page: 5 } }), VS.api('/guest/profile')]).then(function (rs) {
                var all = rs[0];
                if (all.error) { body.innerHTML = VS.errorState('Could not load your stays. ' + all.error, 'vsDashRetry'); document.getElementById('vsDashRetry').onclick = load; return; }
                var items = all.items, today = VS.today();
                items.sort(function (a, b) { return new Date(b.it.created_at) - new Date(a.it.created_at); });
                var keyOf = function (x) { return S.status(x.it, x.kind).key; };
                var active = items.filter(function (x) { return /pending|confirmed|checkedin/.test(keyOf(x)); });
                var past = items.filter(function (x) { return /completed|noshow/.test(keyOf(x)); });
                var cancelled = items.filter(function (x) { return /cancelled|rejected/.test(keyOf(x)); });
                var html = '';
                var cnt = S.counts(S.classify(items));
                function tile(tab, cat, label) {
                    var n = cnt[tab][cat];
                    return '<a class="vs-sumtile' + (n ? '' : ' zero') + '" href="/guest/reservations?tab=' + (tab === 'booking' ? 'bookings' : 'reservations') + '&status=' + cat + '" aria-label="' + VS.esc(label) + ': ' + n + '"><span class="n">' + n + '</span><span class="l">' + VS.esc(label) + '</span></a>';
                }
                var open = localStorage.getItem('vs_summary_open') !== '0';
                html += '<section class="vs-card vs-reveal" id="vsSummaryCard"><button type="button" class="vs-collapse-head" id="vsSummaryToggle" aria-expanded="' + open + '" aria-controls="vsSummaryBody"><h2>Booking &amp; reservation summary</h2><i class="fas fa-chevron-down" aria-hidden="true"></i></button>' +
                    '<div id="vsSummaryBody"' + (open ? '' : ' hidden') + '><p class="text-muted small my-2">A booking is paid; a reservation holds a room to pay later. Tap a total to open that list.</p><div class="vs-sumgrid">' +
                    tile('booking', 'active', 'Active bookings') + tile('reservation', 'active', 'Active reservations') +
                    tile('booking', 'completed', 'Past bookings') + tile('reservation', 'completed', 'Past reservations') +
                    tile('booking', 'cancelled', 'Cancelled bookings') + tile('reservation', 'cancelled', 'Cancelled reservations') + '</div></div></section>';

                // next stay: the one in house, else the soonest upcoming
                var upcoming = active.filter(function (x) { var s = (x.it.stays && x.it.stays[0]) || {}; return keyOf(x) === 'checkedin' || (s.check_out || '') >= today; })
                    .sort(function (a, b) { var A = keyOf(a) === 'checkedin' ? '0' : ((a.it.stays[0] || {}).check_in || '9'), B = keyOf(b) === 'checkedin' ? '0' : ((b.it.stays[0] || {}).check_in || '9'); return A < B ? -1 : 1; });
                var next = upcoming[0];
                if (next) {
                    var st = S.status(next.it, next.kind), m = S.money(next.it);
                    var label = { checkedin: 'Staying with us', confirmed: 'Confirmed', pending: m.waiting > 0.009 ? 'Payment under review' : (next.it.payment_method === 'gcash' && m.paid <= 0.009 ? 'Waiting for payment' : 'Awaiting verification') }[st.key] || st.label;
                    html += card('Your next stay', '<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold">' + VS.esc(S.idLabel(next.it, next.kind)) + '</div><div>' + VS.esc(S.dates(next.it)) + '</div><div class="text-muted small">' + VS.esc(hint(next, today)) + '</div><div class="small">' + VS.esc(S.firstRoomTypes(next.it)) + '</div></div><span class="vs-chip ' + st.cls + '">' + VS.esc(label) + '</span></div>' +
                        '<div class="vs-countdown mt-2"><i class="fas fa-hourglass-half" aria-hidden="true"></i> ' + VS.esc(hint(next, today)) + '</div>' +
                        '<div class="vs-row mt-2"><span>Balance due</span><span>' + VS.money(m.remaining) + '</span></div>' + nextPay(next) + '<a class="btn btn-velocity btn-sm mt-2" href="' + linkOf(next) + '">View details ›</a>');
                } else html += card('Your next stay', VS.empty('fa-calendar', 'No stay planned yet', 'Book a room and your next stay will show up here.', '<a class="btn btn-velocity" href="/guest/rooms">Book a room</a>'));

                // stay calendar
                html += card('Stay calendar', '<p class="text-muted small">Check-ins and check-outs of your stays</p><div class="btn-group mb-2" role="group" aria-label="Period" id="calMode"><button class="btn btn-sm btn-velocity" data-m="daily">Daily</button><button class="btn btn-sm btn-outline-velocity" data-m="weekly">Weekly</button><button class="btn btn-sm btn-outline-velocity" data-m="monthly">Monthly</button></div><div id="calGrid"></div><div id="calList" class="mt-2"></div>');

                var others = items.filter(function (x) { return !next || x !== next; });
                html += card('Other recent & upcoming stays', others.slice(0, 5).length ? others.slice(0, 5).map(stayRow).join('') : '<p class="text-muted mb-0">No recent or upcoming stays</p>');
                var pc = items.filter(function (x) { return /pending|confirmed/.test(keyOf(x)); });
                html += card('Pending & confirmed stays', pc.length ? pc.slice(0, 5).map(stayRow).join('') : '<p class="text-muted mb-0">No pending or confirmed upcoming stays right now.</p>', '', 'Stays waiting for approval or confirmed');
                var recent = items.filter(function (x) { return x.kind === 'booking' || (x.it.booking); }).slice(0, 5);
                html += card('Recent bookings', recent.length ? recent.map(stayRow).join('') + '<div class="text-end mt-2"><a href="/guest/reservations?tab=bookings">See all</a></div>' : '<p class="text-muted mb-0">No bookings found</p><a class="btn btn-velocity btn-sm mt-2" href="/guest/rooms">Book now</a>', '', 'Your latest bookings');

                var nr = rs[1].ok ? rs[1].data : { data: [], unread_count: 0 };
                html += card('Notifications' + (nr.unread_count ? ' <span class="vs-chip bad ms-1">' + nr.unread_count + '</span>' : ''), '<div class="small text-muted mb-1">' + (nr.unread_count ? nr.unread_count + ' unread' : 'You are all caught up') + '</div>' + ((nr.data || []).slice(0, 3).map(function (n) { return '<div class="py-1 border-bottom"><div class="' + (n.is_read ? '' : 'fw-bold') + '">' + VS.esc(n.title) + '</div><div class="small text-muted">' + VS.esc(VS.dateTime(n.created_at)) + '</div></div>'; }).join('') || '<p class="text-muted mb-0">No notifications</p>') + '<a class="small d-inline-block mt-2" href="/guest/notifications">See all</a>', '', 'Updates on your stays and payments');

                var live = items.filter(function (x) { return !/cancelled|rejected/.test(keyOf(x)); });
                var paid = live.reduce(function (a, x) { return a + S.money(x.it).paid; }, 0), wait = live.reduce(function (a, x) { return a + S.money(x.it).waiting; }, 0), due = live.filter(function (x) { return !/completed/.test(keyOf(x)); }).reduce(function (a, x) { return a + S.money(x.it).remaining; }, 0);
                html += card('Payment status', '<div class="vs-row"><span>Paid (verified)</span><span>' + VS.money(paid) + '</span></div><div class="vs-row"><span>Waiting for verification</span><span>' + VS.money(wait) + '</span></div><div class="vs-row vs-strong"><span>Balance due</span><span>' + VS.money(due) + '</span></div>' + latestPayment(live) + payRows(live), '', 'Paid and still due');

                var pr = rs[2].ok ? (rs[2].data.user || {}) : {}, g = rs[2].ok ? (rs[2].data.guest || {}) : {};
                var comp = S.completeness(pr, g), pct = comp.pct;
                html += card('Account summary', '<a class="d-flex align-items-center gap-3 text-decoration-none" style="color:inherit" href="/guest/profile"><div class="vs-donut" style="--p:' + pct + '" role="img" aria-label="Profile ' + pct + '% complete"><span>' + pct + '%</span></div><div><div class="fw-bold">' + VS.esc([pr.first_name, pr.last_name].filter(Boolean).join(' ')) + '</div><div class="small text-muted">' + VS.esc(pr.email || '') + '</div><div class="small text-muted">' + VS.esc(g.mobile_number || pr.mobile_number || '') + '</div><div class="small ' + (comp.line ? 'text-danger' : 'text-success') + '">' + VS.esc(comp.line || 'Profile complete') + '</div></div></a><div class="vs-row mt-2"><span>Account type</span><span>Guest</span></div><div class="vs-row"><span>Account status</span><span>Active</span></div>', '', 'Your profile and contact details');
                html += card('Need help?', '<p class="mb-2">Our team is here for you 24/7.</p><div><i class="fas fa-phone me-2 text-danger"></i>0955 139 2737</div><div><i class="fas fa-envelope me-2 text-danger"></i>velocitysuites@gmail.com</div><div><i class="fas fa-location-dot me-2 text-danger"></i>Allah Valley Drive, Surallah, South Cotabato</div><a class="btn btn-outline-velocity btn-sm mt-3" href="mailto:velocitysuites@gmail.com?subject=Velocity%20Suites%20support">Contact support</a>');
                body.innerHTML = '<div class="vs-dash-grid">' + html + '</div>';
                body.querySelectorAll('section.vs-card').forEach(function (sec) { var h = sec.querySelector('h2'); if (h && /summary|calendar|next stay|payment status/i.test(h.textContent)) sec.classList.add('vs-span2'); });
                VS.reveal(body);
                var tg = body.querySelector('#vsSummaryToggle'); if (tg) tg.onclick = function () { var now = tg.getAttribute('aria-expanded') !== 'true'; tg.setAttribute('aria-expanded', now); body.querySelector('#vsSummaryBody').hidden = !now; try { localStorage.setItem('vs_summary_open', now ? '1' : '0'); } catch (e) { /* storage off */ } };
                calendar(body, items);
            });
        }

        function calendar(body, items) {
            var ev = stayEvents(items), mode = 'daily', today = VS.today(), month = today.slice(0, 7);
            var grid = body.querySelector('#calGrid'), list = body.querySelector('#calList');
            function paint() {
                var from, to;
                if (mode === 'daily') { from = to = today; } else if (mode === 'weekly') { from = weekStart(today); to = VS.addDays(from, 6); } else { from = month + '-01'; to = VS.addDays(month + '-01', 31).slice(0, 7) === month ? VS.addDays(month + '-01', 30) : VS.addDays(VS.addDays(month + '-01', 31).slice(0, 7) + '-01', -1); }
                var inRange = ev.filter(function (e) { return e.date >= from && e.date <= to; }).sort(function (a, b) { return a.date < b.date ? -1 : 1; });
                list.innerHTML = inRange.length ? inRange.map(function (e) { return '<div class="vs-row"><span>' + VS.esc(e.kind) + ' · ' + VS.esc(e.label) + '</span><span>' + VS.esc(VS.date(e.date)) + '</span></div>'; }).join('') : '<p class="text-muted small mb-0">Nothing scheduled in this period.</p>';
                // month grid with dots
                var y = +month.slice(0, 4), mo = +month.slice(5, 7), first = new Date(Date.UTC(y, mo - 1, 1)), days = new Date(Date.UTC(y, mo, 0)).getUTCDate(), lead = first.getUTCDay();
                var cells = ''; for (var i = 0; i < lead; i++) cells += '<div></div>';
                for (var d = 1; d <= days; d++) {
                    var iso = month + '-' + String(d).padStart(2, '0'), has = ev.some(function (e) { return e.date === iso; });
                    cells += '<div class="vs-cal-day' + (iso === today ? ' today' : '') + '">' + d + (has ? '<i></i>' : '') + '</div>';
                }
                grid.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-1"><button class="btn btn-sm btn-light" data-prev aria-label="Previous month">‹</button><strong>' + first.toLocaleString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }) + '</strong><button class="btn btn-sm btn-light" data-nextm aria-label="Next month">›</button></div><div class="vs-cal">' + ['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(function (x) { return '<div class="text-muted small">' + x + '</div>'; }).join('') + cells + '</div>';
                grid.querySelector('[data-prev]').onclick = function () { var dd = new Date(Date.UTC(y, mo - 2, 1)); month = dd.toISOString().slice(0, 7); paint(); };
                grid.querySelector('[data-nextm]').onclick = function () { var dd = new Date(Date.UTC(y, mo, 1)); month = dd.toISOString().slice(0, 7); paint(); };
            }
            body.querySelectorAll('#calMode [data-m]').forEach(function (b) { b.onclick = function () { mode = b.dataset.m; body.querySelectorAll('#calMode [data-m]').forEach(function (x) { x.className = 'btn btn-sm ' + (x === b ? 'btn-velocity' : 'btn-outline-velocity'); }); paint(); }; });
            paint();
        }
        load();
    };

    // ---- the Calendar screen (the app opens it from the date and time on the dashboard) ----
    D.calendarPage = function (root) {
        var host = root.querySelector('#vsCal'), filter = 'All', picked = null, month = VS.today().slice(0, 7), events = [];
        var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        function build(items) {
            var today = VS.today(), floor = VS.addDays(today, -1);
            events = [];
            S.classify(items).forEach(function (r) {
                if (r.historical || r.cat !== 'active') return;
                var x = r.x, key = S.status(x.it, x.kind).key, label = (r.tab === 'booking' ? 'Booking' : 'Reservation') + ': ' + (S.firstRoomTypes(x.it) || S.idLabel(x.it, x.kind));
                var href = '/guest/' + (x.kind === 'reservation' ? 'reservations' : 'bookings') + '/' + x.it.id;
                (x.it.stays && x.it.stays.length ? x.it.stays : [{ check_in: String(x.it.check_in).slice(0, 10), check_out: String(x.it.check_out).slice(0, 10) }]).forEach(function (st) {
                    if (key !== 'checkedin' && st.check_in >= floor) events.push({ date: st.check_in, kind: 'in', tab: r.tab, label: label, href: href });
                    if (key === 'checkedin' && st.check_out >= floor) events.push({ date: st.check_out, kind: 'out', tab: r.tab, label: label, href: href });
                });
            });
            events.sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : 0; });
        }
        function byFilter(e) { return filter === 'All' || (filter === 'Bookings' ? e.tab === 'booking' : e.tab === 'reservation'); }
        function paint() {
            var y = +month.slice(0, 4), mo = +month.slice(5, 7), first = new Date(Date.UTC(y, mo - 1, 1)), days = new Date(Date.UTC(y, mo, 0)).getUTCDate(), lead = first.getUTCDay(), today = VS.today();
            var dots = {}; events.filter(byFilter).forEach(function (e) { dots[e.date] = 1; });
            var cells = ''; for (var i = 0; i < lead; i++) cells += '<div></div>';
            for (var d = 1; d <= days; d++) { var iso = month + '-' + String(d).padStart(2, '0'); cells += '<button type="button" class="vs-cal-day' + (iso === today ? ' today' : '') + (iso === picked ? ' picked' : '') + '" data-d="' + iso + '" aria-label="' + VS.esc(VS.date(iso)) + (dots[iso] ? ', has events' : '') + '" aria-pressed="' + (iso === picked) + '">' + d + (dots[iso] ? '<i></i>' : '') + '</button>'; }
            var list = events.filter(function (e) { return byFilter(e) && (!picked || e.date === picked); });
            host.innerHTML = '<section class="vs-card"><div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Show">' + [['All', 'All'], ['Bookings', 'Upcoming booking'], ['Reservations', 'Upcoming reservation']].map(function (f) { return '<button type="button" class="btn btn-sm ' + (filter === f[0] ? 'btn-velocity' : 'btn-outline-velocity') + '" data-f="' + f[0] + '" aria-pressed="' + (filter === f[0]) + '">' + f[1] + '</button>'; }).join('') + '</div>' +
                '<div class="d-flex justify-content-between align-items-center mb-1"><button class="btn btn-sm btn-light" data-prev aria-label="Previous month">\u2039</button><strong>' + MONTHS[mo - 1] + ' ' + y + '</strong><button class="btn btn-sm btn-light" data-nextm aria-label="Next month">\u203a</button></div>' +
                '<div class="vs-cal-head"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div><div class="vs-cal-grid" role="group" aria-label="' + MONTHS[mo - 1] + ' ' + y + '">' + cells + '</div></section>' +
                '<section class="vs-card"><div class="d-flex justify-content-between align-items-center mb-2"><h2 class="mb-0">' + (picked ? 'Showing events for ' + VS.esc(VS.date(picked)) : 'Upcoming check-ins and check-outs') + '</h2>' + (picked ? '<button class="btn btn-light btn-sm" data-clear>Clear</button>' : '') + '</div>' +
                (list.length ? list.map(function (e) { return '<a class="vs-rcpt d-flex justify-content-between align-items-center gap-2 text-decoration-none" href="' + e.href + '"><div><div class="fw-semibold">' + VS.esc(e.label) + '</div><div class="small text-muted">' + (e.kind === 'in' ? 'Check-in' : 'Check-out') + ' \u00b7 ' + VS.esc(VS.date(e.date)) + '</div></div><span class="vs-chip ' + (e.kind === 'in' ? '' : 'ok') + '">' + (e.kind === 'in' ? '2:00 PM' : '12:00 PM') + '</span></a>'; }).join('')
                    : VS.empty('fa-calendar', 'No transactions found', 'No transactions match the filters you chose. Try a different type or date.')) + '</section>';
            host.querySelectorAll('[data-f]').forEach(function (b) { b.onclick = function () { filter = b.dataset.f; paint(); }; });
            host.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { picked = picked === b.dataset.d ? null : b.dataset.d; paint(); }; });
            var c = host.querySelector('[data-clear]'); if (c) c.onclick = function () { picked = null; paint(); };
            host.querySelector('[data-prev]').onclick = function () { month = new Date(Date.UTC(y, mo - 2, 1)).toISOString().slice(0, 7); paint(); };
            host.querySelector('[data-nextm]').onclick = function () { month = new Date(Date.UTC(y, mo, 1)).toISOString().slice(0, 7); paint(); };
        }
        function load() {
            host.innerHTML = VS.skeleton(5);
            S.loadAll().then(function (r) {
                if (r.error) { host.innerHTML = VS.errorState(r.error, 'vsCalRetry'); document.getElementById('vsCalRetry').onclick = load; return; }
                build(r.items); paint();
            });
        }
        load();
    };
})();
