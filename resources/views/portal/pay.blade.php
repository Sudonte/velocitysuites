@extends('layouts.portal')
@section('title', 'Pay now - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:760px">
    <nav class="vs-head" aria-label="Breadcrumb"><a href="{{ route('guest.reservations.show', $id) }}" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Reservation #{{ $id }}</a></nav>
    <div id="vsPayHost" class="vs-card" aria-live="polite">Loading...</div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/pay.js') }}?v={{ filemtime(public_path('js/portal/pay.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script>
(function () {
    var host = document.getElementById('vsPayHost'), id = {{ $id }};
    host.innerHTML = VS.skeleton(5);
    VS.api('/guest/reservations/' + id).then(function (r) {
        if (!r.ok) { host.innerHTML = VS.errorState(r.status === 404 || r.status === 403 ? 'We could not find that reservation.' : r.message); return; }
        var it = r.data, ps = it.payment_summary || {}, lines = [], notes = [];
        var bk = it.booking || null, bs = bk ? bk.booking_status : null, st = it.status || '';
        var gone = /CANCELLED|REJECTED/.test(st) || /CANCELLED|COMPLETED|NO_SHOW|CHECKED_IN/.test(bs || '');
        var due = Number(ps.remaining_balance != null ? ps.remaining_balance : (ps.grand_total || it.total_amount_due || 0) - Number(ps.total_amount_paid || 0));
        var waiting = Number(ps.waiting_for_verification || 0) > 0.009;
        var stop = null;
        if (gone) stop = 'This transaction is closed, so there is nothing to pay online.';
        else if (it.payment_method !== 'gcash') stop = 'Pay at the hotel. This reservation is paid in cash at the front desk, so no online payment is needed.';
        else if (bk && st === 'CONVERTED_TO_BOOKING') stop = 'This reservation is now a booking. The rest is paid at the front desk, at check-in or check-out.';
        else if (due <= 0.009) stop = 'Nothing is left to pay. This is fully paid.';
        else if (waiting) stop = 'Payment under review. Your payment is waiting for verification, so a second one cannot be sent yet.';
        (it.stays || []).forEach(function (s) {
            (s.rooms || []).forEach(function (rm) { lines.push({ label: rm.room_type + ' \u00d7 ' + rm.quantity + ' (' + VS.date(s.check_in) + ' \u2192 ' + VS.date(s.check_out) + ')', amount: rm.subtotal }); });
            (s.amenities || []).forEach(function (a) { lines.push({ label: a.name + ' \u00d7 ' + a.quantity + (a.room_number ? ' \u00b7 Room ' + a.room_number : ''), amount: a.subtotal != null ? a.subtotal : (Number(a.price || 0) * Number(a.quantity || 1)) }); });
            if (!(s.amenities || []).length && Number(s.amenities_subtotal) > 0) lines.push({ label: 'Amenities (' + VS.date(s.check_in) + ')', amount: s.amenities_subtotal });
        });
        var pi = it.promo_info || {};
        if (pi.applied) notes.push('Promo: ' + (pi.applied.title || 'applied') + ' \u2212' + VS.money(pi.applied.amount) + ' (verified at check-in).');
        else if ((pi.available || []).length) notes.push('Promos for your rooms (verified at check-in): ' + pi.available.map(function (x) { return x.title || x.promo_name; }).join(', ') + '.');
        var dp = it.discount_preview || {};
        if (it.discount_requested && (dp.discount || dp.amount)) notes.push('Expected discount (' + (dp.name || it.id_card_type || 'ID') + '): \u2212' + VS.money(dp.discount || dp.amount) + ' \u2014 applied after your ID is checked at check-in.');
        var pending = (it.discount_requested && (it.discount_verification_status || 'pending') === 'pending');
        host.classList.remove('vs-card');
        if (stop) { host.innerHTML = '<div class="vs-card text-center py-4"><i class="fas fa-circle-info fa-2x text-muted mb-2"></i><h2 class="h5 fw-bold">' + (/^Pay at the hotel/.test(stop) ? 'Pay at the hotel' : /^Payment under review/.test(stop) ? 'Payment under review' : 'Nothing to pay online') + '</h2><p class="text-muted">' + VS.esc(stop.replace(/^(Pay at the hotel|Payment under review)\.? ?/, '')) + '</p><a class="btn btn-velocity" href="/guest/reservations/' + id + '">Back to details</a></div>'; return; }
        VSPay.run(host, {
            total: Number(ps.grand_total || it.total_amount_due || 0), paid: Number(ps.total_amount_paid || 0), discountPending: pending, depositCap: it.deposit_cap != null ? Number(it.deposit_cap) : null,
            kindLabel: 'Reservation #' + id, lines: lines, notes: notes,
            onCancel: function () { window.location.href = '/guest/reservations/' + id; },
            submit: function (f) {
                var fd = new FormData();
                fd.append('payment_method', 'gcash'); fd.append('payment_type', 'partial'); fd.append('amount_paid', f.amount); fd.append('selected_payment_percentage', f.percent);
                fd.append('reference_number', f.reference_number); fd.append('receipt', f.receipt, f.receipt.name || 'receipt.jpg'); fd.append('idempotency_key', f.key);
                return VS.api('/guest/reservations/' + id + '/payments', { method: 'POST', body: fd });
            },
            success: function () { return '<a class="btn btn-velocity" href="/guest/reservations/' + id + '">View reservation</a>'; }
        });
    });
})();
</script>
@endpush
