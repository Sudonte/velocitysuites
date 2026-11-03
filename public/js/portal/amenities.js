/*
 * Request additional amenities during a stay: pick the date range, then the room, then the amenities. A request is pending until the
 * front desk approves or rejects it; only an approved one is added to the bill (and the ID discount covers it). Uses
 * GET/POST /api/guest/{bookings|reservations}/{id}/additional-amenities, the app's own endpoints.
 */
(function () {
    'use strict';
    var VS = window.VS;
    var A = window.VSAmenities = {};

    var CHIP = { pending: ['wait', 'Pending'], approved: ['ok', 'Approved'], rejected: ['bad', 'Not approved'] };

    A.mount = function (el, it, kind, booking, _requests, reload) {
        var isRes = kind === 'reservation';
        var path = '/guest/' + (isRes ? 'reservations' : 'bookings') + '/' + it.id + '/additional-amenities';
        var b = booking || (kind === 'booking' ? it : null);
        if (!b || !/CHECKED_IN/.test(b.booking_status || '')) { el.innerHTML = ''; return; }
        el.innerHTML = '<section class="vs-card" id="vsAmenCard"><h2>Request amenities</h2><div id="vsAmenBody">' + VS.skeleton(3) + '</div></section>';
        var body = el.querySelector('#vsAmenBody');
        Promise.all([VS.api(path), VS.api('/amenities', { query: { pricing_type: 'paid' } })]).then(function (rs) {
            if (!rs[0].ok) { body.innerHTML = VS.errorState(rs[0].message); return; }
            var d = rs[0].data, catalog = rs[1].ok ? rs[1].data : [];
            var list = (d.requests || []).slice().sort(function (a, c) { return new Date(c.requested_at) - new Date(a.requested_at); });
            var reqHtml = list.length ? '<h3 class="h6 fw-bold mt-3">Your requests</h3>' + list.map(function (r) {
                var c = CHIP[r.status] || CHIP.pending;
                return '<div class="py-2 border-bottom"><div class="vs-row"><span class="fw-semibold">' + VS.esc(r.name) + ' × ' + r.quantity + (r.room_number ? ' <span class="small text-muted">(Room ' + VS.esc(r.room_number) + ')</span>' : '') + '</span><span>' + VS.money(r.subtotal) + '</span></div>' +
                    '<div class="small"><span class="vs-chip ' + c[0] + '">' + c[1] + '</span> <span class="text-muted">' + VS.esc(VS.dateTime(r.requested_at)) + '</span></div>' + (r.status === 'rejected' && r.rejection_reason ? '<div class="small text-danger">' + VS.esc(r.rejection_reason) + '</div>' : '') + '</div>';
            }).join('') + '<p class="text-muted small mt-2 mb-0">Pending requests are not on your bill until the front desk approves them.</p>' : '';
            if (!d.can_request) { body.innerHTML = '<p class="text-muted mb-0">' + VS.esc(d.reason || 'Amenity requests are not available right now.') + '</p>' + reqHtml; return; }
            var stays = d.stays && d.stays.length ? d.stays : [{ stay_id: null, check_in: '', check_out: '', rooms: d.rooms || [] }];
            var st = { stay: stays[0], room: null, qty: {} };
            function paintForm() {
                var rooms = st.stay.rooms || [];
                body.innerHTML = '<div id="vsAmenErr"></div>' +
                    (stays.length > 1 ? '<label class="form-label fw-semibold" for="amStay">Date range</label><select class="form-select mb-3" id="amStay">' + stays.map(function (s, i) { return '<option value="' + i + '"' + (s === st.stay ? ' selected' : '') + '>' + VS.esc(VS.date(s.check_in) + ' → ' + VS.date(s.check_out)) + '</option>'; }).join('') + '</select>' : '') +
                    '<label class="form-label fw-semibold" for="amRoom">Room</label><select class="form-select mb-3" id="amRoom"><option value="">Choose a room</option>' + rooms.map(function (r) { return '<option value="' + r.room_id + '"' + (st.room == r.room_id ? ' selected' : '') + '>Room ' + VS.esc(r.room_number) + ' · ' + VS.esc(r.room_type || '') + '</option>'; }).join('') + '</select>' +
                    '<div id="amList">' + (st.room ? catalog.map(function (a) {
                        var q = st.qty[a.id] || 0;
                        return '<div class="d-flex align-items-center gap-2 py-1"><div class="flex-grow-1">' + VS.esc(a.amenity_name) + ' <span class="small text-muted">' + VS.money(a.charge) + '</span></div><button class="btn btn-sm btn-outline-velocity" data-d="' + a.id + '" aria-label="Fewer ' + VS.esc(a.amenity_name) + '">−</button><span class="vs-num fw-bold" style="min-width:1.5rem;text-align:center">' + q + '</span><button class="btn btn-sm btn-velocity" data-u="' + a.id + '" aria-label="More ' + VS.esc(a.amenity_name) + '">+</button></div>';
                    }).join('') : '<p class="text-muted small">Choose a room to pick amenities.</p>') + '</div>' +
                    '<label class="form-label fw-semibold mt-3" for="amNote">Note <span class="text-muted fw-normal">(optional)</span></label><input class="form-control mb-3" id="amNote" maxlength="500">' +
                    '<button class="btn btn-velocity" id="amSend" data-busy-text="Sending">Send request</button>' + reqHtml;
                var ss = body.querySelector('#amStay'); if (ss) ss.onchange = function () { st.stay = stays[+ss.value]; st.room = null; st.qty = {}; paintForm(); };
                body.querySelector('#amRoom').onchange = function (e) { st.room = e.target.value || null; st.qty = {}; paintForm(); };
                body.querySelectorAll('[data-u]').forEach(function (b2) { b2.onclick = function () { st.qty[b2.dataset.u] = (st.qty[b2.dataset.u] || 0) + 1; paintForm(); }; });
                body.querySelectorAll('[data-d]').forEach(function (b2) { b2.onclick = function () { st.qty[b2.dataset.d] = Math.max(0, (st.qty[b2.dataset.d] || 0) - 1); paintForm(); }; });
                body.querySelector('#amSend').onclick = function (e) {
                    var btn = e.currentTarget, items = Object.keys(st.qty).filter(function (k) { return st.qty[k] > 0; }).map(function (k) { return { amenity_id: +k, quantity: st.qty[k] }; });
                    if (!st.room) { VS.alertBox('vsAmenErr', 'Choose a room first.'); return; }
                    if (!items.length) { VS.alertBox('vsAmenErr', 'Choose at least one amenity.'); return; }
                    VS.confirm({ title: 'Send amenity request?', message: 'The front desk will approve or reject it. Only approved amenities are added to your bill.', confirmText: 'Send request' }).then(function (yes) {
                        if (!yes) return;
                        var payload = { rooms: [{ room_id: +st.room, items: items }], note: body.querySelector('#amNote').value.trim() || undefined };
                        if (st.stay.stay_id) payload.stay_id = st.stay.stay_id;
                        VS.busy(btn, function () { return VS.api(path, { method: 'POST', body: payload }); }).then(function (r) {
                            if (!r) return; if (r.ok) { VS.toast('Request sent. Waiting for the front desk.', 'success'); reload(); } else VS.alertBox('vsAmenErr', r.message);
                        });
                    });
                };
            }
            paintForm();
        });
    };
})();
