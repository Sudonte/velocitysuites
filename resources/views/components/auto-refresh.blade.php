{{-- Silent dashboard refresh: re-fetches the current page every 30s and
     swaps <main>'s content so counts/tables stay fresh without a manual
     reload. Polling (not websockets) keeps this shared-hosting friendly.
     Skips ticks while the tab is hidden, a modal is open, or the user is
     interacting with a form, so it never yanks UI state mid-action. --}}
@push('scripts')
<script>
(function () {
    const REFRESH_MS = 30000;

    async function refreshMain() {
        if (document.hidden) return;
        if (document.querySelector('.modal.show')) return;
        if (document.activeElement && document.activeElement.closest && document.activeElement.closest('form')) return;

        try {
            const resp = await fetch(window.location.href, { headers: { 'X-Requested-With': 'auto-refresh' } });
            if (!resp.ok) return;
            const doc = new DOMParser().parseFromString(await resp.text(), 'text/html');
            const fresh = doc.querySelector('main');
            const current = document.querySelector('main');
            if (fresh && current) {
                current.innerHTML = fresh.innerHTML;
                // Anything stateful bound to the old DOM (e.g. a Chart.js
                // canvas) needs to know its nodes just got replaced, since
                // this swap doesn't re-run <script> tags inside main.
                window.dispatchEvent(new Event('auto-refresh:swapped'));
            }
        } catch (e) {
            // offline or transient error - just try again next tick
        }
    }

    setInterval(refreshMain, REFRESH_MS);
})();
</script>
@endpush
