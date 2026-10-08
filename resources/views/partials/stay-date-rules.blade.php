{{--
    Stay-date rule for every guest web date picker (see App\Support\CheckInWindow): the earliest check-in is today + 2 days
    (hotel time), later dates are fine, and check-out must be at least 1 day after the chosen check-in. Dates that break it are
    disabled in the picker itself. Wires every form that has a check_in + check_out input; include once per page.
--}}
<script>
(function () {
    var EARLIEST = @json(\App\Support\CheckInWindow::earliest());
    function nextDay(iso) {
        var d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + 1);
        return d.toISOString().slice(0, 10);
    }
    function wire(checkIn, checkOut) {
        // An unchanged check-in on an existing reservation may already be inside the lead time - leave it selectable.
        var min = checkIn.dataset.keepExisting && checkIn.value && checkIn.value < EARLIEST ? checkIn.value : EARLIEST;
        checkIn.min = min;
        function sync() {
            var base = checkIn.value && checkIn.value >= min ? checkIn.value : min;
            checkOut.min = nextDay(base);
            if (checkOut.value && checkOut.value < checkOut.min) { checkOut.value = checkOut.min; }
        }
        checkIn.addEventListener('change', sync);
        sync();
    }
    document.querySelectorAll('form').forEach(function (form) {
        var i = form.querySelector('input[type="date"][name="check_in"]');
        var o = form.querySelector('input[type="date"][name="check_out"]');
        if (i && o) { wire(i, o); }
    });
})();
</script>
