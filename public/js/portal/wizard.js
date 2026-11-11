/*
 * New booking (4 steps, with the optional Amenities step) and new reservation (4 steps, no amenities) on the web - the same steps, rules and wording as the app. Rooms and rates
 * come from GET /api/rooms, amenities from GET /api/amenities, discounts from GET /api/discounts, and the request goes to the app's
 * own POST /api/guest/reservations (a booking continues to the payment page, which posts to /api/guest/bookings).
 */
(function () {
    'use strict';
    var VS = window.VS;
    var W = window.VSWizard = {};
    var MAX_STAYS = 6;
    var KEY = 'vs_wizard_';

    // ---------------------------------------------------------------- image helper (camera or file, max 1600px, JPEG 80%)

    VS.compressImage = function (file) {
        return new Promise(function (resolve) {
            if (!/^image\//.test(file.type) || file.type === 'image/gif') { resolve(file); return; }
            var img = new Image(), url = URL.createObjectURL(file);
            img.onload = function () {
                var max = 1600, w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, max / Math.max(w, h));
                var c = document.createElement('canvas'); c.width = Math.round(w * s); c.height = Math.round(h * s);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { resolve(b ? new File([b], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.8);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    };

    // ---------------------------------------------------------------- state

    function newState(mode) {
        return { v: 3, mode: mode, step: 0, stays: [], draft: { check_in: '', check_out: '', rooms: {}, editing: null }, amenities: {}, // amenities[stayIndex] = [{amenity_id, quantity, room_type_id, room_slot}]
            rep: { who: 'owner', first: '', middle: '', last: '' }, adults: 1, minors: [], discount_id: '', idFile: null, payment_method: '', key: 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) };
    }

    function nights(s) { return Math.max(1, VS.nights(s.check_in, s.check_out)); }
    function overlap(a, b) { return a.check_in < b.check_out && b.check_in < a.check_out; }

    W.init = function (root, mode, editId) {
        var S = newState(mode);
        var DK = KEY + mode + (editId ? '_edit' + editId : '');
        var stepsDef = mode === 'booking'
            ? ['Dates and rooms', 'Amenities', 'Representative and guests', 'Review and bill summary']
            : ['Dates and rooms', 'Representative and guests', 'Payment method', 'Review and bill summary'];
        var cache = { rooms: {}, amenities: null, discounts: null, profile: null };
        var main = root.querySelector('#vsWizMain'), bar = root.querySelector('#vsWizBar'), line = root.querySelector('#vsWizLine'), sum = root.querySelector('#vsWizSummary');
        var earliest = VS.addDays(VS.today(), 2);

        // restore a draft after a refresh
        var draftReset = false;
        try {
            var saved = editId ? null : JSON.parse(sessionStorage.getItem(DK) || 'null');
            // a draft saved by an older form (other steps) has no version 3 marker: start fresh, with a short message
            if (saved && saved.v !== 3) { sessionStorage.removeItem(DK); draftReset = true; }
            else if (saved && saved.stays) { S = Object.assign(S, saved); }
        } catch (e) { /* no draft */ }
        var pre = new URLSearchParams(window.location.search);
        // Straight to the wizard with no dates and rooms (a New booking button, a typed address): dates come first, on the Rooms page
        if (!editId && !pre.get('sel') && !pre.get('room') && !(S.stays && S.stays.length) && !(S.draft && S.draft.check_in)) {
            window.__vsLeavingOk = true; window.location.replace('/guest/rooms?dates_first=1&mode=' + (mode === 'reservation' ? 'reserve' : 'book') + (draftReset ? '&reset=1' : '')); return;
        }
        function save() { try { sessionStorage.setItem(DK, JSON.stringify(S)); } catch (e) { /* storage full or blocked */ } }

        // ---- data
        function roomsFor(ci, co) {
            var k = ci + '|' + co;
            if (cache.rooms[k]) return Promise.resolve(cache.rooms[k]);
            return VS.api('/rooms', { query: { check_in: ci, check_out: co } }).then(function (r) {
                if (!r.ok) return null;
                var list = r.data.room_types.data || r.data.room_types;
                cache.rooms[k] = list; return list;
            });
        }
        function allTypes() { // type info (rate, capacity, name) from any date range
            var map = {}; Object.keys(cache.rooms).forEach(function (k) { cache.rooms[k].forEach(function (t) { window.VSRooms.unitsOf(t).forEach(function (u) { map[window.VSRooms.unitKey(u)] = u; }); }); }); return map;
        }
        function loadAmenities() {
            if (cache.amenities) return Promise.resolve(cache.amenities);
            return VS.api('/amenities').then(function (r) { cache.amenities = r.ok ? r.data : []; return cache.amenities; });
        }
        function loadDiscounts() {
            if (cache.discounts) return Promise.resolve(cache.discounts);
            return VS.api('/discounts').then(function (r) { cache.discounts = r.ok ? r.data : []; return cache.discounts; });
        }
        function loadProfile() {
            if (cache.profile) return Promise.resolve(cache.profile);
            return VS.api('/guest/profile').then(function (r) { cache.profile = r.ok ? (r.data.user || r.data) : {}; return cache.profile; });
        }

        // ---- money (same arithmetic the server uses: rooms x booked nights, plus paid amenities)
        // a key is the room type id ('12'), or '12:r4200c4' for one of its other options
        function unitName(t) { return t.option_key ? t.name + ' (up to ' + t.capacity + ' guests)' : t.name; }
        function stayRooms(s) { return Object.keys(s.rooms).map(function (id) { var p = String(id).split(':'); return { id: id, typeId: +p[0], option: p[1] || null, qty: s.rooms[id] }; }).filter(function (x) { return x.qty > 0; }); }
        function roomsTotal(types) {
            return S.stays.reduce(function (sum, s) { return sum + stayRooms(s).reduce(function (a, r) { return a + (types[r.id] ? Number(types[r.id].rate) * nights(s) * r.qty : 0); }, 0); }, 0);
        }
        function amenitiesTotal() {
            var by = {}; (cache.amenities || []).forEach(function (a) { by[a.id] = a; });
            var t = 0; Object.keys(S.amenities).forEach(function (i) { (S.amenities[i] || []).forEach(function (a) { t += (by[a.amenity_id] ? Number(by[a.amenity_id].charge) : 0) * a.quantity; }); }); return t;
        }
        function paintSummary() {
            var types = allTypes(), r = roomsTotal(types), a = amenitiesTotal();
            // nothing confirmed yet: no total (a zero would read as a price for dates that were not chosen)
            if (!S.stays.length && S.step === 0) { sum.innerHTML = '<div class="text-muted small">' + (S.draft && S.draft.check_in && S.draft.check_out ? 'Confirm these dates to see the total' : 'Choose your dates to see the total') + '</div>'; return; }
            sum.innerHTML = '<div class="vs-row"><span>Rooms</span><span>' + VS.money(r) + '</span></div>' + (a > 0 ? '<div class="vs-row"><span>Amenities</span><span>' + VS.money(a) + '</span></div>' : '') +
                '<div class="vs-row vs-strong"><span>Grand total</span><span class="vs-pay">' + VS.money(r + a) + '</span></div>';
        }

        // ---- frame
        function frame() {
            bar.innerHTML = stepsDef.map(function (_, i) { return '<li class="' + (i < S.step ? 'done' : i === S.step ? 'now' : '') + '"></li>'; }).join('');
            line.textContent = 'Step ' + (S.step + 1) + ' of ' + stepsDef.length + ' · ' + stepsDef[S.step];
            save(); paintSummary();
        }
        function go(n) {
            S.step = n; frame(); render();
            root.style.scrollMarginTop = '70px'; root.scrollIntoView({ block: 'start', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); // (the shell scrolls in its own box, so not window.scrollTo)
        }
        function nav(opts) {
            return '<div class="d-flex justify-content-between mt-4 gap-2">' + (S.step > 0 ? '<button class="btn btn-light" data-back>Back</button>' : '<button type="button" class="btn btn-light" data-cancel>Cancel</button>') +
                '<button class="btn btn-velocity" data-next>' + (opts && opts.label || 'Continue') + '</button></div>';
        }
        function bindNav(onNext) {
            var n = main.querySelector('[data-next]'), b = main.querySelector('[data-back]');
            if (b) b.onclick = function () { go(S.step - 1); };
            if (n) n.onclick = function () { onNext(n); };
            var cx = main.querySelector('[data-cancel]');
            if (cx) cx.onclick = function () {
                VS.confirm({ title: 'Cancel this ' + (editId ? 'edit' : mode) + '?', message: editId ? 'Your changes are not saved.' : 'What you chose so far, including your selected rooms, is cleared.', confirmText: 'Cancel ' + (editId ? 'edit' : mode), cancelText: 'Keep going', danger: true }).then(function (yes) {
                    if (!yes) return;
                    abandon(); window.location.href = editId ? '/guest/reservations' : '/guest/rooms';
                });
            };
        }
        // Cancelling, finishing or leaving for good: the wizard's draft and the room selection are deleted from every storage
        function abandon() { try { sessionStorage.removeItem(DK); } catch (e) { /* ignore */ } if (window.VSRooms) window.VSRooms.clearSelection(); window.__vsLeavingOk = true; }
        function errorBox(msg) { var e = main.querySelector('#vsStepErr'); if (e) VS.alertBox(e, msg); if (msg && e) e.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); }

        function render() { (mode === 'booking' ? [stepStays, stepAmenities, stepRepGuests, stepReview] : [stepStays, stepRepGuests, stepPaymentMethod, stepReview])[S.step](); }

        // ================================================= STEP 1 - dates and rooms
        function stepStays() {
            var d = S.draft;
            main.innerHTML = '<h2 class="vs-section-title">Dates and rooms</h2><p class="text-muted">Choose your check-in and check-out dates to see available rooms. You can add more than one date range; each one is listed below.</p><div id="vsStepErr"></div>' +
                '<div id="vsStayList"></div><section class="vs-card" id="vsEditor"></section><p class="vs-next-reason small fw-semibold text-muted text-end mb-0 mt-3" id="vsNextReason" role="status" hidden></p>' + nav();
            paintStays(); paintEditor();
            bindNav(function () {
                var cur = S.draft;
                if (cur.check_in && Object.keys(cur.rooms).some(function (k) { return cur.rooms[k] > 0; })) { errorBox('Confirm this stay first, or clear its rooms.'); return; }
                if (!S.stays.length) { errorBox('Add at least one stay: choose dates and rooms, then confirm it.'); return; }
                reviewStays().then(function (yes) { if (yes) go(1); });
            });
        }

        // The short review after Next on Step 1 (the same sheet, words and order as the app): each date range with its rooms and subtotal,
        // the total for all dates and the promos; "Edit dates and rooms" stays on the step, "Continue" asks "Proceed to ...?".
        function shortDate(v) { return VS.date(v).replace(/, \d{4}$/, ''); }
        function reviewStays() {
            var types = allTypes(), rooms = 0, html = '';
            S.stays.forEach(function (s) {
                var sub = 0;
                html += '<h3 class="h6 fw-bold mt-3 mb-1">' + VS.esc(shortDate(s.check_in) + ' \u2192 ' + shortDate(s.check_out) + ' \u00b7 ' + VS.plural(nights(s), 'night')) + '</h3>';
                stayRooms(s).forEach(function (rm) {
                    var t = types[rm.id] || { name: 'Room', rate: 0 }; rooms += rm.qty; sub += Number(t.rate) * nights(s) * rm.qty;
                    html += '<div class="vs-row"><span>' + VS.esc(rm.qty + ' \u00d7 ' + unitName(t) + ' \u00d7 ' + VS.money(t.rate) + ' \u00d7 ' + VS.plural(nights(s), 'night')) + '</span><span>' + VS.money(Number(t.rate) * nights(s) * rm.qty) + '</span></div>';
                });
                html += '<div class="vs-row fw-bold"><span>Subtotal</span><span>' + VS.money(sub) + '</span></div>';
            });
            var total = roomsTotal(types), promos = promoNotes(types);
            html += '<hr class="vs-rule"><div class="vs-row fw-bold"><span>Total for all dates</span><span>' + VS.money(total) + '</span></div>';
            if (promos.length) {
                html += '<div class="fw-bold mt-2">Promos for your rooms (verified at check-in)</div>';
                promos.forEach(function (x) {
                    var where = x.p.room_type_name || 'every room type';
                    html += '<div class="vs-row"><span>' + VS.esc(x.p.title) + '</span><span>' + VS.esc(x.p.label ? x.p.label + ' \u00b7 ' + where : where) + '</span></div>';
                    if (x.save > 0) html += '<div class="text-muted small ms-3">Estimated saving ' + VS.money(x.save) + '. Not subtracted until the front desk verifies it.</div>';
                });
                html += '<div class="text-muted small ms-3 mt-1">Promos are checked by the front desk at check-in. The Grand total stays whole until then.</div>';
            }
            var next = mode === 'booking' && !editId ? 'Amenities' : 'Representative and guests';
            return VS.confirm({ title: 'Review your dates and rooms', html: html, confirmText: 'Continue', cancelText: 'Edit dates and rooms' }).then(function (yes) {
                if (!yes) return false;
                return VS.confirm({ title: 'Proceed to ' + next + '?', message: VS.plural(S.stays.length, 'stay') + ' \u00b7 ' + VS.plural(rooms, 'room') + ' \u00b7 ' + VS.money(total), confirmText: 'Yes, continue' });
            });
        }

        function paintStays() {
            var host = main.querySelector('#vsStayList'), types = allTypes();
            var editing = S.draft && S.draft.editing != null ? S.draft.editing : null;
            var head = '<h3 class="h5 fw-bold mb-2" id="vsYourDates">Your dates and rooms</h3>';
            orderBlocks();
            if (!S.stays.length && S.draft && S.draft.carried && Object.keys(S.draft.rooms).length) { host.innerHTML = ''; updateNext(); return; } // the Selected rooms card says it
            if (!S.stays.length) {
                host.innerHTML = '<section class="vs-datelist" aria-labelledby="vsYourDates">' + head + '<div class="vs-datelist-empty"><i class="fas fa-calendar-plus"></i><div><div class="fw-semibold">Nothing chosen yet</div><div class="small text-muted">Pick your check-in and check-out dates above, choose your rooms, then confirm. Each date range appears here as its own card.</div></div></div></section>';
                return;
            }
            host.innerHTML = '<section class="vs-datelist" aria-labelledby="vsYourDates">' + head + '<p class="small text-muted">' + VS.plural(S.stays.length, 'date range') + ' · check everything here before you continue.</p>' + S.stays.map(function (s, i) {
                var n = nights(s), sub = 0;
                var lines = stayRooms(s).map(function (r) {
                    var t = types[r.id], rate = t ? Number(t.rate) : 0, line = rate * n * r.qty; sub += line;
                    return '<div class="vs-dl-room"><div><span class="fw-semibold">' + VS.esc(t ? unitName(t) : 'Room') + '</span> <span class="text-muted">× ' + r.qty + '</span><div class="small text-muted vs-num">' + VS.money(rate) + ' per night · ' + VS.plural(n, 'night') + '</div></div><div class="vs-num fw-semibold text-end">' + VS.money(line) + '</div></div>';
                }).join('');
                var on = editing === i;
                return '<article class="vs-dl-card' + (on ? ' is-editing' : '') + '" aria-label="' + VS.esc(VS.date(s.check_in) + ' to ' + VS.date(s.check_out)) + '">' +
                    '<header class="vs-dl-head"><div><div class="fw-bold">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + ' <span class="text-muted fw-normal">· ' + VS.plural(n, 'night') + '</span></div>' + (on ? '<span class="vs-chip">Editing now</span>' : '') + '</div>' +
                    '<div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-velocity" data-add-room="' + i + '">Add room</button><button type="button" class="btn btn-sm btn-outline-velocity" data-edit="' + i + '"' + (on ? ' disabled' : '') + '>Edit</button><button type="button" class="btn btn-sm btn-light" data-remove="' + i + '">Remove</button></div></header>' +
                    lines + '<div class="vs-dl-sub"><span>Subtotal for these dates</span><span class="vs-num fw-bold">' + VS.money(sub) + '</span></div></article>';
            }).join('') + '<div class="vs-dl-total"><span>Total for all dates</span><span class="vs-num">' + VS.money(roomsTotal(types)) + '</span></div>' +
                '<div class="mt-2"><button type="button" class="btn btn-outline-velocity" data-add-date>Add another date</button></div></section>';
            updateNext();
            host.querySelectorAll('[data-edit]').forEach(function (b) { b.onclick = function () {
                var s = S.stays[+b.dataset.edit]; S.draft = { check_in: s.check_in, check_out: s.check_out, rooms: JSON.parse(JSON.stringify(s.rooms)), editing: +b.dataset.edit }; save(); paintStays(); paintEditor();
                main.querySelector('#vsEditor').scrollIntoView({ behavior: VS.reducedMotion ? 'auto' : 'smooth' });
            }; });
            host.querySelectorAll('[data-add-room]').forEach(function (b) { b.onclick = function () {
                var s = S.stays[+b.dataset.addRoom]; S.draft = { check_in: s.check_in, check_out: s.check_out, rooms: JSON.parse(JSON.stringify(s.rooms)), editing: +b.dataset.addRoom }; save(); paintStays(); paintEditor();
                var pick = main.querySelector('#vsRoomPick'); if (pick) pick.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' });
            }; });
            var addDate = host.querySelector('[data-add-date]');
            if (addDate) addDate.onclick = function () {
                if (S.draft.editing != null) { S.draft = { check_in: '', check_out: '', rooms: {}, editing: null }; save(); paintStays(); paintEditor(); }
                var i = main.querySelector('#wIn'); if (i) { i.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); i.focus({ preventScroll: true }); }
            };
            host.querySelectorAll('[data-remove]').forEach(function (b) { b.onclick = function () {
                var i = +b.dataset.remove, s = S.stays[i];
                VS.confirm({ title: 'Remove ' + VS.date(s.check_in) + ' → ' + VS.date(s.check_out) + '?', message: 'Its dates, rooms and amenities are removed from this booking.', confirmText: 'Remove', danger: true }).then(function (yes) {
                    if (!yes) return; S.stays.splice(i, 1); delete S.amenities[i]; reindexAmenities(i); S.draft.editing = null; save(); frame(); paintStays(); paintEditor();
                });
            }; });
        }
        /** While the first date range is not confirmed the date step is FIRST on the page; once there is a confirmed range, the list of ranges comes first. */
        function orderBlocks() {
            var list = main.querySelector('#vsStayList'), ed = main.querySelector('#vsEditor');
            if (!list || !ed) return;
            if (!S.stays.length) main.insertBefore(ed, list); else main.insertBefore(list, ed);
        }
        function datesOk(d) { return !!(d.check_in && d.check_out && d.check_out > d.check_in); }
        /** Next stays off until the dates are confirmed, with the reason in one short line above it. */
        function updateNext() {
            var n = main.querySelector('[data-next]'), r = main.querySelector('#vsNextReason');
            if (!n || !r || S.step !== 0) return;
            var d = S.draft, pending = Object.keys(d.rooms).some(function (k) { return d.rooms[k] > 0; }), reason = '';
            if (pending) reason = datesOk(d) ? 'Confirm these dates to continue' : 'Choose your dates first';
            else if (!S.stays.length) reason = datesOk(d) ? 'Choose at least one room' : 'Choose your dates first';
            n.disabled = !!reason; r.textContent = reason; r.hidden = !reason;
        }
        function rateOf(d, id) { var t = (d.types || {})[id] || allTypes()[id]; return t ? Number(t.rate) : 0; }
        function nameOf(d, id) { var t = (d.types || {})[id] || allTypes()[id]; return t ? (t.name || unitName(t)) : 'Room'; }
        /** The rooms the guest arrived with: "Deluxe Room × 2 · ₱1,200.00 per night", no night count and no total until the dates are chosen. */
        function paintSelectedRooms() {
            var host = main.querySelector('#vsSelRooms'); if (!host) return;
            var d = S.draft, ids = Object.keys(d.rooms).filter(function (k) { return d.rooms[k] > 0; });
            if (S.stays.length || !d.carried || !ids.length) { host.innerHTML = ''; return; }
            var ok = datesOk(d), n = ok ? nights(d) : 0, count = ids.reduce(function (a, k) { return a + d.rooms[k]; }, 0), total = ids.reduce(function (a, k) { return a + d.rooms[k] * rateOf(d, k) * n; }, 0);
            host.innerHTML = '<section class="vs-selrooms" aria-labelledby="vsSelRoomsTitle"><header><h3 class="h6 fw-bold mb-0" id="vsSelRoomsTitle">Selected rooms</h3><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-velocity" data-sr-edit>Edit</button><button type="button" class="btn btn-sm btn-light" data-sr-remove>Remove</button></div></header>' +
                ids.map(function (k) { return '<div class="vs-sr-line">' + VS.esc(nameOf(d, k)) + ' \u00d7 ' + d.rooms[k] + ' \u00b7 <span class="vs-num">' + VS.money(rateOf(d, k)) + ' per night</span></div>'; }).join('') +
                '<div class="vs-sr-total vs-num">' + (ok ? VS.esc(VS.plural(count, 'room') + ' \u00d7 ' + VS.plural(n, 'night') + ' = ' + VS.money(total)) : 'Choose your dates to see the total') + '</div></section>';
            host.querySelector('[data-sr-edit]').onclick = function () { var i = main.querySelector('#wIn'); if (i) { i.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); i.focus(); } };
            host.querySelector('[data-sr-remove]').onclick = function () {
                VS.confirm({ title: 'Remove the selected rooms?', message: 'The rooms you selected are removed from this booking.', confirmText: 'Remove', danger: true }).then(function (yes) {
                    if (!yes) return; d.rooms = {}; d.types = {}; d.carried = false; d.note = ''; window.VSRooms.clearSelection(); save(); paintStays(); paintEditor();
                });
            };
        }
        function reindexAmenities(removed) { var n = {}; Object.keys(S.amenities).forEach(function (k) { k = +k; if (k < removed) n[k] = S.amenities[k]; else if (k > removed) n[k - 1] = S.amenities[k]; }); S.amenities = n; }

        function paintEditor() {
            var d = S.draft, host = main.querySelector('#vsEditor');
            var title = d.editing != null ? 'Edit ' + VS.date(S.stays[d.editing].check_in) + ' → ' + VS.date(S.stays[d.editing].check_out) : (S.stays.length ? 'Add another date' : (d.carried ? 'Choose your dates' : 'Choose dates and rooms'));
            var hint = d.editing == null && !S.stays.length && d.carried && !datesOk(d) ? '<p class="text-muted mb-2">Pick check-in and check-out for the rooms you selected</p>' : '';
            host.innerHTML = '<h3 class="h6 fw-bold">' + VS.esc(title) + '</h3>' + hint + '<div class="row g-3 mb-2"><div class="col-6"><label class="form-label small fw-semibold" for="wIn">Check-in</label><input type="date" class="form-control" id="wIn" min="' + earliest + '" value="' + VS.esc(d.check_in) + '"></div>' +
                '<div class="col-6"><label class="form-label small fw-semibold" for="wOut">Check-out</label><input type="date" class="form-control" id="wOut" min="' + VS.addDays(d.check_in || earliest, 1) + '" value="' + VS.esc(d.check_out) + '"></div></div><div id="vsDateErr"></div><div id="vsSelRooms"></div><div id="vsRoomPick"></div>';
            var ci = host.querySelector('#wIn'), co = host.querySelector('#wOut');
            function changed() {
                VS.clearErrors(host);
                if (ci.value && ci.value < earliest) { VS.fieldError(ci, 'The earliest check-in is ' + VS.date(earliest) + '.'); d.check_in = ''; return; }
                d.check_in = ci.value; if (ci.value) { co.min = VS.addDays(ci.value, 1); if (co.value && co.value <= ci.value) co.value = ''; }
                d.check_out = co.value;
                if (d.check_in && d.check_out && d.check_out <= d.check_in) { VS.fieldError(co, 'Check-out must be after check-in.'); d.check_out = ''; }
                // rooms the guest arrived with stay selected (and are re-checked for the new dates); a plain draft starts its room choice again
                if (d.carried) { d.recheck = true; d.note = ''; } else d.rooms = {};
                save(); paintSelectedRooms(); paintRooms();
            }
            ci.onchange = changed; co.onchange = changed;
            paintSelectedRooms(); paintRooms();
        }

        function paintRooms() {
            var d = S.draft, host = main.querySelector('#vsRoomPick');
            paintSummary(); // "Choose your dates" / "Confirm these dates" follows the date fields
            if (!d.check_in || !d.check_out) { host.innerHTML = '<p class="text-muted mb-0">Pick both dates to see the rooms.</p>'; updateNext(); return; }
            host.innerHTML = VS.skeleton(3);
            roomsFor(d.check_in, d.check_out).then(function (list) {
                if (!list) { host.innerHTML = VS.errorState('We could not load the rooms.', 'vsRoomsRetry'); host.querySelector('#vsRoomsRetry').onclick = paintRooms; return; }
                var others = S.stays.filter(function (s, i) { return i !== d.editing && overlap(s, d); });
                function usedElsewhere(key) { return others.reduce(function (a, s) { return a + (s.rooms[key] || 0); }, 0); }
                var units = window.VSRooms.flatUnits(list), keyOf = window.VSRooms.unitKey;
                if (d.carried && d.recheck) {
                    // the rooms the guest arrived with, against what is free for these dates: lowered with a note, or dropped with a note
                    d.recheck = false; var notes = [];
                    Object.keys(d.rooms).forEach(function (K) {
                        var t = units.filter(function (x) { return keyOf(x) === K; })[0], want = d.rooms[K], label = t ? (t.option_key ? t.name + ' (other, up to ' + t.capacity + ' guests)' : t.name) : nameOf(d, K);
                        var free = t ? Math.max(0, (t.is_fully_booked ? 0 : (t.available_count || 0)) - usedElsewhere(K)) : 0;
                        if (want <= free) return;
                        if (free > 0) { d.rooms[K] = free; notes.push('Only ' + free + ' of ' + label + ' is free for these dates, so your selection was lowered from ' + want + ' to ' + free + '.'); }
                        else { delete d.rooms[K]; notes.push(label + ' is not free for these dates, so it was removed from your selection.'); }
                    });
                    d.note = notes.join(' '); save(); paintSelectedRooms();
                }
                var rows = units.map(function (t) {
                    var K = keyOf(t), free = Math.max(0, (t.is_fully_booked ? 0 : (t.available_count || 0)) - usedElsewhere(K)), qty = d.rooms[K] || 0;
                    var label = t.name + (t.option_key ? ' (other, up to ' + t.capacity + ' guests)' : '');
                    var promo = (t.promos || []).length ? '<span class="vs-chip ms-1"><i class="fas fa-tag"></i> ' + VS.plural(t.promos.length, 'promo') + '</span>' : '';
                    return '<div class="vs-card py-2 d-flex gap-3 align-items-center' + (t.option_key ? ' vs-other-unit' : '') + '" data-type="' + VS.esc(K) + '"><img src="' + VS.esc(t.image_url) + '" alt="" style="width:84px;height:64px;object-fit:cover;border-radius:10px">' +
                        '<div class="flex-grow-1"><div class="fw-bold">' + VS.esc(t.option_key ? t.name + ' \u00b7 other room' : t.name) + promo + '</div><div class="small text-muted">' + VS.esc(t.option_key ? 'Up to ' + t.capacity + ' guests' : window.VSRooms.facts(t)) + '</div><div class="vs-num fw-semibold">' + VS.money(t.rate) + ' <span class="text-muted small fw-normal">per night</span></div>' +
                        '<div class="small ' + (free ? 'text-success' : 'text-danger') + '">' + (free ? VS.plural(free, 'room') + ' available' : 'Fully booked') + '</div></div>' +
                        '<div class="d-flex align-items-center gap-2"><button class="btn btn-sm btn-outline-velocity" data-dec aria-label="Fewer ' + VS.esc(label) + ' rooms"' + (qty ? '' : ' disabled') + '>\u2212</button><span class="vs-num fw-bold" style="min-width:1.5rem;text-align:center">' + qty + '</span><button class="btn btn-sm btn-velocity" data-inc aria-label="More ' + VS.esc(label) + ' rooms"' + (qty >= free ? ' disabled' : '') + '>+</button></div></div>';
                }).join('');
                var n = nights(d), preview = units.reduce(function (a, t) { return a + (d.rooms[keyOf(t)] || 0) * Number(t.rate) * n; }, 0);
                host.innerHTML = (d.note ? '<div class="alert alert-warning vs-alert" role="status" id="vsLowerNote">' + VS.esc(d.note) + '</div>' : '') + rows + '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div class="vs-num fw-bold">' + VS.plural(n, 'night') + ' \u00b7 ' + VS.money(preview) + '</div>' +
                    '<div class="d-flex gap-2">' + (d.editing != null ? '<button class="btn btn-light" id="wCancelEdit">Cancel edit</button>' : '') + '<button class="btn btn-velocity" id="wConfirm">' + (d.editing != null ? 'Save this stay' : 'Confirm these dates') + '</button></div></div>';
                host.querySelectorAll('[data-type]').forEach(function (row) {
                    var id = row.dataset.type;
                    var t = units.find(function (x) { return keyOf(x) === id; });
                    var free = Math.max(0, (t.is_fully_booked ? 0 : (t.available_count || 0)) - usedElsewhere(id));
                    row.querySelector('[data-inc]').onclick = function () { d.rooms[id] = Math.min(free, (d.rooms[id] || 0) + 1); if (d.carried) { d.types = d.types || {}; d.types[id] = { name: t.name, rate: Number(t.rate) }; } save(); paintSelectedRooms(); paintRooms(); };
                    row.querySelector('[data-dec]').onclick = function () { d.rooms[id] = Math.max(0, (d.rooms[id] || 0) - 1); if (!d.rooms[id]) delete d.rooms[id]; save(); paintSelectedRooms(); paintRooms(); };
                });
                var ce = host.querySelector('#wCancelEdit'); if (ce) ce.onclick = function () { S.draft = { check_in: '', check_out: '', rooms: {}, editing: null }; save(); paintStays(); paintEditor(); };
                host.querySelector('#wConfirm').onclick = confirmStay;
                if (d.toConfirm) {
                    d.toConfirm = false; save();
                    host.querySelector('#wConfirm').scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' });
                }
                updateNext();
            });
        }

        function confirmStay() {
            var d = S.draft; errorBox('');
            if (!d.check_in || !d.check_out) { VS.alertBox('vsDateErr', 'Choose valid check-in and check-out dates for this stay first.'); return; }
            if (!Object.keys(d.rooms).some(function (k) { return d.rooms[k] > 0; })) { VS.alertBox('vsDateErr', 'Choose at least one room for this stay.'); return; }
            var dup = S.stays.findIndex(function (s, i) { return i !== d.editing && s.check_in === d.check_in && s.check_out === d.check_out; });
            if (dup >= 0) { VS.alertBox('vsDateErr', 'You already added a stay for ' + VS.date(d.check_in) + ' – ' + VS.date(d.check_out) + '. Pick different dates or add rooms to that stay.'); return; }
            if (S.stays.length >= MAX_STAYS && d.editing == null) { VS.alertBox('vsDateErr', 'You can book up to ' + MAX_STAYS + ' stays together.'); return; }
            var stay = { check_in: d.check_in, check_out: d.check_out, rooms: JSON.parse(JSON.stringify(d.rooms)) };
            if (d.editing != null) { S.stays[d.editing] = stay; delete S.amenities[d.editing]; } else S.stays.push(stay);
            S.draft = { check_in: '', check_out: '', rooms: {}, editing: null };
            VS.toast('Dates confirmed.', 'success'); save(); frame(); paintStays(); paintEditor();
        }

        // ================================================= STEP 2 (booking) - amenities per date range and room
        function amenityQtyTotal() { var n = 0; Object.keys(S.amenities).forEach(function (i) { (S.amenities[i] || []).forEach(function (x) { n += x.quantity; }); }); return n; }
        function stepAmenities() {
            main.innerHTML = '<h2 class="vs-section-title">Amenities</h2><p class="text-muted">Optional. Amenities are added to your total; you can request more after booking.</p><div id="vsStepErr"></div><div id="vsAmenHost">' + VS.skeleton(4) + '</div>' +
                '<div class="vs-card vs-strong vs-num text-end" id="vsAmenTotal" role="status" aria-live="polite"></div>' +
                '<div class="d-flex justify-content-between mt-4 gap-2"><button class="btn btn-light" data-back>Back</button><div class="d-flex gap-2"><button type="button" class="btn btn-outline-velocity" data-skip>Skip</button><button class="btn btn-velocity" data-next>Continue</button></div></div>';
            function liveTotal() {
                var types = allTypes(), r = roomsTotal(types), a = amenitiesTotal();
                main.querySelector('#vsAmenTotal').textContent = 'Amenities ' + VS.money(a) + ' · Grand total ' + VS.money(r + a);
                paintSummary();
            }
            function noneMessage() { return 'No amenities · Total ' + VS.money(roomsTotal(allTypes())); }
            main.querySelector('[data-skip]').onclick = function () {
                VS.confirm({ title: 'Continue without additional amenities?', message: noneMessage(), confirmText: 'Yes, continue' }).then(function (yes) {
                    if (!yes) return; S.amenities = {}; save(); go(S.step + 1);
                });
            };
            bindNav(function () {
                var any = amenityQtyTotal() > 0, types = allTypes();
                VS.confirm(any
                    ? { title: 'Proceed with these amenities?', message: 'Amenities ' + VS.money(amenitiesTotal()) + ' · Grand total ' + VS.money(roomsTotal(types) + amenitiesTotal()), confirmText: 'Yes, continue' }
                    : { title: 'Continue without additional amenities?', message: noneMessage(), confirmText: 'Yes, continue' }).then(function (yes) { if (yes) go(S.step + 1); });
            });
            loadAmenities().then(function (list) {
                var host = main.querySelector('#vsAmenHost'), types = allTypes();
                var included = list.filter(function (a) { return !(Number(a.charge) > 0); }), paid = list.filter(function (a) { return Number(a.charge) > 0; });
                if (!list.length) { host.innerHTML = '<p class="text-muted">No amenities are offered right now.</p>'; liveTotal(); return; }
                function rowsOf(group, i, sl, isIncluded) {
                    return group.map(function (a) {
                        return '<div class="d-flex align-items-center gap-2 py-1" data-am="' + i + '|' + sl.type + '|' + sl.slot + '|' + a.id + '"><div class="flex-grow-1">' + VS.esc(a.amenity_name) + ' ' +
                            (isIncluded ? '<span class="vs-chip ok">Included</span> <span class="text-muted small vs-num">' + VS.money(0) + '</span>' : '<span class="text-muted small vs-num">' + VS.money(a.charge) + '</span>') + '</div>' +
                            '<button class="btn btn-sm btn-outline-velocity" data-d aria-label="Fewer ' + VS.esc(a.amenity_name) + '">−</button><span class="vs-num fw-bold" data-q style="min-width:1.5rem;text-align:center">0</span><button class="btn btn-sm btn-velocity" data-u aria-label="More ' + VS.esc(a.amenity_name) + '">+</button></div>';
                    }).join('');
                }
                host.innerHTML = S.stays.map(function (s, i) {
                    var slots = []; stayRooms(s).forEach(function (r) { for (var k = 1; k <= r.qty; k++) slots.push({ type: r.id, slot: k }); });
                    return '<div class="vs-card"><h3 class="h6 fw-bold">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + '</h3>' + slots.map(function (sl, n) {
                        var tn = types[sl.type] ? unitName(types[sl.type]) : 'Room';
                        return '<details class="mb-2"' + (i === 0 && n === 0 ? ' open' : '') + '><summary class="fw-semibold">' + VS.esc(tn) + ' (room ' + sl.slot + ')</summary><div class="mt-2">' +
                            (included.length ? '<div class="small fw-bold mt-1" data-group="included">Included with your room</div>' + rowsOf(included, i, sl, true) : '') +
                            (paid.length ? '<div class="small fw-bold mt-2" data-group="paid">Additional amenities</div>' + rowsOf(paid, i, sl, false) : '') + '</div></details>';
                    }).join('') + '</div>';
                }).join('');
                function qtyOf(i, t, sl, id) { var f = (S.amenities[i] || []).find(function (x) { return unitOf(x) == t && x.room_slot == sl && x.amenity_id == id; }); return f ? f.quantity : 0; }
                function setQty(i, t, sl, id, q) {
                    var arr = S.amenities[i] = S.amenities[i] || []; var idx = arr.findIndex(function (x) { return unitOf(x) == t && x.room_slot == sl && x.amenity_id == id; });
                    if (q <= 0) { if (idx >= 0) arr.splice(idx, 1); } else if (idx >= 0) arr[idx].quantity = q; else { var pp = String(t).split(':'); arr.push({ amenity_id: +id, quantity: q, unit: String(t), room_type_id: +pp[0], option_key: pp[1] || null, room_slot: +sl }); }
                    save();
                }
                function totalFor(id) { var n = 0; Object.keys(S.amenities).forEach(function (i) { (S.amenities[i] || []).forEach(function (x) { if (x.amenity_id == id) n += x.quantity; }); }); return n; }
                host.querySelectorAll('[data-am]').forEach(function (row) {
                    var p = row.dataset.am.split('|'), i = +p[0], t = p[1], sl = p[2], id = p[3], a = list.find(function (x) { return x.id == id; });
                    function paint() { row.querySelector('[data-q]').textContent = qtyOf(i, t, sl, id); liveTotal(); }
                    row.querySelector('[data-u]').onclick = function () {
                        // the same two limits as the app: a room takes at most its capacity of one amenity, and never more than the hotel has
                        var roomCap = types[t] ? Math.max(1, Number(types[t].capacity) || 1) : 0;
                        if (roomCap && qtyOf(i, t, sl, id) >= roomCap) { VS.toast("Quantity can't exceed the room capacity you selected.", 'warning'); return; }
                        if (!a.is_unlimited && totalFor(id) + 1 > a.quantity) { VS.toast('Only ' + a.quantity + ' of ' + a.amenity_name + ' left.', 'warning'); return; }
                        setQty(i, t, sl, id, qtyOf(i, t, sl, id) + 1); paint();
                    };
                    row.querySelector('[data-d]').onclick = function () { setQty(i, t, sl, id, qtyOf(i, t, sl, id) - 1); paint(); };
                    row.querySelector('[data-q]').textContent = qtyOf(i, t, sl, id);
                });
                liveTotal();
            });
        }

        // ================================================= representative and guests (step 3 of a booking, step 2 of a reservation)
        function capacity() {
            var types = allTypes();
            return Math.min.apply(null, S.stays.map(function (s) { return stayRooms(s).reduce(function (a, r) { return a + (types[r.id] ? types[r.id].capacity * r.qty : 0); }, 0); }));
        }
        function unitOf(x) { return x.unit || String(x.room_type_id); }
        function stepRepGuests() {
            var cap = capacity();
            if (S.adults + S.minors.length > cap) { S.adults = Math.max(1, cap - S.minors.length); S.minors = S.minors.slice(0, Math.max(0, cap - S.adults)); }
            main.innerHTML = '<h2 class="vs-section-title">Representative and guests</h2><p class="text-muted">Who checks in, and who is staying.</p><div id="vsStepErr"></div>' +
                '<section class="vs-card" aria-labelledby="vsRepTitle"><h3 class="h6 fw-bold" id="vsRepTitle">Who can check in?</h3><p class="text-muted small">Choose Account owner if you will personally handle the transaction at the hotel. Choose Someone else if another person will represent your booking.</p>' +
                '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="who" id="whoOwner" value="owner"><label class="form-check-label fw-semibold" for="whoOwner">Account owner</label></div>' +
                '<div class="form-check mb-3"><input class="form-check-input" type="radio" name="who" id="whoOther" value="other"><label class="form-check-label fw-semibold" for="whoOther">Someone else</label></div>' +
                '<div class="row g-3"><div class="col-md-4"><label class="form-label small fw-semibold" for="rF">First name</label><input class="form-control" id="rF" maxlength="100"></div><div class="col-md-4"><label class="form-label small fw-semibold" for="rM">Middle name <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" id="rM" maxlength="100"></div><div class="col-md-4"><label class="form-label small fw-semibold" for="rL">Last name</label><input class="form-control" id="rL" maxlength="100"></div></div>' +
                '<p class="text-muted small mt-3 mb-0">The person checking in presents 1 valid ID at the front desk.</p></section>' +
                '<section class="vs-card" id="vsGuestsCard" aria-labelledby="vsGuestsTitle"><h3 class="h6 fw-bold" id="vsGuestsTitle">Guests</h3><p class="text-muted small">Adults and minors together must fit the rooms you chose in every stay (up to <strong>' + cap + '</strong>).</p>' +
                '<div class="d-flex align-items-center justify-content-between py-2"><span class="fw-semibold">Adults</span><span><button class="btn btn-sm btn-outline-velocity" id="aDec" aria-label="Fewer adults">−</button><span class="vs-num fw-bold mx-3" id="aVal"></span><button class="btn btn-sm btn-velocity" id="aInc" aria-label="More adults">+</button></span></div>' +
                '<div class="d-flex align-items-center justify-content-between py-2"><span class="fw-semibold">Minors</span><span><button class="btn btn-sm btn-outline-velocity" id="mDec" aria-label="Fewer minors">−</button><span class="vs-num fw-bold mx-3" id="mVal"></span><button class="btn btn-sm btn-velocity" id="mInc" aria-label="More minors">+</button></span></div>' +
                '<div id="mAges" class="row g-2 mt-1"></div><p class="text-muted small mt-2 mb-0" id="gHint"></p><div id="gCap" class="invalid-feedback d-block fw-semibold" role="alert" hidden></div></section>' + nav();
            var who = main.querySelectorAll('[name=who]'), f = main.querySelector('#rF'), m = main.querySelector('#rM'), l = main.querySelector('#rL');
            loadProfile().then(function (p) {
                S.owner = { first: p.first_name || '', middle: p.middle_name || '', last: p.last_name || '' };
                apply();
            });
            function apply() {
                var owner = S.rep.who === 'owner';
                who.forEach(function (r) { r.checked = r.value === S.rep.who; });
                [f, m, l].forEach(function (i) { i.readOnly = owner; });
                if (owner && S.owner) { f.value = S.owner.first; m.value = S.owner.middle; l.value = S.owner.last; } else { f.value = S.rep.first; m.value = S.rep.middle; l.value = S.rep.last; }
            }
            // After the representative is chosen and the names are valid, the page moves to Guests (once; smoothly unless motion is reduced)
            var wentToGuests = false;
            function repValid() { return S.rep.who === 'owner' ? !!(S.owner && S.owner.first && S.owner.last) : !!(f.value.trim() && l.value.trim()); }
            function toGuests() {
                if (wentToGuests || !repValid()) return;
                wentToGuests = true;
                var g = main.querySelector('#vsGuestsCard');
                g.style.scrollMarginTop = '80px'; // clear of the page header; works in the signed-in shell, whose content scrolls in its own box
                g.scrollIntoView({ block: 'start', behavior: VS.reducedMotion ? 'auto' : 'smooth' });
            }
            who.forEach(function (r) { r.onchange = function () { S.rep.who = r.value; if (r.value === 'other') { S.rep.first = ''; S.rep.middle = ''; S.rep.last = ''; } apply(); save(); toGuests(); }; });
            [f, m, l].forEach(function (i) { i.oninput = function () { if (S.rep.who === 'other') { S.rep.first = f.value; S.rep.middle = m.value; S.rep.last = l.value; save(); } }; i.onchange = toGuests; });
            apply();
            function paint() {
                main.querySelector('#aVal').textContent = S.adults; main.querySelector('#mVal').textContent = S.minors.length;
                var total = S.adults + S.minors.length, capBox = main.querySelector('#gCap');
                main.querySelector('#aDec').disabled = S.adults <= 1; main.querySelector('#aInc').disabled = total >= cap;
                main.querySelector('#mDec').disabled = !S.minors.length; main.querySelector('#mInc').disabled = total >= cap;
                main.querySelector('#gHint').textContent = VS.plural(total, 'guest') + ' of ' + cap + '. A minor is a child aged 0 to 7; enter each child’s age.';
                // the capacity check is inline: at the limit the + buttons stop and this says why
                capBox.hidden = total < cap; capBox.textContent = total >= cap ? 'The rooms you chose hold up to ' + VS.plural(cap, 'guest') + '. Choose more rooms to add guests.' : '';
                main.querySelector('#mAges').innerHTML = S.minors.map(function (a, i) { return '<div class="col-6 col-md-3"><label class="form-label small fw-semibold" for="age' + i + '">Child ' + (i + 1) + ' age</label><input class="form-control" id="age' + i + '" type="number" inputmode="numeric" min="0" max="7" value="' + (a == null ? '' : a) + '"></div>'; }).join('');
                main.querySelectorAll('#mAges input').forEach(function (inp, i) { inp.oninput = function () { S.minors[i] = inp.value === '' ? null : +inp.value; inp.classList.remove('is-invalid'); var fb = inp.nextElementSibling; if (fb && fb.classList.contains('vs-fb')) fb.remove(); save(); }; });
                save();
            }
            main.querySelector('#aInc').onclick = function () { S.adults++; paint(); }; main.querySelector('#aDec').onclick = function () { S.adults--; paint(); };
            main.querySelector('#mInc').onclick = function () { S.minors.push(null); paint(); }; main.querySelector('#mDec').onclick = function () { S.minors.pop(); paint(); };
            paint();
            bindNav(function () {
                VS.clearErrors(main); var ok = true;
                var first = f.value.trim(), last = l.value.trim();
                if (!first) { VS.fieldError(f, 'Enter the first name.'); ok = false; }
                if (!last) { VS.fieldError(l, 'Enter the last name.'); ok = false; }
                main.querySelectorAll('#mAges input').forEach(function (inp) {
                    var v = inp.value; if (v === '' || +v < 0 || +v > 7 || !/^\d+$/.test(v)) { VS.fieldError(inp, 'Enter an age from 0 to 7.'); ok = false; }
                });
                if (S.adults + S.minors.length > cap) { VS.alertBox('vsStepErr', 'The rooms you chose hold up to ' + VS.plural(cap, 'guest') + '.'); ok = false; }
                if (!ok) { var bad = main.querySelector('.is-invalid, #vsStepErr .alert'); if (bad) bad.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); return; }
                S.rep.first = first; S.rep.middle = m.value.trim(); S.rep.last = last;
                var who2 = S.rep.who === 'other' ? first + ' ' + last + ' will check in and present 1 valid ID at the front desk. ' : '';
                VS.confirm({ title: 'Continue with these details?', message: who2 + VS.plural(S.adults, 'adult') + (S.minors.length ? ' and ' + VS.plural(S.minors.length, 'minor') : '') + '.', confirmText: 'Continue' }).then(function (yes) { if (yes) go(S.step + 1); });
            });
        }

        // ================================================= STEP 6 (reservation) - payment method
        function stepPaymentMethod() {
            main.innerHTML = '<h2 class="vs-section-title">Payment method</h2><p class="text-muted">Choose Cash or GCash. A payment method is required.</p><div id="vsStepErr"></div><div class="vs-card">' +
                ['cash', 'gcash'].map(function (m) {
                    return '<div class="form-check mb-3"><input class="form-check-input" type="radio" name="pm" id="pm_' + m + '" value="' + m + '"' + (S.payment_method === m ? ' checked' : '') + '><label class="form-check-label" for="pm_' + m + '"><span class="fw-semibold">' + (m === 'cash' ? 'Cash' : 'GCash') + '</span><br><span class="text-muted small">' +
                        (m === 'cash' ? 'Pay at the hotel through walk-in payment. No online payment is required at this time.' : 'You can complete your GCash payment later by selecting Pay now from your reservation.') + '</span></label></div>';
                }).join('') + '</div>' + nav();
            main.querySelectorAll('[name=pm]').forEach(function (r) { r.onchange = function () { S.payment_method = r.value; save(); }; });
            bindNav(function () { if (!S.payment_method) { errorBox('Choose a payment method.'); return; } go(S.step + 1); });
        }

        // ================================================= LAST STEP - review and bill summary
        function promoNotes(types) {
            var by = {};
            S.stays.forEach(function (s) { stayRooms(s).forEach(function (r) { var t = types[r.id]; (t && t.promos || []).forEach(function (p) {
                var off = p.rate_after != null ? Math.max(0, Number(t.rate) - Number(p.rate_after)) * nights(s) * r.qty : 0; by[p.id] = by[p.id] || { p: p, save: 0 }; by[p.id].save += off; }); }); });
            return Object.keys(by).map(function (k) { return by[k]; });
        }
        function stepReview() {
            main.innerHTML = '<h2 class="vs-section-title">Review and bill summary</h2><p class="text-muted">Check everything before you confirm.</p><div id="vsStepErr"></div><div id="vsRev">' + VS.skeleton(6) + '</div>';
            loadAmenities().then(function () {
                var types = allTypes(), host = main.querySelector('#vsRev'), amenBy = {}; (cache.amenities || []).forEach(function (a) { amenBy[a.id] = a; });
                var r = roomsTotal(types), a = amenitiesTotal();
                var staysHtml = S.stays.map(function (s, i) {
                    var lines = stayRooms(s).map(function (rm) { var t = types[rm.id] || { name: 'Room', rate: 0 }; return '<div class="vs-row"><span>' + VS.esc(unitName(t)) + ' × ' + rm.qty + ' <span class="small">(' + VS.money(t.rate) + ' × ' + VS.plural(nights(s), 'night') + ')</span></span><span>' + VS.money(Number(t.rate) * nights(s) * rm.qty) + '</span></div>'; }).join('');
                    // a booking's amenities (included ones add 0), or those of a reservation that was made with some (an edit)
                    var am = (S.amenities[i] || []).map(function (x) { var am = amenBy[x.amenity_id] || { amenity_name: 'Amenity', charge: 0 }; var t = types[unitOf(x)]; return '<div class="vs-row vs-sub"><span>' + VS.esc(am.amenity_name) + ' × ' + x.quantity + (t ? ' <span class="small">(' + VS.esc(unitName(t)) + ' room ' + x.room_slot + ')</span>' : '') + '</span><span>' + (Number(am.charge) > 0 ? VS.money(am.charge * x.quantity) : '<span class="vs-chip ok">Included</span>') + '</span></div>'; }).join('');
                    return '<div class="vs-card"><h3 class="h6 fw-bold">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + ' · ' + VS.plural(nights(s), 'night') + '</h3>' + lines + am + '</div>';
                }).join('');
                var promos = promoNotes(types), total = r + a;
                var repName = [S.rep.first, S.rep.middle, S.rep.last].filter(Boolean).join(' ');
                var payNow = mode === 'booking' ? '<div class="vs-row"><span>Amount to pay now</span><span>' + VS.money(Math.round(total * 0.2 * 100) / 100) + ' to ' + VS.money(Math.round(total * 0.5 * 100) / 100) + '</span></div><p class="text-muted small mb-0">You choose 20%, 30%, 40% or 50% of the grand total on the next screen. The rest is paid at the hotel.</p>' : '';
                host.innerHTML = staysHtml +
                    '<div class="vs-card"><h3 class="h6 fw-bold">Representative and guests</h3><div class="vs-row"><span>Representative</span><span>' + VS.esc(repName) + (S.rep.who === 'other' ? ' (must present 1 valid ID)' : '') + '</span></div>' +
                    '<div class="vs-row"><span>Guests</span><span>' + VS.plural(S.adults, 'adult') + (S.minors.length ? ', ' + VS.plural(S.minors.length, 'minor') : '') + '</span></div>' +
                    (mode === 'reservation' ? '<div class="vs-row"><span>Payment method</span><span>' + (S.payment_method === 'gcash' ? 'GCash' : 'Cash') + '</span></div>' : '') + '</div>' +
                    '<div class="vs-card"><h3 class="h6 fw-bold">Bill summary</h3><div class="vs-row"><span>Room charges</span><span>' + VS.money(r) + '</span></div>' + (a > 0 ? '<div class="vs-row"><span>Amenities</span><span>' + VS.money(a) + '</span></div>' : '') + '<hr class="vs-rule">' +
                    '<div class="vs-row vs-strong"><span>Grand total</span><span>' + VS.money(total) + '</span></div>' + payNow +
                    '<p class="text-muted small mt-2 mb-0" id="vsDeskNote">PWD and Senior Citizen discounts are verified and applied by the front desk at check-in.</p></div>' +
                    (promos.length ? '<div class="vs-card"><h3 class="h6 fw-bold">Promos for your rooms (verified at check-in)</h3>' + promos.map(function (x) { return '<div class="vs-row"><span>' + VS.esc(x.p.title) + ' · ' + VS.esc(x.p.label || '') + '</span><span></span></div>' + (x.save > 0 ? '<div class="text-muted small">Estimated saving ' + VS.money(x.save) + '. Not subtracted until the front desk verifies it.</div>' : ''); }).join('') + '</div>' : '') +
                    '<div class="vs-card"><div class="form-check"><input class="form-check-input" type="checkbox" id="wTerms"><label class="form-check-label fw-normal" for="wTerms">I have read and accepted the <a href="#" id="wTermsLink">Terms and cancellation policy</a>.</label></div></div>' +
                    '<div class="d-flex justify-content-between mt-3 gap-2"><button class="btn btn-light" data-back>Back</button><button class="btn btn-velocity" id="wSubmit" data-busy-text="' + (mode === 'booking' ? 'Please wait' : 'Confirming') + '">' + (mode === 'booking' ? 'Continue to payment' : 'Confirm reservation') + '</button></div>';
                host.querySelector('[data-back]').onclick = function () { go(S.step - 1); };
                host.querySelector('#wTermsLink').onclick = function (e) {
                    e.preventDefault();
                    VS.confirm({ title: 'Terms and cancellation policy', html: '<div class="vs-terms">' + root.querySelector('#vsPolicyText').innerHTML + '</div>', confirmText: 'I agree', cancelText: 'Close' }).then(function (yes) { if (yes) host.querySelector('#wTerms').checked = true; });
                };
                host.querySelector('#wSubmit').onclick = submit;
            });
        }

        function payload() {
            var types = allTypes();
            var fd = new FormData();
            S.stays.forEach(function (s, i) {
                fd.append('stays[' + i + '][check_in]', s.check_in); fd.append('stays[' + i + '][check_out]', s.check_out);
                stayRooms(s).forEach(function (r, k) { fd.append('stays[' + i + '][rooms][' + k + '][room_type_id]', r.typeId); if (r.option) fd.append('stays[' + i + '][rooms][' + k + '][option_key]', r.option); fd.append('stays[' + i + '][rooms][' + k + '][quantity]', r.qty); });
                (mode === 'booking' ? (S.amenities[i] || []) : []).forEach(function (a, k) {
                    fd.append('stays[' + i + '][amenities][' + k + '][amenity_id]', a.amenity_id); fd.append('stays[' + i + '][amenities][' + k + '][quantity]', a.quantity);
                    fd.append('stays[' + i + '][amenities][' + k + '][room_type_id]', a.room_type_id); if (a.option_key) fd.append('stays[' + i + '][amenities][' + k + '][option_key]', a.option_key); fd.append('stays[' + i + '][amenities][' + k + '][room_slot]', a.room_slot);
                });
            });
            fd.append('adults', S.adults); fd.append('children', S.minors.length);
            fd.append('guest_first_name', S.rep.first); fd.append('guest_last_name', S.rep.last); if (S.rep.middle) fd.append('guest_middle_name', S.rep.middle);
            S.minors.forEach(function (age, i) {
                fd.append('additional_guests[' + i + '][name]', 'Child ' + (i + 1)); fd.append('additional_guests[' + i + '][age]', age); fd.append('additional_guests[' + i + '][relationship]', 'Child');
            });
            fd.append('idempotency_key', S.key);
            return fd;
        }


        function editBody() {
            var b = { stays: S.stays.map(function (s, i) { return { check_in: s.check_in, check_out: s.check_out,
                    rooms: stayRooms(s).map(function (r) { return r.option ? { room_type_id: r.typeId, option_key: r.option, quantity: r.qty } : { room_type_id: r.typeId, quantity: r.qty }; }),
                    amenities: (S.amenities[i] || []).map(function (a) { var o = { amenity_id: a.amenity_id, quantity: a.quantity, room_type_id: a.room_type_id, room_slot: a.room_slot }; if (a.option_key) o.option_key = a.option_key; return o; }) }; }),
                adults: S.adults, children: S.minors.length, guest_first_name: S.rep.first, guest_last_name: S.rep.last,
                additional_guests: S.minors.map(function (age, i) { return { name: 'Child ' + (i + 1), age: age, relationship: 'Child' }; }) };
            if (S.rep.middle) b.guest_middle_name = S.rep.middle;
            if (S.discount_id) { b.discount_id = +S.discount_id; if (editInfo && editInfo.id_card_type && editInfo.id_card_type !== 'None') b.id_card_type = editInfo.id_card_type; }
            else { b.id_card_type = 'None'; if (editInfo && editInfo.id_card_image_path) b.remove_id_card = true; }
            return b;
        }
        function saveEdit(btn) {
            VS.confirm({ title: 'Save your changes?', message: 'You can edit a reservation only once. After this it cannot be edited again.', confirmText: 'Save changes' }).then(function (yes) {
                if (!yes) return;
                VS.busy(btn, function () {
                    return VS.api('/guest/reservations/' + editId, { method: 'PUT', body: editBody() }).then(function (r) {
                        if (!r.ok) return r;
                        var steps = Promise.resolve(r);
                        if (S.payment_method !== S.origMethod) steps = steps.then(function (prev) { if (!prev.ok) return prev; return VS.api('/guest/reservations/' + editId + '/switch-to-' + (S.payment_method === 'gcash' ? 'gcash' : 'cash'), { method: 'PUT' }).then(function (u) { return u.ok ? r : u; }); });
                        return steps;
                    });
                }).then(function (r) {
                    if (!r) return;
                    if (!r.ok) { errorBox(r.message); return; }
                    abandon();
                    main.innerHTML = '<div class="text-center py-4"><div class="vs-success"><i class="fas fa-check"></i></div><h2 class="h4 fw-bold mt-3">Reservation updated</h2><p class="text-muted">Reservation #' + VS.esc(editId) + ' was saved. It cannot be edited again.</p><a class="btn btn-velocity" href="/guest/reservations/' + VS.esc(editId) + '">View reservation</a></div>';
                    bar.parentNode.classList.add('d-none'); sum.parentNode.classList.add('d-none'); root.querySelector('#vsWizSummaryM').classList.add('d-none');
                });
            });
        }

        function submit(btn) {
            btn = btn.currentTarget || main.querySelector('#wSubmit');
            if (!main.querySelector('#wTerms').checked) { VS.fieldError(main.querySelector('#wTerms'), 'Accept the terms to continue.'); return; }
            if (mode === 'booking') { startPayment(); return; }
            if (editId) { saveEdit(btn); return; }
            VS.confirm({ title: 'Confirm reservation?', message: 'Your reservation will be sent to the front desk.', confirmText: 'Confirm reservation' }).then(function (yes) {
                if (!yes) return;
                var fd = payload(); fd.append('payment_method', S.payment_method);
                VS.busy(btn, function () { return VS.api('/guest/reservations', { method: 'POST', body: fd }); }).then(function (r) {
                    if (!r) return;
                    if (!r.ok) { errorBox(r.message); return; }
                    abandon();
                    var res = r.data.reservation || r.data;
                    main.innerHTML = '<div class="text-center py-4"><div class="vs-success"><i class="fas fa-check"></i></div><h2 class="h4 fw-bold mt-3">Reservation confirmed</h2><p class="text-muted">Reservation #' + VS.esc(res.id) + ' is with the front desk.' +
                        (S.payment_method === 'gcash' ? ' Complete your GCash payment from your reservation using Pay now.' : ' Pay at the hotel when you arrive.') + '</p><a class="btn btn-velocity" href="/guest/reservations/' + VS.esc(res.id) + '">View reservation</a></div>';
                    bar.parentNode.classList.add('d-none'); sum.parentNode.classList.add('d-none'); root.querySelector('#vsWizSummaryM').classList.add('d-none');
                });
            });
        }


        // a booking is paid first: Review Billing and the GCash steps run on this page, and the booking is created with its payment
        function startPayment() {
            var types = allTypes(), r = roomsTotal(types), a = amenitiesTotal(), total = r + a;
            var pending = false, cap = null; // no ID is uploaded when booking: the front desk applies any discount at check-in
            root.querySelector('#vsWizTop').classList.add('d-none'); sum.parentNode.classList.add('d-none'); root.querySelector('#vsWizSummaryM').classList.add('d-none');
            window.VSPay.run(main, {
                total: total, paid: 0, discountPending: pending, depositCap: cap, kindLabel: 'New booking',
                lines: [{ label: 'Room charges', amount: r }].concat(a > 0 ? [{ label: 'Amenities', amount: a }] : []),
                notes: ['PWD and Senior Citizen discounts are verified and applied by the front desk at check-in.'],
                onCancel: function () { root.querySelector('#vsWizTop').classList.remove('d-none'); sum.parentNode.classList.remove('d-none'); root.querySelector('#vsWizSummaryM').classList.remove('d-none'); go(S.step); },
                submit: function (f) {
                    var fd = payload(); fd.append('payment_method', 'gcash'); fd.append('amount_paid', f.amount); fd.append('selected_payment_percentage', f.percent);
                    fd.append('reference_number', f.reference_number); fd.append('receipt', f.receipt, f.receipt.name || 'receipt.jpg');
                    return VS.api('/guest/bookings', { method: 'POST', body: fd }).then(function (res) { if (res.ok) { abandon(); } return res; });
                },
                success: function (data) { var b = data.booking || data; return '<a class="btn btn-velocity" href="/guest/bookings/' + VS.esc(b.id) + '">View booking</a>'; }
            });
        }

        // booking payment page reads this state
        W.draftForPayment = function () { return { fd: payload(), total: roomsTotal(allTypes()) + amenitiesTotal() }; };

        // The rooms (and, if the guest chose them there, the dates) picked on the landing page or the Rooms page: they open as the stay being
        // filled in. Chosen dates are carried over and availability is checked again (a lower count or a dropped room says so inline);
        // without chosen dates the guest is asked for them first, with the rooms shown as a compact card.
        function applySelection(sv) {
            if (sv.expired) { window.VSRooms.clearSelection(); VS.alertBox('vsStepErr', 'Your room selection expired. Choose your dates and rooms again.', 'warning'); return; }
            var chosen = !!sv.chosen && sv.check_in >= earliest && sv.check_out > sv.check_in;
            window.VSRooms.clearSelection();
            S.draft = { check_in: chosen ? sv.check_in : '', check_out: chosen ? sv.check_out : '', rooms: JSON.parse(JSON.stringify(sv.rooms || {})), types: JSON.parse(JSON.stringify(sv.types || {})), editing: null, carried: true, recheck: true, note: '', toConfirm: chosen };
            if (sv.chosen && !chosen) S.draft.note = 'Those dates can no longer be booked (the earliest check-in is ' + VS.date(earliest) + '). Choose new dates.';
            save(); frame(); paintStays(); paintEditor();
            if (S.draft.note && !chosen) { VS.alertBox('vsDateErr', S.draft.note, 'warning'); S.draft.note = ''; save(); }
        }

        // ---- start: preselect a room from the Room Details link
        var presetRoom = pre.get('room');
        if (presetRoom && !S.stays.length && !S.draft.check_in) {
            S.draft.check_in = pre.get('check_in') && pre.get('check_in') >= earliest ? pre.get('check_in') : '';
            S.draft.check_out = S.draft.check_in ? (pre.get('check_out') && pre.get('check_out') > S.draft.check_in ? pre.get('check_out') : VS.addDays(S.draft.check_in, 1)) : '';
            S.presetRoom = +presetRoom;
        }

        // ---- Edit reservation (the app's one-time edit): fill the wizard from the reservation, save with PUT
        var editInfo = null;
        function fetchEdit() {
            main.innerHTML = VS.skeleton(5);
            return VS.api('/guest/reservations/' + editId).then(function (r) {
                var back = '<a class="btn btn-light" href="/guest/reservations">Back to Booking &amp; Reservation</a>';
                if (!r.ok && (r.status === 404 || r.status === 403)) { main.innerHTML = VS.notFound('That reservation is not on your account.'); return Promise.reject('stop'); }
                if (!r.ok) { main.innerHTML = VS.errorState(r.message) + '<div class="text-center">' + back + '</div>'; return Promise.reject('stop'); }
                var it = r.data, awaiting = /AWAITING|TO_BE_CONVERTED/.test(it.status || '');
                if (it.booking || !awaiting) { main.innerHTML = '<div class="vs-empty"><i class="fas fa-circle-info"></i><h3 class="h6 fw-bold">This reservation can no longer be edited</h3><p class="text-muted">Only a pending reservation can be edited.</p>' + back + '</div>'; return Promise.reject('stop'); }
                if (it.edited_at) { main.innerHTML = '<div class="vs-empty"><i class="fas fa-circle-info"></i><h3 class="h6 fw-bold">Already edited</h3><p class="text-muted">This reservation has already been modified and cannot be edited again.</p>' + back + '</div>'; return Promise.reject('stop'); }
                editInfo = it;
                S.stays = (it.stays || []).map(function (s) { var rooms = {}; (s.rooms || []).forEach(function (rm) { var k = rm.option_key && rm.option_key !== 'std' ? rm.room_type_id + ':' + rm.option_key : String(rm.room_type_id); rooms[k] = (rooms[k] || 0) + rm.quantity; }); return { check_in: s.check_in, check_out: s.check_out, rooms: rooms }; });
                S.amenities = {}; (it.stays || []).forEach(function (s, i) { S.amenities[i] = (s.amenities || []).filter(function (a) { return a.amenity_id; }).map(function (a) { var ok = a.option_key && a.option_key !== 'std' ? a.option_key : null; return { amenity_id: +a.amenity_id, quantity: a.quantity, unit: a.room_type_id != null ? (ok ? a.room_type_id + ':' + ok : String(a.room_type_id)) : null, room_type_id: a.room_type_id != null ? +a.room_type_id : null, option_key: ok, room_slot: a.room_slot != null ? +a.room_slot : null }; }); });
                S.adults = Math.max(1, +it.adults || 1);
                S.minors = (it.additional_guest_details || it.additional_guests || []).map(function (g) { return g.age != null ? +g.age : null; });
                S.rep = { who: 'other', first: it.guest_first_name || '', middle: it.guest_middle_name || '', last: it.guest_last_name || '' };
                S.discount_id = it.discount_id ? String(it.discount_id) : ''; S.hasId = !!(it.discount_id && (it.id_card_image_path || it.has_id_card || it.id_card_type && it.id_card_type !== 'None'));
                S.payment_method = it.payment_method === 'gcash' ? 'gcash' : 'cash'; S.origMethod = S.payment_method;
                return loadProfile().then(function (p) { if (p && p.first_name && p.first_name === S.rep.first && (p.last_name || '') === S.rep.last) S.rep.who = 'owner'; });
            });
        }
        var ready = editId ? fetchEdit() : Promise.resolve();

        // ---- start: the dates and rooms picked on the Rooms page or the landing page (kept up to 30 minutes, also through sign-in)
        var fromSel = null;
        if (pre.get('sel') && !S.stays.length && !S.draft.check_in) {
            var sv = window.VSRooms && window.VSRooms.loadSelection();
            if (sv && sv.check_in && sv.rooms && Object.keys(sv.rooms).length) fromSel = sv;
            else if (pre.get('sel')) fromSel = { expired: true };
        }
        frame();
        loadAmenities();
        // make sure type info is known for restored stays before the first render
        ready.then(function () { return Promise.all(S.stays.map(function (s) { return roomsFor(s.check_in, s.check_out); })); }).then(function () {
            if (S.step > stepsDef.length - 1) S.step = 0;
            render(); frame();
            if (draftReset) VS.alertBox('vsStepErr', 'The booking form has changed, so your earlier progress was cleared. Please start again.', 'info');
            if (fromSel) applySelection(fromSel);
            if (S.presetRoom && S.draft.check_in) { var id = S.presetRoom; delete S.presetRoom; roomsFor(S.draft.check_in, S.draft.check_out).then(function (list) { if (list && list.some(function (t) { return t.id === id && t.available_count > 0; })) { S.draft.rooms[id] = 1; S.draft.toConfirm = true; paintRooms(); } }); }
        });
        window.addEventListener('beforeunload', function (e) { if (S.stays.length && !window.__vsLeavingOk && !(editId && !editInfo)) { e.preventDefault(); e.returnValue = ''; } });
        W.state = function () { return S; };
    };
})();
