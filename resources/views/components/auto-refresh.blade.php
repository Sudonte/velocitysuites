{{-- Silent page refresh for dashboards/notification lists: keeps counts and tables
     fresh without a manual reload, by swapping <main>'s content.
     Staff pages: handled by live sync (public/js/app.js) - <main> is swapped only
     when the data actually changed. Other roles (the guest dashboard): re-fetched
     every 30s. Either way it skips while the tab is hidden, a modal is open, or the
     user is in a form, so it never yanks UI state mid-action. --}}
@push('scripts')
@if(auth()->check() && in_array(auth()->user()->role, ['admin', 'manager', 'receptionist'], true))
<script>window.liveSyncMain = true;</script>
@else
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
@endif
@endpush
