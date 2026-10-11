{{-- window.bindCustomRangeToggle(form): From/To fields ([data-custom-only]) show only when the Custom period is picked. --}}
<script>
window.bindCustomRangeToggle = window.bindCustomRangeToggle || function (form) {
    const fields = form.querySelectorAll('[data-custom-only]');
    const sync = function () {
        const custom = form.querySelector('input[name="period"]:checked')?.value === 'custom';
        fields.forEach(function (el) {
            el.classList.toggle('d-none', !custom);
            el.querySelectorAll('input').forEach(function (input) { input.disabled = !custom; });
        });
    };
    form.querySelectorAll('input[name="period"]').forEach(function (radio) { radio.addEventListener('change', sync); });
    sync();
};
</script>
