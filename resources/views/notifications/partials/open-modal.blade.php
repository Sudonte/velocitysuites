{{--
    Notification detail modal (all roles). Clicking a notification opens it
    with the full message, marks it read in the background, and - when the
    notification is about something with its own page (see
    App\Support\NotificationLink) - offers to go there.
--}}
<div class="modal fade" id="notificationOpenModal" tabindex="-1" aria-labelledby="notificationOpenTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title" id="notificationOpenTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">
                    <i class="fas fa-clock"></i> <span data-notif-field="time"></span>
                    &middot; <span class="notification-category-tag" data-notif-field="category"></span>
                </p>
                <div class="d-flex flex-wrap gap-1 mb-2 d-none" data-notif-field="audience"></div>
                <p class="mb-0" style="white-space: pre-line;" data-notif-field="message"></p>
                <p class="mt-3 mb-0 fw-semibold d-none" data-notif-field="prompt"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-notif-field="dismiss">Close</button>
                <a href="#" class="btn btn-primary d-none" data-notif-field="go"><i class="fas fa-arrow-right"></i> <span></span></a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Looked up on every open, and clicks are delegated: the list and this modal live in <main>,
    // which auto-refresh and live sections replace - handlers bound once at load would go dead.
    if (!document.getElementById('notificationOpenModal')) return;
    let modalEl = null;
    const field = (name) => modalEl.querySelector('[data-notif-field="' + name + '"]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    function markRead(item) {
        if (item.dataset.unread !== '1') return;
        item.dataset.unread = '0';
        item.classList.remove('is-unread');
        item.querySelector('.notification-title .badge')?.remove();
        fetch(item.dataset.readUrl, {
            method: 'PUT',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then((r) => r.ok ? r.json() : null).then((data) => {
            if (!data) return;
            // Keep the navbar bell in step without a reload.
            document.querySelectorAll('[data-notif-unread-count]').forEach((badge) => {
                badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                badge.classList.toggle('d-none', data.unread_count === 0);
            });
        }).catch(() => {});
    }

    function open(item) {
        modalEl = document.getElementById('notificationOpenModal');
        if (!modalEl || !item) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        field('time').textContent = item.dataset.time;
        field('category').textContent = item.dataset.category;
        field('message').textContent = item.dataset.message;
        modalEl.querySelector('#notificationOpenTitle').textContent = item.dataset.title;

        const audience = field('audience');
        audience.innerHTML = '';
        (item.dataset.audience ? item.dataset.audience.split('|') : []).forEach((label) => {
            const badge = document.createElement('span');
            badge.className = 'badge announcement-audience-badge';
            badge.textContent = label;
            audience.appendChild(badge);
        });
        audience.classList.toggle('d-none', !item.dataset.audience);

        const go = field('go');
        const hasLink = !!item.dataset.linkUrl;
        go.classList.toggle('d-none', !hasLink);
        field('prompt').classList.toggle('d-none', !hasLink);
        field('dismiss').textContent = hasLink ? 'Stay Here' : 'Close';
        if (hasLink) {
            go.href = item.dataset.linkUrl;
            go.querySelector('span').textContent = item.dataset.linkLabel;
            field('prompt').textContent = 'Go to this page now? (' + item.dataset.linkLabel + ')';
        }

        modal.show();
        markRead(item);
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-notif-open]');
        if (trigger) open(trigger.closest('.notification-item'));
    });
    document.addEventListener('keydown', function (e) {
        const trigger = e.target.closest && e.target.closest('[data-notif-open]');
        if (trigger && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(trigger.closest('.notification-item')); }
    });
});
</script>
@endpush

@once
    @push('styles')
    <style>
        .notification-body[data-notif-open] { cursor: pointer; }
        .notification-body[data-notif-open]:hover .notification-title { text-decoration: underline; }
        .notification-body[data-notif-open]:focus-visible { outline: 2px solid var(--brand-color, #dc3545); outline-offset: 4px; border-radius: 4px; }
    </style>
    @endpush
@endonce
