/*
 * The three receipts on screen: Receipt 1 (payment verified), Receipt 2 (check-in), Receipt 3 (check-out, final). The page shows the
 * payload of GET /api/guest/receipts/{number} exactly as the server froze it - the same content the app shows and the PDF prints.
 */
(function () {
    'use strict';
    var VS = window.VS;
    var R = window.VSReceipt = {};

    function n(v) { return Number(v || 0); }
    function row(label, value, cls) { return '<div class="vs-row ' + (cls || '') + '"><span>' + label + '</span><span>' + value + '</span></div>'; }

    function staysHtml(rc) {
        var stays = rc.stays || [];
        if (!stays.length) return '';
        return '<section class="vs-card"><h2>Your stay</h2>' + stays.map(function (s) {
            return '<div class="mb-3"><div class="fw-bold">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + ' · ' + VS.plural(s.nights, 'night') + '</div>' +
                (s.rooms || []).map(function (r) { return row(VS.esc(r.room_type) + ' × ' + r.quantity + ' <span class="small">(' + VS.money(r.price_per_night) + ' × ' + VS.plural(r.nights, 'night') + ')</span>', VS.money(r.subtotal)) +
                    ((r.assigned_room_numbers || []).length ? '<div class="small text-muted">Room ' + VS.esc(r.assigned_room_numbers.join(', ')) + '</div>' : ''); }).join('') +
                (s.amenities || []).map(function (a) { return row('<span class="small">' + VS.esc(a.amenity_name) + ' × ' + a.quantity + (a.room_label ? ' (' + VS.esc(a.room_label) + ')' : '') + '</span>', VS.money(a.subtotal), 'vs-sub'); }).join('') + '</div>';
        }).join('') + '</section>';
    }

    function billFinal(h) {
        var out = '<section class="vs-card"><h2>Bill</h2>';
        (h.stays || []).forEach(function (s) {
            out += '<div class="fw-semibold mt-2">' + VS.esc(VS.date(s.check_in)) + ' → ' + VS.esc(VS.date(s.check_out)) + '</div>';
            (s.rooms || []).forEach(function (r) {
                var promo = n(r.promo_off) > 0.009;
                out += row('Room ' + VS.esc(r.room_number || '') + ' · ' + VS.esc(r.room_type) + ' <span class="small">(' + (promo ? VS.money(r.rate) + ' → ' + VS.money(r.rate_after_promo) + ' per night (Promo)' : VS.money(r.rate) + ' × ' + VS.plural(r.nights, 'night')) + ')</span>', VS.money(r.subtotal));
            });
            (s.amenities || []).forEach(function (a) { out += row('<span class="small">' + VS.esc(a.name) + ' × ' + a.quantity + (a.room ? ' (' + VS.esc(a.room) + ')' : '') + '</span>', VS.money(a.subtotal), 'vs-sub'); });
        });
        out += '<hr class="vs-rule">' + row('Room charges', VS.money(h.room_charges));
        if (n(h.promo) > 0.009) out += row('Promo applied' + (h.promo_name ? ': ' + VS.esc(h.promo_name) : ''), '<span class="vs-neg">−' + VS.money(h.promo) + '</span>');
        out += row('Amenities', VS.money(h.amenities_total));
        if (n(h.discount) > 0.009) out += row('Discount' + (h.discount_name ? ' (' + VS.esc(h.discount_name) + ')' : ''), '<span class="vs-neg">−' + VS.money(h.discount) + '</span>') + '<div class="small text-muted">on rooms and amenities</div>';
        (h.other_charges || []).forEach(function (c) { out += row('Other charges: ' + VS.esc(c.name), VS.money(c.amount)); });
        out += '<hr class="vs-rule">' + row('Grand total', VS.money(h.grand_total), 'vs-strong');
        (h.payments || []).forEach(function (p) { out += row(VS.esc(p.label) + (p.method ? ' · ' + VS.esc(/gcash/i.test(p.method) ? 'GCash' : 'Cash') : ''), VS.money(p.amount)); });
        out += row('Paid so far', VS.money(h.total_paid));
        if (h.no_refund_note) out += '<p class="small text-muted mt-2 mb-0">' + VS.esc(h.no_refund_note) + '</p>';
        return out + '</section>';
    }

    function billEarly(rc, h) {
        var cs = rc.checkin_settlement || {};
        var out = '<section class="vs-card"><h2>' + (h.kind === 'checkin_payment' ? 'Check-in payment' : 'Payment verified') + '</h2>';
        if (h.kind === 'checkin_payment') {
            out += (n(h.amount_paid_now) > 0.009 ? row('Paid at check-in', VS.money(h.amount_paid_now)) + (h.payment_method ? row('Payment method', /gcash/i.test(h.payment_method) ? 'GCash' : 'Cash') : '') : '<div class="fw-semibold">No payment at check-in</div>');
            if (n(h.promo) > 0.009) out += row('Promo applied' + (h.promo_name ? ': ' + VS.esc(h.promo_name) : ''), '<span class="vs-neg">−' + VS.money(h.promo) + '</span>');
            if (n(h.discount) > 0.009) out += row('Discount' + (h.discount_name ? ' (' + VS.esc(h.discount_name) + ')' : ''), '<span class="vs-neg">−' + VS.money(h.discount) + '</span>') + '<div class="small text-muted">on rooms and amenities</div>';
            out += '<hr class="vs-rule">' + row('Grand total', VS.money(h.total_amount), 'vs-strong') + row('Paid so far', VS.money(h.total_paid));
        } else {
            out += row('Amount paid', VS.money(h.amount_paid_now)) + (h.payment_method ? row('Payment method', /gcash/i.test(h.payment_method) ? 'GCash' : 'Cash') : '') + (n(h.previous_payments) > 0.009 ? row('Earlier payments', VS.money(h.previous_payments)) : '') +
                '<hr class="vs-rule">' + row('Grand total', VS.money(h.total_amount), 'vs-strong') + row('Paid so far', VS.money(h.total_paid));
        }
        return out + '</section>';
    }

    function earlier(rc) {
        var refs = (rc.receipt_numbers || []).filter(function (x) { return x.receipt_number !== rc.receipt_number; });
        if (!refs.length) return '';
        var names = { 1: 'Receipt 1 · Payment verified', 2: 'Receipt 2 · Check-in', 3: 'Receipt 3 · Check-out (final)' };
        return '<section class="vs-card"><h2>Other receipts of this stay</h2>' + refs.map(function (x) { return '<a class="vs-rcpt d-block text-decoration-none" href="/guest/receipts/' + encodeURIComponent(x.receipt_number) + '"><div class="d-flex justify-content-between"><span class="fw-semibold">' + (names[x.stage] || 'Receipt') + '</span><span class="vs-num">' + VS.money(x.amount) + '</span></div><div class="small text-muted">' + VS.esc(x.receipt_number) + '</div></a>'; }).join('') + '</section>';
    }

    function history(rc) {
        var h = rc.transaction_history || [];
        if (!h.length || (rc.receipt_stage || 0) !== 3) return '';
        return '<section class="vs-card"><h2>Transaction history</h2>' + h.map(function (e) { return row(VS.esc(e.label || e.title || ''), VS.esc(VS.dateTime(e.at || e.date || e.time))); }).join('') + '</section>';
    }

    R.page = function (root, number) {
        var host = root.querySelector('#vsReceipt');
        function load() {
            host.innerHTML = VS.skeleton(6);
            VS.api('/guest/receipts/' + encodeURIComponent(number)).then(function (r) {
                if (r.status === 404) { host.innerHTML = VS.notFound('That receipt is not on your account.'); return; }
                if (!r.ok || !r.data || !r.data.receipt) {
                    host.innerHTML = '<div class="vs-empty"><i class="fas fa-receipt"></i><h2 class="h5 fw-bold">We couldn’t open this receipt</h2><p class="text-muted mb-1">' + VS.esc(r.status === 404 ? 'This receipt is not available.' : (r.message || 'Please try again.')) + '</p>' +
                        '<p class="small text-muted">Tap Try again. If it keeps happening, show this receipt number to the front desk: ' + VS.esc(number) + '</p><button class="btn btn-velocity" id="vsRcRetry">Try again</button></div>';
                    host.querySelector('#vsRcRetry').onclick = load; return;
                }
                var rc = r.data.receipt, h = rc.highlight || {}, stage = rc.receipt_stage || 0, titles = { 1: 'Payment verified', 2: 'Check-in', 3: 'Check-out (final)' };
                var ps = rc.payment_summary || {}, total = n(h.grand_total != null ? h.grand_total : (h.total_amount != null ? h.total_amount : ps.grand_total)), paid = n(h.total_paid != null ? h.total_paid : ps.total_amount_paid), remaining = n(h.remaining_balance != null ? h.remaining_balance : ps.remaining_balance);
                var settled = remaining <= 0.009;
                var pay = stage === 3 ? (settled ? VS.money(0) + ' · Fully paid' : VS.money(remaining) + ' · still due') : settled ? VS.money(0) + ' · Nothing to pay' : VS.money(remaining) + ' · ' + (stage === 2 ? 'at check-out' : 'at check-in');
                host.innerHTML = '<section class="vs-card text-center"><img src="/images/logo.jpg" alt="" width="56" height="48" style="border-radius:8px"><h1 class="h5 fw-bold text-danger mt-2 mb-0">Velocity Suites</h1><div class="small text-muted">Your comfort is our priority</div>' +
                    '<h2 class="h6 fw-bold mt-3 mb-1" style="color:var(--vs-ink)">Receipt ' + stage + ' of 3 · ' + (titles[stage] || 'Receipt') + '</h2><span class="vs-chip ok">' + (stage === 3 && settled ? 'Paid' : stage === 3 ? 'Balance due' : 'Issued') + '</span>' +
                    '<div class="text-start mt-3">' + row('Receipt number', VS.esc(rc.receipt_number)) + row('Transaction', (rc.reservation_id ? 'Reservation #' + rc.reservation_id : 'Booking #' + rc.booking_id)) + row('Date issued', VS.esc(VS.dateTime(rc.issued_at))) + '</div></section>' +
                    staysHtml(rc) + '<section class="vs-card"><h2>Guests</h2>' + row('Account holder', VS.esc(rc.guest_account_name || '')) + row('Representative', VS.esc(rc.representative_name || '')) + row('Adults', rc.adults || 0) + row('Minors', rc.children || 0) +
                    (rc.discount_info && rc.discount_info.id_type ? row('ID / discount', VS.esc(rc.discount_info.id_type) + (rc.discount_info.status === 'approved' ? ' · applied' : '')) : '') + '</section>' +
                    (stage === 3 ? billFinal(h) : billEarly(rc, h)) + earlier(rc) + history(rc) +
                    '<div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-velocity" href="/guest/receipts/' + encodeURIComponent(rc.receipt_number) + '/preview"><i class="fas fa-eye me-1"></i>Preview</a><a class="btn btn-outline-velocity" href="/guest/receipts/' + encodeURIComponent(rc.receipt_number) + '/preview?print=1"><i class="fas fa-print me-1"></i>Print</a><a class="btn btn-outline-velocity" href="/guest/receipts/' + encodeURIComponent(rc.receipt_number) + '/pdf" download><i class="fas fa-download me-1"></i>Download PDF</a></div>' +
                    '<div class="vs-summary"><div class="vs-row"><span>Grand total</span><span>' + VS.money(total) + '</span></div><div class="vs-row"><span>Remaining balance</span><span>' + VS.money(remaining) + '</span></div><div class="vs-row vs-strong"><span>Amount to pay</span><span class="vs-pay' + (settled ? ' settled' : '') + '">' + VS.esc(pay) + '</span></div></div>';
            });
        }
        load();
    };
})();
