/*
 * Rooms on the web, drawn from GET /api/rooms - the same list the app's Available Rooms screen shows (photo, name, price per
 * night, "3 rooms available" / "Fully booked", guests · beds · size, promo badge) and the same Room Details.
 */
(function () {
    'use strict';
    var VS = window.VS;

    var R = window.VSRooms = {};

    function facts(rt) {
        var parts = [];
        if (rt.capacity) parts.push(VS.plural(rt.capacity, 'guest'));
        if (rt.bed_type) parts.push(rt.bed_type);
        if (rt.room_size) parts.push(String(rt.room_size).trim() + ' sqm');
        return parts.join(' · ');
    }
    R.facts = facts;

    function promoBadge(rt) {
        var n = (rt.promos || []).length;
        return n ? '<span class="vs-room-promo"><i class="fas fa-tag me-1"></i>' + VS.plural(n, 'promo') + '</span>' : '';
    }

    function availChip(rt) {
        return rt.is_fully_booked || (rt.available_count || 0) <= 0
            ? '<span class="vs-chip bad vs-room-avail">Fully booked</span>'
            : '<span class="vs-chip ok vs-room-avail">' + VS.plural(rt.available_count, 'room') + ' available</span>';
    }

    // ---- the selection: dates + how many rooms of each type, kept for 30 minutes (also through sign-in) ----

    var SEL_KEY = 'vs_selection', SEL_TTL = 30 * 60 * 1000;
    R.loadSelection = function () {
        try {
            var v = JSON.parse(localStorage.getItem(SEL_KEY) || 'null');
            if (!v || !v.ts || Date.now() - v.ts > SEL_TTL) { localStorage.removeItem(SEL_KEY); return null; }
            return v;
        } catch (e) { return null; }
    };
    R.saveSelection = function (v) { v.ts = Date.now(); try { localStorage.setItem(SEL_KEY, JSON.stringify(v)); } catch (e) { /* storage off */ } };
    // gone from the local AND the session storage, so it can never come back on another page
    R.clearSelection = function () { try { localStorage.removeItem(SEL_KEY); } catch (e) { /* ignore */ } try { sessionStorage.removeItem(SEL_KEY); } catch (e) { /* ignore */ } };

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function shortDate(iso) { var p = String(iso).split('-'); return MONTHS[+p[1] - 1] + ' ' + (+p[2]); }

    // A room type has its STANDARD option (the type itself) and, when some of its rooms have their own price or capacity, "other" options.
    // Every option is a "unit" with its own key ("12" for the standard one, "12:r4200c4" for another) used by the steppers, the selection and the wizard.
    R.unitKey = function (u) { return u._key || String(u.id); };
    R.unitsOf = function (rt) {
        var out = [rt];
        (rt.other_options || []).forEach(function (o) {
            out.push({ id: rt.id, name: rt.name, rate: o.price, capacity: o.capacity, capacity_label: o.capacity_label, available_count: o.available_count, is_fully_booked: o.is_fully_booked,
                promos: o.promos || [], image_url: rt.image_url, _key: rt.id + ':' + o.option_key, option_key: o.option_key, _type: rt });
        });
        return out;
    };
    R.flatUnits = function (rooms) { return rooms.reduce(function (a, rt) { return a.concat(R.unitsOf(rt)); }, []); };
    function unitLabel(u) { return u.name + (u.option_key ? ' (up to ' + u.capacity + ' guests)' : ''); }

    function stepperInner(rt, qty, max) {
        if (max <= 0) return '';
        var atLimit = qty >= max, K = R.unitKey(rt);
        return '<span class="vs-qty-label" id="qtyl' + K + '">Rooms</span><div class="vs-qty" role="group" aria-labelledby="qtyl' + K + '">' +
            '<button type="button" class="btn btn-sm btn-outline-velocity" data-dec="' + K + '" aria-label="Fewer ' + VS.esc(unitLabel(rt)) + ' rooms"' + (qty ? '' : ' disabled') + '>\u2212</button>' +
            '<output class="vs-num fw-bold" aria-live="polite">' + qty + '</output>' +
            '<button type="button" class="btn btn-sm btn-outline-velocity" data-inc="' + K + '" aria-label="More ' + VS.esc(unitLabel(rt)) + ' rooms"' + (atLimit ? ' disabled' : '') + '>+</button></div>' +
            '<div class="vs-qty-msg small" role="status">' + (atLimit ? 'Only ' + VS.plural(max, 'room') + ' available for these dates.' : '') + '</div>';
    }

    /** "Other Deluxe rooms >": the rooms of this type with their own price or capacity, each with its own stepper, hidden until opened. */
    R.otherBlock = function (rt, sel) {
        var units = R.unitsOf(rt).slice(1);
        var open = sel && units.some(function (u) { return sel.qty(R.unitKey(u)) > 0; });
        var rows = units.map(function (u) {
            var K = R.unitKey(u), max = sel ? sel.max(u) : 0, free = u.is_fully_booked || (u.available_count || 0) <= 0 ? 0 : u.available_count;
            return '<div class="vs-other-row" data-other-row="' + VS.esc(K) + '"><div><div class="fw-semibold">Up to ' + u.capacity + ' guests</div>' +
                '<div class="small vs-num">' + VS.money(u.rate) + ' <span class="text-muted">per night</span></div>' +
                '<div class="small ' + (free ? 'text-success' : 'text-danger') + '">' + (free ? VS.plural(free, 'room') + ' available' : 'Fully booked') + '</div></div>' +
                (sel ? '<div class="vs-stepper" data-stepper="' + VS.esc(K) + '">' + stepperInner(u, sel.qty(K), max) + '</div>' : '') + '</div>';
        }).join('');
        return '<div class="vs-other"><button type="button" class="vs-other-toggle" aria-expanded="' + !!open + '" aria-controls="vsOther' + rt.id + '"><span>Other ' + VS.esc(rt.name) + ' rooms</span><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
            '<div class="vs-other-list" id="vsOther' + rt.id + '"' + (open ? '' : ' hidden') + '>' + rows + '</div></div>';
    };
    document.addEventListener('click', function (e) {
        var t = e.target.closest('.vs-other-toggle'); if (!t) return;
        var now = t.getAttribute('aria-expanded') !== 'true';
        t.setAttribute('aria-expanded', now); var l = document.getElementById(t.getAttribute('aria-controls')); if (l) l.hidden = !now;
    });

    /** One room card; `search` is the query string (dates) carried to the details page; `sel` (optional) adds the quantity stepper. */
    R.card = function (rt, search, sel) {
        var best = (rt.promos || []).filter(function (p) { return p.rate_after != null && Number(p.rate_after) < Number(rt.rate); })
            .sort(function (a, b) { return a.rate_after - b.rate_after; })[0];
        var price = best
            ? '<s>' + VS.money(rt.rate) + '</s>' + VS.money(best.rate_after)
            : VS.money(rt.rate);
        var href = '/rooms/' + rt.id + (search ? '?' + search : '');
        var step = sel ? '<div class="vs-stepper" data-stepper="' + rt.id + '">' + stepperInner(rt, sel.qty(rt.id), sel.max(rt)) + '</div>' : '';
        var others = (rt.other_options || []).length ? R.otherBlock(rt, sel) : '';
        return '<div class="col-sm-6 col-lg-4"><article class="vs-room vs-reveal" data-room="' + rt.id + '">' +
            '<a class="vs-room-photo" href="' + href + '" aria-label="' + VS.esc(rt.name) + ' details">' +
            '<img src="' + VS.esc(rt.image_url) + '" alt="' + VS.esc(rt.name) + '" loading="lazy">' + availChip(rt) + promoBadge(rt) + '</a>' +
            '<div class="vs-room-body"><h3>' + VS.esc(rt.name) + '</h3>' +
            '<div class="vs-room-facts">' + VS.esc(facts(rt)) + '</div>' +
            '<div class="vs-room-price">' + price + ' <small>per night</small></div>' + step + others +
            '<a class="btn btn-outline-velocity mt-auto" href="' + href + '">View details</a>' +
            '</div></article></div>';
    };

    /**
     * The dates and the room counts. cfg: { ci, co (date inputs), signed (bool) }.
     * sel.sync(list) clamps counts to what the dates allow, sel.bind(grid) wires the steppers, the sticky bar follows the counts.
     */
    R.selection = function (cfg) {
        var saved = R.loadSelection() || {};
        var earliest = VS.addDays(VS.today(), 2);
        var st = { check_in: saved.check_in && saved.check_in >= earliest ? saved.check_in : earliest, check_out: '', rooms: saved.rooms || {}, types: saved.types || {} };
        st.check_out = saved.check_out && saved.check_out > st.check_in ? saved.check_out : VS.addDays(st.check_in, 1);
        cfg.ci.min = earliest; cfg.ci.value = st.check_in; cfg.co.min = VS.addDays(st.check_in, 1); cfg.co.value = st.check_out;
        var list = [], bar = null, sel = {};

        function persist(intent) { if (!Object.keys(st.rooms).length && !intent) { R.clearSelection(); return; } var v = { check_in: st.check_in, check_out: st.check_out, rooms: st.rooms, types: st.types }; if (intent) v.intent = intent; R.saveSelection(v); }
        sel.qty = function (id) { return st.rooms[id] || 0; };
        sel.max = function (rt) { return rt.is_fully_booked ? 0 : Math.max(0, rt.available_count || 0); };
        sel.dates = function () { return { check_in: st.check_in, check_out: st.check_out }; };

        sel.sync = function (rooms) {
            list = R.flatUnits(rooms);
            var changed = false;
            Object.keys(st.rooms).forEach(function (id) {
                var rt = list.filter(function (r) { return R.unitKey(r) === String(id); })[0];
                var max = rt ? sel.max(rt) : 0;
                if (st.rooms[id] > max) { changed = true; if (max > 0) st.rooms[id] = max; else delete st.rooms[id]; }
            });
            list.forEach(function (r) { var k = R.unitKey(r); if (st.rooms[k]) st.types[k] = { name: unitLabel(r), rate: Number(r.rate) }; });
            persist(); paintBar();
            return changed;
        };

        function totals() {
            var ids = Object.keys(st.rooms).filter(function (id) { return st.rooms[id] > 0; });
            var n = Math.max(1, VS.nights(st.check_in, st.check_out)), rooms = 0, money = 0;
            ids.forEach(function (id) { rooms += st.rooms[id]; money += st.rooms[id] * ((st.types[id] || {}).rate || 0) * n; });
            return { types: ids.length, rooms: rooms, money: money };
        }

        function ensureBar() {
            if (bar) return bar;
            bar = document.createElement('div'); bar.className = 'vs-selbar'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Your room selection'); bar.hidden = true;
            bar.innerHTML = '<div class="vs-selbar-in"><div class="vs-selbar-text" aria-live="polite"></div><div class="vs-selbar-actions"><button type="button" class="btn btn-link vs-selbar-clear" data-clear-sel>Clear selection</button><button type="button" class="btn btn-velocity" data-go="book">Book now</button><button type="button" class="btn btn-outline-velocity" data-go="reserve">Reserve</button></div></div>';
            document.body.appendChild(bar);
            bar.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { go(b.dataset.go); }); });
            bar.querySelector('[data-clear-sel]').addEventListener('click', function () {
                var t = totals();
                VS.confirm({ title: 'Clear ' + VS.plural(t.rooms, 'selected room') + '?', message: 'The rooms you picked are removed. Your dates stay.', confirmText: 'Clear selection', cancelText: 'Keep them', danger: true }).then(function (yes) { if (yes) sel.clear(); });
            });
            return bar;
        }
        function paintBar() {
            var t = totals(), b = ensureBar();
            b.hidden = t.rooms === 0;
            document.body.classList.toggle('vs-has-selbar', t.rooms > 0);
            b.querySelector('.vs-selbar-text').innerHTML = '<strong>' + VS.plural(t.types, 'room type') + ' \u00b7 ' + VS.plural(t.rooms, 'room') + '</strong> \u00b7 ' + shortDate(st.check_in) + ' \u2192 ' + shortDate(st.check_out) + ' \u00b7 <span class="vs-num fw-bold">' + VS.money(t.money) + '</span>';
        }

        function promptSignIn(intent) {
            var wrap = document.createElement('div'); wrap.className = 'modal fade'; wrap.tabIndex = -1; wrap.setAttribute('aria-labelledby', 'vsSiTitle');
            wrap.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="vsSiTitle">Sign in to continue</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
                '<div class="modal-body">Sign in or create an account to ' + (intent === 'reserve' ? 'reserve' : 'book') + ' these rooms. We will keep your dates and rooms for 30 minutes.</div>' +
                '<div class="modal-footer border-0 pt-0"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><a class="btn btn-outline-velocity" href="/register">Create account</a><a class="btn btn-velocity" href="/login">Sign in</a></div></div></div>';
            document.body.appendChild(wrap);
            var m = new bootstrap.Modal(wrap); m.show(); wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
        }
        function go(intent) {
            var t = totals(); if (!t.rooms) return;
            persist(intent);
            if (cfg.signed) window.location.href = '/guest/' + (intent === 'reserve' ? 'reserve' : 'book') + '/new?sel=1';
            else promptSignIn(intent);
        }

        sel.bind = function (grid) {
            grid.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-inc],[data-dec]'); if (!btn) return;
                var id = btn.dataset.inc || btn.dataset.dec, rt = list.filter(function (r) { return R.unitKey(r) === String(id); })[0]; if (!rt) return;
                var K = R.unitKey(rt), q = sel.qty(K) + (btn.dataset.inc ? 1 : -1);
                q = Math.max(0, Math.min(sel.max(rt), q));
                if (q) { st.rooms[K] = q; st.types[K] = { name: unitLabel(rt), rate: Number(rt.rate) }; } else delete st.rooms[K];
                var host = grid.querySelector('[data-stepper="' + K + '"]');
                host.innerHTML = stepperInner(rt, q, sel.max(rt));
                var again = host.querySelector(btn.dataset.inc ? '[data-inc]' : '[data-dec]'); if (again && !again.disabled) again.focus();
                persist(); paintBar();
            });
        };

        sel.clear = function () {
            st.rooms = {}; st.types = {}; R.clearSelection();
            document.querySelectorAll('[data-stepper]').forEach(function (host) {
                var rt = list.filter(function (x) { return R.unitKey(x) === String(host.getAttribute('data-stepper')); })[0];
                if (rt) host.innerHTML = stepperInner(rt, 0, sel.max(rt));
            });
            paintBar(); VS.toast('Selection cleared.', 'success');
        };
        sel.setDates = function (ci, co) { st.check_in = ci; st.check_out = co; persist(); paintBar(); };
        sel.paintBar = paintBar;
        return sel;
    };

    R.load = function (query) {
        return VS.api('/rooms', { query: query || {} }).then(function (r) {
            if (!r.ok) return { error: r.message, rooms: [] };
            var rt = r.data.room_types;
            return { rooms: rt.data || rt, dates: { check_in: r.data.check_in, check_out: r.data.check_out } };
        });
    };

    /** Featured rooms on the landing page: the same rooms the app lists, with the dates and a room count on each card. */
    R.featured = function (el, opts) {
        opts = opts || {};
        var datesHost = document.getElementById('vsFeaturedDates');
        el.innerHTML = '<div class="col-12">' + VS.skeleton(3) + '</div>';
        var sel = null;
        if (datesHost) {
            datesHost.innerHTML = R.dateRow('fi');
            sel = R.selection({ ci: datesHost.querySelector('[data-ci]'), co: datesHost.querySelector('[data-co]'), signed: !!opts.signed });
            sel.bind(el);
        }
        function run() {
            var q = sel ? sel.dates() : {};
            el.innerHTML = '<div class="col-12">' + VS.skeleton(3) + '</div>';
            R.load(q).then(function (res) {
                if (res.error) { el.innerHTML = '<div class="col-12">' + VS.errorState(res.error, 'vsFeaturedRetry') + '</div>'; var b = document.getElementById('vsFeaturedRetry'); if (b) b.onclick = run; return; }
                if (!res.rooms.length) { el.innerHTML = '<div class="col-12">' + VS.empty('fa-bed', 'No rooms right now', 'Please check back later.') + '</div>'; return; }
                if (sel) sel.sync(res.rooms);
                el.innerHTML = res.rooms.slice(0, 6).map(function (rt) { return R.card(rt, sel ? new URLSearchParams(sel.dates()).toString() : '', sel); }).join('');
                VS.reveal(el);
            });
        }
        if (datesHost) R.bindDates(datesHost, sel, run);
        run();
    };

    /** The check-in / check-out pair (same rules as the app: earliest check-in is two days from today, at least one night). */
    R.dateRow = function (p) {
        return '<div class="row g-2 align-items-start vs-daterow"><div class="col-6 col-md-3"><label class="form-label small fw-semibold" for="' + p + 'In">Check-in</label><input type="date" class="form-control" id="' + p + 'In" data-ci></div>' +
            '<div class="col-6 col-md-3"><label class="form-label small fw-semibold" for="' + p + 'Out">Check-out</label><input type="date" class="form-control" id="' + p + 'Out" data-co></div>' +
            '<div class="col-12 col-md-6 small text-muted pt-md-4" data-nights aria-live="polite"></div></div>';
    };
    /** Keeps the two dates valid, tells how many nights, and calls `run` when a valid pair is chosen. */
    R.bindDates = function (host, sel, run) {
        var ci = host.querySelector('[data-ci]'), co = host.querySelector('[data-co]'), n = host.querySelector('[data-nights]');
        var earliest = VS.addDays(VS.today(), 2);
        function nights() { n.textContent = VS.plural(Math.max(1, VS.nights(ci.value, co.value)), 'night') + ' \u00b7 the earliest check-in is ' + VS.date(earliest); }
        function check(from) {
            VS.clearErrors(host);
            if (from === 'in' && ci.value && ci.value >= earliest && (!co.value || co.value <= ci.value)) co.value = VS.addDays(ci.value, 1);
            if (ci.value) co.min = VS.addDays(ci.value, 1);
            if (!ci.value || ci.value < earliest) { VS.fieldError(ci, 'The earliest check-in is ' + VS.date(earliest) + '.'); return false; }
            if (!co.value || co.value <= ci.value) { VS.fieldError(co, 'Stay at least 1 night: check-out must be after check-in.'); return false; }
            nights(); return true;
        }
        function change(from) { if (check(from)) { if (sel) sel.setDates(ci.value, co.value); run(); } }
        ci.addEventListener('change', function () { change('in'); }); co.addEventListener('change', function () { change('out'); });
        nights();
    };

    // ---- Room Details ----

    function promoBlock(rt) {
        if (!(rt.promos || []).length) return '';
        return '<section class="vs-card"><h2>Promos</h2>' + rt.promos.map(function (p) {
            return '<div class="mb-2"><div class="fw-bold">' + VS.esc(p.title || p.promo_name) + ' <span class="vs-chip">' + VS.esc(p.label || '') + '</span></div>' +
                (p.description ? '<div class="text-muted small">' + VS.esc(p.description) + '</div>' : '') +
                (p.rate_after != null && Number(p.rate_after) < Number(rt.rate) ? '<div class="small">' + VS.money(rt.rate) + ' → <strong>' + VS.money(p.rate_after) + '</strong> per night</div>' : '') +
                ((p.amenities || []).length ? '<div class="small text-muted">Includes ' + VS.esc(p.amenities.join(', ')) + '</div>' : '') +
                (p.valid_until ? '<div class="small text-muted">Valid until ' + VS.esc(VS.date(p.valid_until)) + '. Verified at check-in.</div>' : '') + '</div>';
        }).join('') + '</section>';
    }

    R.details = function (el, id, search) {
        el.innerHTML = VS.skeleton(5);
        var qs = new URLSearchParams(search || window.location.search);
        VS.api('/rooms/' + id, { query: { check_in: qs.get('check_in'), check_out: qs.get('check_out') } }).then(function (r) {
            if (!r.ok) { el.innerHTML = VS.errorState(r.status === 404 ? 'That room type is not available.' : r.message); return; }
            var rt = r.data.room_type;
            var gallery = (rt.gallery || []).map(function (g) { return g.url || g.image_url || g; }).filter(Boolean);
            if (!gallery.length) gallery = [rt.image_url];
            var thumbs = gallery.length > 1 ? '<div class="d-flex gap-2 mt-2 flex-wrap">' + gallery.map(function (u, i) {
                return '<button type="button" class="p-0 border-0 bg-transparent vs-thumb" data-src="' + VS.esc(u) + '" aria-label="Photo ' + (i + 1) + '"><img src="' + VS.esc(u) + '" alt="" style="width:76px;height:56px;object-fit:cover;border-radius:8px"></button>';
            }).join('') + '</div>' : '';
            var amen = (rt.amenities || []).length ? '<ul class="list-unstyled row row-cols-1 row-cols-sm-2 g-2 mb-0">' + rt.amenities.map(function (a) {
                return '<li class="col"><i class="fas fa-check text-success me-2"></i>' + VS.esc(a.name) + '</li>';
            }).join('') + '</ul>' : '<p class="text-muted mb-0">No amenities listed.</p>';
            var full = rt.is_fully_booked || rt.available_count <= 0;
            var book = '/guest/book/new?room=' + rt.id + (qs.get('check_in') ? '&check_in=' + qs.get('check_in') + '&check_out=' + (qs.get('check_out') || '') : '');
            var cta = full
                ? '<button class="btn btn-secondary w-100" disabled>Fully booked for these dates</button>'
                : '<a class="btn btn-velocity w-100 mb-2" href="' + book + '">Book this room</a><a class="btn btn-outline-velocity w-100" href="' + book.replace('/book/new', '/reserve/new') + '">Reserve this room</a>';
            el.innerHTML =
                '<div class="row g-4"><div class="col-lg-7">' +
                '<div class="vs-room-photo rounded-3" style="aspect-ratio:16/10"><img id="vsMainPhoto" src="' + VS.esc(gallery[0]) + '" alt="' + VS.esc(rt.name) + '">' + availChip(rt) + '</div>' + thumbs +
                '</div><div class="col-lg-5">' +
                '<h1 class="h3 fw-bold mb-1">' + VS.esc(rt.name) + '</h1>' +
                '<div class="vs-room-facts mb-2">' + VS.esc(facts(rt)) + '</div>' +
                '<div class="vs-room-price mb-3">' + VS.money(rt.rate) + ' <small>per night</small></div>' + cta + '</div></div>' +
                '<div class="row g-3 mt-1"><div class="col-lg-7">' + promoBlock(rt) +
                '<section class="vs-card"><h2>Overview</h2><p class="mb-0">' + VS.esc(rt.description || 'No description yet.') + '</p></section>' +
                '<section class="vs-card"><h2>Room facts</h2>' +
                '<div class="vs-row"><span>Guests</span><span>' + VS.esc(rt.capacity) + '</span></div>' +
                (rt.bed_type ? '<div class="vs-row"><span>Beds</span><span>' + VS.esc(rt.bed_type) + '</span></div>' : '') +
                (rt.room_size ? '<div class="vs-row"><span>Size</span><span>' + VS.esc(String(rt.room_size).trim()) + ' sqm</span></div>' : '') + '</section>' +
                '<section class="vs-card"><h2>Amenities</h2>' + amen + '</section>' +
                '<section class="vs-card"><h2>Policies</h2><ul class="mb-0 ps-3">' +
                (rt.policies ? '<li>' + VS.esc(rt.policies) + '</li>' : '') +
                '<li>Check-in from 2:00 PM, check-out until 12:00 PM.</li><li>The earliest check-in you can book is two days from today.</li>' +
                '<li>Cancelling a booking or reservation that was partly paid does not refund what was already paid.</li></ul></section>' +
                '</div></div>';
            el.querySelectorAll('.vs-thumb').forEach(function (b) {
                b.addEventListener('click', function () { document.getElementById('vsMainPhoto').src = b.getAttribute('data-src'); });
            });
        });
    };

    // ---- Rooms page ----

    R.page = function (root) {
        var grid = root.querySelector('#vsRoomGrid');
        var form = root.querySelector('#vsRoomFilters');
        var signed = root.dataset.signed === '1';
        var state = { sort: 'recommended' };
        var ci = form.querySelector('[name=check_in]'), co = form.querySelector('[name=check_out]');
        var sel = R.selection({ ci: ci, co: co, signed: signed });
        sel.bind(grid);

        function read() {
            var q = {};
            ['check_in', 'check_out', 'capacity', 'min_price', 'max_price'].forEach(function (n) { var v = form.elements[n].value; if (v) q[n] = v; });
            return q;
        }
        function paint(list) {
            var type = form.elements.room_type.value;
            if (type) list = list.filter(function (r) { return r.name === type; });
            if (form.elements.only_available.checked) list = list.filter(function (r) { return !r.is_fully_booked && r.available_count > 0; });
            list = list.slice().sort(function (a, b) {
                return state.sort === 'price_asc' ? a.rate - b.rate : state.sort === 'price_desc' ? b.rate - a.rate : state.sort === 'name' ? a.name.localeCompare(b.name) : 0;
            });
            var search = new URLSearchParams(sel.dates()).toString();
            var cnt = root.querySelector('#vsRoomCount'); if (cnt) cnt.textContent = 'Available Rooms (' + list.length + ')';
            grid.innerHTML = list.length ? list.map(function (r) { return R.card(r, search, sel); }).join('')
                : '<div class="col-12">' + VS.empty('fa-bed', 'No rooms match', 'Try other dates or clear the filters.') + '</div>';
            VS.reveal(grid);
        }
        var cache = [];
        function run() {
            var q = read();
            var earliest = VS.addDays(VS.today(), 2);
            VS.clearErrors(form);
            if (!q.check_in || q.check_in < earliest) { VS.fieldError(ci, 'The earliest check-in is ' + VS.date(earliest) + '.'); return; }
            if (!q.check_out || q.check_out <= q.check_in) { VS.fieldError(co, 'Stay at least 1 night: check-out must be after check-in.'); return; }
            sel.setDates(q.check_in, q.check_out);
            grid.innerHTML = '<div class="col-12">' + VS.skeleton(4) + '</div>';
            R.load(q).then(function (res) {
                if (res.error) { grid.innerHTML = '<div class="col-12">' + VS.errorState(res.error, 'vsRoomsRetry') + '</div>'; document.getElementById('vsRoomsRetry').onclick = run; return; }
                cache = res.rooms;
                var names = Array.from(new Set(cache.map(function (r) { return r.name; })));
                var sl = form.elements.room_type, cur = sl.value;
                sl.innerHTML = '<option value="">All room types</option>' + names.map(function (n) { return '<option>' + VS.esc(n) + '</option>'; }).join('');
                sl.value = cur;
                var clamped = sel.sync(cache);
                paint(cache);
                var note = root.querySelector('#vsSelNote');
                if (note) note.innerHTML = clamped ? '<div class="alert alert-warning vs-alert mb-3" role="status">Some rooms are not available for these dates, so we lowered your selection.</div>' : '';
            });
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
        form.elements.sort.addEventListener('change', function () { state.sort = this.value; paint(cache); });
        form.elements.room_type.addEventListener('change', function () { paint(cache); });
        form.elements.only_available.addEventListener('change', function () { paint(cache); });
        form.querySelector('[data-clear]').addEventListener('click', function () { form.reset(); sel.setDates(VS.addDays(VS.today(), 2), VS.addDays(VS.today(), 3)); ci.value = VS.addDays(VS.today(), 2); co.value = VS.addDays(VS.today(), 3); run(); });
        ci.addEventListener('change', function () { if (ci.value) { co.min = VS.addDays(ci.value, 1); if (!co.value || co.value <= ci.value) co.value = VS.addDays(ci.value, 1); run(); } });
        co.addEventListener('change', function () { run(); });
        run();
    };
})();
