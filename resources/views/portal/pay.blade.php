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
        var it = r.data, ps = it.payment_summary || {}, lines = [];
        (it.stays || []).forEach(function (s) {
            (s.rooms || []).forEach(function (rm) { lines.push({ label: rm.room_type + ' × ' + rm.quantity + ' (' + VS.date(s.check_in) + ' → ' + VS.date(s.check_out) + ')', amount: rm.subtotal }); });
            if (Number(s.amenities_subtotal) > 0) lines.push({ label: 'Amenities (' + VS.date(s.check_in) + ')', amount: s.amenities_subtotal });
        });
        var pending = (it.discount_requested && (it.discount_verification_status || 'pending') === 'pending');
        host.classList.remove('vs-card');
        VSPay.run(host, {
            total: Number(ps.grand_total || it.total_amount_due || 0), paid: Number(ps.total_amount_paid || 0), discountPending: pending, depositCap: it.deposit_cap != null ? Number(it.deposit_cap) : null,
            kindLabel: 'Reservation #' + id, lines: lines,
            onCancel: function () { window.location.href = '/guest/reservations/' + id; },
            submit: function (f) {
                var fd = new FormData();
                fd.append('payment_method', 'gcash'); fd.append('payment_type', 'partial'); fd.append('amount_paid', f.amount); fd.append('selected_payment_percentage', f.percent);
                fd.append('gcash_number', f.gcash_number); fd.append('reference_number', f.reference_number); fd.append('receipt', f.receipt, f.receipt.name || 'receipt.jpg'); fd.append('idempotency_key', f.key);
                return VS.api('/guest/reservations/' + id + '/payments', { method: 'POST', body: fd });
            },
            success: function () { return '<a class="btn btn-velocity" href="/guest/reservations/' + id + '">View reservation</a>'; }
        });
    });
})();
</script>
@endpush
