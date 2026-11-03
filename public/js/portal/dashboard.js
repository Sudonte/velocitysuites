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
    function clock() { return new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(new Date()) + ' · ' + new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date()); }

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

    function stayRow(x) {
        var st = S.status(x.it, x.kind), m = S.money(x.it);
        return '<a class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom text-decoration-none" style="color:inherit" href="' + linkOf(x) + '"><div><div class="fw-semibold">' + VS.esc(S.idLabel(x.it, x.kind)) + '</div><div class="small text-muted">' + VS.esc(S.dates(x.it)) + '</div></div>' +
            '<div class="text-end"><span class="vs-chip ' + st.cls + '">' + st.label + '</span><div class="small vs-num">' + (m.remaining > 0.009 ? VS.money(m.remaining) + ' due' : 'Paid') + '</div></div></a>';
    }
    function card(title, html, extra) { return '<section class="vs-card vs-reveal" ' + (extra || '') + '><h2>' + title + '</h2>' + html + '</section>'; }

    D.page = function (root, firstName) {
        var host = root.querySelector('#vsDash');
        host.innerHTML = '<section class="vs-card"><div class="text-muted">' + greeting() + '</div><h1 class="h3 fw-bold mb-1">' + VS.esc(firstName) + '</h1><div class="text-muted small" id="vsClock"></div><p class="mb-0 mt-1">Here’s a quick look at your stays and payments.</p></section>' +
            card('Quick actions', '<div class="row g-2">' + [['/rooms', 'fa-bed', 'Browse rooms'], ['/guest/book/new', 'fa-calendar-check', 'Booking'], ['/guest/reserve/new', 'fa-bookmark', 'Reservation'], ['/guest/reservations', 'fa-suitcase', 'My stays'], ['/guest/history', 'fa-clock-rotate-left', 'Transaction history'], ['/guest/profile', 'fa-circle-user', 'Profile']].map(function (q) {
                return '<div class="col-6 col-md-4"><a class="btn btn-outline-velocity w-100 py-2" href="' + q[0] + '"><i class="fas ' + q[1] + ' me-2"></i>' + q[2] + '</a></div>'; }).join('') + '</div>') +
            '<div id="vsDashBody">' + VS.skeleton(4) + '</div>';
        function tick() { var c = root.querySelector('#vsClock'); if (c) c.textContent = clock(); }
        tick(); setInterval(tick, 1000);

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
                html += card('Booking & reservation summary', '<div class="row text-center g-2"><div class="col-4"><div class="h3 mb-0 vs-num">' + active.length + '</div><div class="small text-muted">Active</div></div><div class="col-4"><div class="h3 mb-0 vs-num">' + past.length + '</div><div class="small text-muted">Past</div></div><div class="col-4"><div class="h3 mb-0 vs-num">' + cancelled.length + '</div><div class="small text-muted">Cancelled</div></div></div>');

                // next stay: the one in house, else the soonest upcoming
                var upcoming = active.filter(function (x) { var s = (x.it.stays && x.it.stays[0]) || {}; return keyOf(x) === 'checkedin' || (s.check_out || '') >= today; })
                    .sort(function (a, b) { var A = keyOf(a) === 'checkedin' ? '0' : ((a.it.stays[0] || {}).check_in || '9'), B = keyOf(b) === 'checkedin' ? '0' : ((b.it.stays[0] || {}).check_in || '9'); return A < B ? -1 : 1; });
                var next = upcoming[0];
                if (next) {
                    var st = S.status(next.it, next.kind), m = S.money(next.it);
                    var label = { checkedin: 'Staying with us', confirmed: 'Confirmed', pending: m.waiting > 0.009 ? 'Payment under review' : (next.it.payment_method === 'gcash' && m.paid <= 0.009 ? 'Waiting for payment' : 'Awaiting verification') }[st.key] || st.label;
                    html += card('Your next stay', '<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold">' + VS.esc(S.idLabel(next.it, next.kind)) + '</div><div>' + VS.esc(S.dates(next.it)) + '</div><div class="text-muted small">' + VS.esc(hint(next, today)) + '</div><div class="small">' + VS.esc(S.firstRoomTypes(next.it)) + '</div></div><span class="vs-chip ' + st.cls + '">' + VS.esc(label) + '</span></div>' +
                        '<div class="vs-row mt-2"><span>Balance due</span><span>' + VS.money(m.remaining) + '</span></div><a class="btn btn-velocity btn-sm mt-1" href="' + linkOf(next) + '">View details ›</a>');
                } else html += card('Your next stay', VS.empty('fa-calendar', 'No stay planned yet', 'Book a room and your next stay will show up here.', '<a class="btn btn-velocity" href="/rooms">Book a room</a>'));

                // stay calendar
                html += card('Stay calendar', '<p class="text-muted small">Check-ins and check-outs of your stays</p><div class="btn-group mb-2" role="group" aria-label="Period" id="calMode"><button class="btn btn-sm btn-velocity" data-m="daily">Daily</button><button class="btn btn-sm btn-outline-velocity" data-m="weekly">Weekly</button><button class="btn btn-sm btn-outline-velocity" data-m="monthly">Monthly</button></div><div id="calGrid"></div><div id="calList" class="mt-2"></div>');

                var others = items.filter(function (x) { return !next || x !== next; });
                html += card('Other recent & upcoming stays', others.slice(0, 5).length ? others.slice(0, 5).map(stayRow).join('') : '<p class="text-muted mb-0">Nothing else yet.</p>');
                var pc = items.filter(function (x) { return /pending|confirmed/.test(keyOf(x)); });
                html += card('Pending & confirmed stays', pc.length ? pc.slice(0, 5).map(stayRow).join('') : '<p class="text-muted mb-0">No pending or confirmed stays.</p>');
                var recent = items.filter(function (x) { return x.kind === 'booking' || (x.it.booking); }).slice(0, 5);
                html += card('Recent bookings', recent.length ? recent.map(stayRow).join('') : '<p class="text-muted mb-0">No bookings yet.</p>');

                var nr = rs[1].ok ? rs[1].data : { data: [], unread_count: 0 };
                html += card('Notifications', '<div class="small text-muted mb-1">' + (nr.unread_count ? nr.unread_count + ' unread' : 'You are all caught up') + '</div>' + ((nr.data || []).slice(0, 3).map(function (n) { return '<div class="py-1 border-bottom"><div class="' + (n.is_read ? '' : 'fw-bold') + '">' + VS.esc(n.title) + '</div><div class="small text-muted">' + VS.esc(VS.dateTime(n.created_at)) + '</div></div>'; }).join('') || '<p class="text-muted mb-0">No notifications yet.</p>') + '<a class="small d-inline-block mt-2" href="/guest/notifications">See all</a>');

                var live = items.filter(function (x) { return !/cancelled|rejected/.test(keyOf(x)); });
                var paid = live.reduce(function (a, x) { return a + S.money(x.it).paid; }, 0), wait = live.reduce(function (a, x) { return a + S.money(x.it).waiting; }, 0), due = live.filter(function (x) { return !/completed/.test(keyOf(x)); }).reduce(function (a, x) { return a + S.money(x.it).remaining; }, 0);
                html += card('Payment status', '<div class="vs-row"><span>Paid (verified)</span><span>' + VS.money(paid) + '</span></div><div class="vs-row"><span>Waiting for verification</span><span>' + VS.money(wait) + '</span></div><div class="vs-row vs-strong"><span>Balance due</span><span>' + VS.money(due) + '</span></div>');

                var pr = rs[2].ok ? (rs[2].data.user || {}) : {}, g = rs[2].ok ? (rs[2].data.guest || {}) : {};
                var fields = [pr.first_name, pr.last_name, pr.email, g.mobile_number || pr.mobile_number, g.gender || pr.gender, g.date_of_birth || pr.date_of_birth, g.address || pr.address, g.profile_picture || pr.profile_picture];
                var pct = Math.round(fields.filter(Boolean).length / fields.length * 100);
                html += card('Account summary', '<div class="d-flex align-items-center gap-3"><div class="vs-donut" style="--p:' + pct + '" role="img" aria-label="Profile ' + pct + '% complete"><span>' + pct + '%</span></div><div><div class="fw-bold">' + VS.esc([pr.first_name, pr.last_name].filter(Boolean).join(' ')) + '</div><div class="small text-muted">' + VS.esc(pr.email || '') + '</div><div class="small text-muted">' + VS.esc(g.mobile_number || pr.mobile_number || '') + '</div><a class="small" href="/guest/profile">' + (pct < 100 ? 'Complete your profile' : 'View profile') + '</a></div></div>');
                html += card('Need help', '<p class="mb-2">The front desk is here 24/7.</p><div><i class="fas fa-phone me-2 text-danger"></i>0955 139 2737</div><div><i class="fas fa-envelope me-2 text-danger"></i>velocitysuites@gmail.com</div><div><i class="fas fa-location-dot me-2 text-danger"></i>Allah Valley Drive, Surallah, South Cotabato</div>');
                body.innerHTML = html; VS.reveal(body);
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
})();
