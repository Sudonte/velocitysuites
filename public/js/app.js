// Toast notification function
function showToast(message, type = 'info') {
    const toastHTML = `
        <div class="toast ${type}" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    const toastContainer = document.querySelector('.toast-container') || 
        (() => {
            const container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
            return container;
        })();
    
    const toastElement = document.createElement('div');
    toastElement.innerHTML = toastHTML;
    toastContainer.appendChild(toastElement.firstElementChild);
    
    const toast = new bootstrap.Toast(toastElement.firstElementChild);
    toast.show();
    
    // Remove element after toast hides
    toastElement.firstElementChild.addEventListener('hidden.bs.toast', function() {
        this.remove();
    });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Modal helpers
function openModal(modalId) {
    const modal = new bootstrap.Modal(document.getElementById(modalId));
    modal.show();
}

function closeModal(modalId) {
    const modal = bootstrap.Modal.getInstance(document.getElementById(modalId));
    if (modal) modal.hide();
}

// Confirm delete
function confirmDelete(url, message = 'Are you sure you want to delete this item?') {
    if (confirm(message)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.innerHTML = `
            <input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').getAttribute('content')}">
            <input type="hidden" name="_method" value="DELETE">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amount);
}

// Format date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

// Format time
function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-PH', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

// Calculate night count
function calculateNights(checkInDate, checkOutDate) {
    const checkIn = new Date(checkInDate);
    const checkOut = new Date(checkOutDate);
    const diffTime = Math.abs(checkOut - checkIn);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays;
}

// Password show/hide toggle - works for any button with class
// "toggle-password" placed alongside a password field (same .input-group
// or immediately preceding sibling). Delegated so it works on forms
// rendered after page load too, and needs no per-page JS.
// Icon convention: closed/crossed eye (fa-eye-slash) while hidden - the
// field's default, at-rest state - open eye (fa-eye) once revealed. Every
// password field's initial markup must start with fa-eye-slash to match.
document.addEventListener('click', function (event) {
    const toggle = event.target.closest('.toggle-password');
    if (!toggle) return;

    const group = toggle.closest('.input-group') || toggle.parentElement;
    const input = group ? group.querySelector('input[type="password"], input[type="text"].password-revealed') : null;
    if (!input) return;

    const icon = toggle.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        input.classList.add('password-revealed');
        if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
        toggle.setAttribute('aria-label', 'Hide password');
        toggle.setAttribute('title', 'Hide password');
    } else {
        input.type = 'password';
        input.classList.remove('password-revealed');
        if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
        toggle.setAttribute('aria-label', 'Show password');
        toggle.setAttribute('title', 'Show password');
    }
});

// Sidebar collapse/expand (desktop only, all 4 roles share this one
// component) - toggled via the .sidebar-toggle-btn button in the sidebar's
// brand header. Selected by class, not id: the sidebar component is included
// twice per page (desktop rail + mobile offcanvas copy), so an id would be
// duplicated in the DOM - closest('.sidebar-toggle-btn') always resolves to
// whichever instance was actually clicked regardless. State persists per-
// browser via localStorage so it survives navigation and reloads; the early
// inline script in layouts/app.blade.php applies the class before first
// paint so it doesn't flash open first. Works identically on tap (mobile/
// tablet touch) and click (desktop) since both fire a standard 'click' event.
document.addEventListener('click', function (event) {
    const toggle = event.target.closest('.sidebar-toggle-btn');
    if (!toggle) return;

    const collapsed = document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    toggle.setAttribute('title', label);
    toggle.setAttribute('aria-label', label);
});

// Profile picture upload preview (System Administrator profile) - shows the
// chosen file before the form is submitted, via FileReader. No-op on pages
// without a #profilePictureInput.
document.addEventListener('change', function (event) {
    if (event.target.id !== 'profilePictureInput') return;

    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const preview = document.getElementById('profilePicturePreview');
    const placeholder = document.getElementById('profilePicturePlaceholder');
    if (!preview) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        preview.src = e.target.result;
        preview.style.display = '';
        if (placeholder) placeholder.style.display = 'none';
    };
    reader.readAsDataURL(file);
});

// Dashboard live clock (components/page-header.blade.php's showClock prop) -
// no-op on any page without the [data-page-header-clock] block.
(function () {
    const timeEl = document.querySelector('[data-clock-time]');
    const dateEl = document.querySelector('[data-clock-date]');
    if (!timeEl || !dateEl) return;

    function tick() {
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
        dateEl.textContent = now.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    tick();
    setInterval(tick, 1000 * 30);
})();

// Collapsible dashboard sections (components/collapsible-card.blade.php) -
// Bootstrap's own collapse.js handles the actual show/hide; this only
// (a) restores each section's saved open/closed state on load and after
// the dashboards' auto-refresh swap (same re-init-on-swap pattern the
// chart redraw code already uses, since auto-refresh replaces <main>'s
// innerHTML wholesale without re-running inline scripts), and
// (b) persists a state change to localStorage and keeps the chevron icon
// in sync, since Bootstrap's collapse events don't touch localStorage or
// icons on their own.
function initCollapsibleCards() {
    document.querySelectorAll('[data-collapse-persist-key]').forEach(function (target) {
        const key = target.dataset.collapsePersistKey;
        const saved = localStorage.getItem(key);
        if (saved === null) return; // no saved preference - keep the server-rendered default

        const toggler = document.querySelector('[data-bs-target="#' + target.id + '"]');
        const shouldShow = saved === '1';
        target.classList.toggle('show', shouldShow);
        if (toggler) {
            toggler.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
            const chevron = toggler.querySelector('.collapsible-card-chevron');
            if (chevron) chevron.classList.toggle('rotated', !shouldShow);
        }
    });
}

document.addEventListener('DOMContentLoaded', initCollapsibleCards);
window.addEventListener('auto-refresh:swapped', initCollapsibleCards);

document.addEventListener('shown.bs.collapse', function (event) {
    if (event.target.dataset.collapsePersistKey) {
        localStorage.setItem(event.target.dataset.collapsePersistKey, '1');
    }
    // A chart initialized while its collapsible container was closed (e.g.
    // restored collapsed from localStorage on load) gets a 0x0 canvas and
    // never redraws on its own - Chart.js's responsive:true only recomputes
    // on a window resize event, which expanding a <details>-like panel
    // doesn't fire by itself. Nudge it explicitly once the panel is visible.
    if (event.target.querySelector('canvas')) {
        window.dispatchEvent(new Event('resize'));
    }
});
document.addEventListener('hidden.bs.collapse', function (event) {
    if (event.target.dataset.collapsePersistKey) {
        localStorage.setItem(event.target.dataset.collapsePersistKey, '0');
    }
});
document.addEventListener('show.bs.collapse', function (event) {
    const toggler = document.querySelector('[data-bs-target="#' + event.target.id + '"]');
    const chevron = toggler ? toggler.querySelector('.collapsible-card-chevron') : null;
    if (chevron) chevron.classList.remove('rotated');
});
document.addEventListener('hide.bs.collapse', function (event) {
    const toggler = document.querySelector('[data-bs-target="#' + event.target.id + '"]');
    const chevron = toggler ? toggler.querySelector('.collapsible-card-chevron') : null;
    if (chevron) chevron.classList.add('rotated');
});

// Dashboard content preview/expand ("Recent Booking & Reservations",
// "Recent Activities", "System Notifications & Alerts", "Top Room Types",
// etc.) - distinct from the whole-card collapse above: the card itself
// always stays visible, only the rows/items past the server-rendered
// preview count (marked .preview-extra) are hidden, toggled by a button.
// Every row is already in the DOM, so no page reload or fetch is needed.
// Same localStorage-persistence + re-init-on-swap convention as
// initCollapsibleCards() above, so an expanded section doesn't silently
// collapse back on the next 30s auto-refresh swap.
function applyPreviewState(container, expanded) {
    container.classList.toggle('preview-expanded', expanded);
    container.querySelectorAll('.preview-extra').forEach(function (el) {
        el.classList.toggle('d-none', !expanded);
    });
    const btn = container.querySelector('.preview-toggle-btn');
    if (btn) {
        btn.innerHTML = expanded
            ? '<i class="fas fa-chevron-up"></i> Collapse'
            : '<i class="fas fa-chevron-down"></i> Expand';
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

function initPreviewLists() {
    document.querySelectorAll('[data-preview-persist-key]').forEach(function (container) {
        const saved = localStorage.getItem(container.dataset.previewPersistKey);
        if (saved === null) return; // no saved preference - keep the server-rendered preview state
        applyPreviewState(container, saved === '1');
    });
}

document.addEventListener('DOMContentLoaded', initPreviewLists);
window.addEventListener('auto-refresh:swapped', initPreviewLists);

document.addEventListener('click', function (event) {
    const btn = event.target.closest('.preview-toggle-btn');
    if (!btn) return;
    const container = btn.closest('[data-preview-list]');
    if (!container) return;

    const expanded = !container.classList.contains('preview-expanded');
    applyPreviewState(container, expanded);
    if (container.dataset.previewPersistKey) {
        localStorage.setItem(container.dataset.previewPersistKey, expanded ? '1' : '0');
    }
});
