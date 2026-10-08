{{-- 48-hour advance check-in note + date-picker wiring (ids check_in / check_out). One rule: \App\Support\CheckInWindow --}}
<div class="alert alert-info small mb-3" id="check-in-notice">
    <i class="fas fa-info-circle"></i> {{ \App\Support\CheckInWindow::notice() }}
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var checkIn = document.getElementById('check_in');
        var checkOut = document.getElementById('check_out');
        if (!checkIn || !checkOut) return;
        // Check-out stays at least 1 day after the chosen check-in; a stale check-out is cleared.
        checkIn.addEventListener('change', function () {
            if (!checkIn.value) return;
            var next = new Date(checkIn.value + 'T00:00:00');
            next.setDate(next.getDate() + 1);
            var min = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
            checkOut.min = min;
            if (checkOut.value && checkOut.value < min) checkOut.value = '';
        });
    });
</script>
