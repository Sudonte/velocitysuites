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

    /** One room card; `search` is the query string (dates) carried to the details page. */
    R.card = function (rt, search) {
        var best = (rt.promos || []).filter(function (p) { return p.rate_after != null && Number(p.rate_after) < Number(rt.rate); })
            .sort(function (a, b) { return a.rate_after - b.rate_after; })[0];
        var price = best
            ? '<s>' + VS.money(rt.rate) + '</s>' + VS.money(best.rate_after)
            : VS.money(rt.rate);
        var href = '/rooms/' + rt.id + (search ? '?' + search : '');
        return '<div class="col-sm-6 col-lg-4"><article class="vs-room vs-reveal">' +
            '<a class="vs-room-photo" href="' + href + '" aria-label="' + VS.esc(rt.name) + ' details">' +
            '<img src="' + VS.esc(rt.image_url) + '" alt="' + VS.esc(rt.name) + '" loading="lazy">' + availChip(rt) + promoBadge(rt) + '</a>' +
            '<div class="vs-room-body"><h3>' + VS.esc(rt.name) + '</h3>' +
            '<div class="vs-room-facts">' + VS.esc(facts(rt)) + '</div>' +
            '<div class="vs-room-price">' + price + ' <small>per night</small></div>' +
            '<a class="btn btn-outline-velocity mt-auto" href="' + href + '">View details</a>' +
            '</div></article></div>';
    };

    R.load = function (query) {
        return VS.api('/rooms', { query: query || {} }).then(function (r) {
            if (!r.ok) return { error: r.message, rooms: [] };
            var rt = r.data.room_types;
            return { rooms: rt.data || rt, dates: { check_in: r.data.check_in, check_out: r.data.check_out } };
        });
    };

    /** Featured rooms on the landing page: the same rooms the app lists, in the same order. */
    R.featured = function (el) {
        el.innerHTML = '<div class="col-12">' + VS.skeleton(3) + '</div>';
        R.load({}).then(function (res) {
            if (res.error) { el.innerHTML = '<div class="col-12">' + VS.errorState(res.error, 'vsFeaturedRetry') + '</div>'; var b = document.getElementById('vsFeaturedRetry'); if (b) b.onclick = function () { R.featured(el); }; return; }
            if (!res.rooms.length) { el.innerHTML = '<div class="col-12">' + VS.empty('fa-bed', 'No rooms right now', 'Please check back later.') + '</div>'; return; }
            el.innerHTML = res.rooms.slice(0, 6).map(function (rt) { return R.card(rt); }).join('');
            VS.reveal(el);
        });
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
        var state = { sort: 'name' };
        var today = VS.today();
        var minIn = VS.addDays(today, 2);
        var ci = form.querySelector('[name=check_in]'), co = form.querySelector('[name=check_out]');
        ci.min = minIn; co.min = VS.addDays(minIn, 1);

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
                return state.sort === 'price_asc' ? a.rate - b.rate : state.sort === 'price_desc' ? b.rate - a.rate : a.name.localeCompare(b.name);
            });
            var search = new URLSearchParams(read()).toString();
            grid.innerHTML = list.length ? list.map(function (r) { return R.card(r, search); }).join('')
                : '<div class="col-12">' + VS.empty('fa-bed', 'No rooms match', 'Try other dates or clear the filters.') + '</div>';
            VS.reveal(grid);
        }
        var cache = [];
        function run() {
            var q = read();
            if (q.check_in && q.check_in < minIn) { VS.fieldError(ci, 'The earliest check-in is ' + VS.date(minIn) + '.'); return; }
            VS.clearErrors(form);
            if (q.check_in && q.check_out && q.check_out <= q.check_in) { VS.fieldError(co, 'Check-out must be after check-in.'); return; }
            grid.innerHTML = '<div class="col-12">' + VS.skeleton(4) + '</div>';
            R.load(q).then(function (res) {
                if (res.error) { grid.innerHTML = '<div class="col-12">' + VS.errorState(res.error, 'vsRoomsRetry') + '</div>'; document.getElementById('vsRoomsRetry').onclick = run; return; }
                cache = res.rooms;
                var names = Array.from(new Set(cache.map(function (r) { return r.name; })));
                var sel = form.elements.room_type, cur = sel.value;
                sel.innerHTML = '<option value="">All room types</option>' + names.map(function (n) { return '<option>' + VS.esc(n) + '</option>'; }).join('');
                sel.value = cur;
                paint(cache);
            });
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
        form.elements.sort.addEventListener('change', function () { state.sort = this.value; paint(cache); });
        form.elements.room_type.addEventListener('change', function () { paint(cache); });
        form.elements.only_available.addEventListener('change', function () { paint(cache); });
        form.querySelector('[data-clear]').addEventListener('click', function () { form.reset(); run(); });
        ci.addEventListener('change', function () { if (ci.value) { co.min = VS.addDays(ci.value, 1); if (co.value && co.value <= ci.value) co.value = VS.addDays(ci.value, 1); } });
        run();
    };
})();
