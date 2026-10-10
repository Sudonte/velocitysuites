// Flatpickr date pickers (loaded from CDN by every layout). Each
// <input type="date"> becomes a picker that shows "Oct 10, 2026" but still
// submits Y-m-d. The input's min/max attributes are honored and re-read after
// any date change in its form, because other scripts adjust them (e.g.
// partials/stay-date-rules). Add data-no-picker to opt an input out.
// Client-side limits only help the user; the server still validates.
(function () {
    function nextDay(iso) {
        var d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + 1);
        return d.toISOString().slice(0, 10);
    }

    function initDatePickers(root) {
        if (!window.flatpickr) return;
        (root || document).querySelectorAll('input[type="date"]:not([data-no-picker])').forEach(function (input) {
            if (input._flatpickr) return;
            var fp = window.flatpickr(input, {
                altInput: true,
                altFormat: 'M j, Y',
                allowInput: true,
                dateFormat: 'Y-m-d',
                minDate: input.min || null,
                maxDate: input.max || null,
                disableMobile: true,
            });
            // The original input becomes hidden; a hidden required field can't be
            // focused by browser validation, so the visible one carries it.
            if (input.required) {
                fp.altInput.required = true;
                input.required = false;
            }
            var label = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
            if (label) {
                label.id = label.id || input.id + 'Label';
                fp.altInput.setAttribute('aria-labelledby', label.id);
                label.addEventListener('click', function () { fp.open(); });
            }
        });
    }

    function syncPickers(scope) {
        scope.querySelectorAll('input.flatpickr-input').forEach(function (input) {
            var fp = input._flatpickr;
            if (!fp) return;
            fp.set('minDate', input.min || null);
            fp.set('maxDate', input.max || null);
            var shown = fp.selectedDates[0] ? fp.formatDate(fp.selectedDates[0], 'Y-m-d') : '';
            if (shown !== (input.value || '')) {
                fp.setDate(input.value || null, false);
            }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target.matches || !target.matches('input.flatpickr-input')) return;
        var scope = target.closest('form') || document;

        // Check-out must follow check-in.
        if (target.name === 'check_in' && target.value) {
            var checkOut = scope.querySelector('input[name="check_out"]');
            if (checkOut) {
                var earliest = nextDay(target.value);
                if (!checkOut.min || checkOut.min < earliest) checkOut.min = earliest;
                if (checkOut.value && checkOut.value < checkOut.min) checkOut.value = '';
            }
        }
        syncPickers(scope);
    });

    window.initDatePickers = initDatePickers;
    document.addEventListener('DOMContentLoaded', function () { initDatePickers(document); });
})();
