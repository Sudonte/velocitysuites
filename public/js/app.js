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

// Guest capacity: a form marked [data-guest-capacity] has adults + children
// checked against the selected rooms' capacity range, mirroring the server
// rule (App\Support\GuestCapacity). The range comes from room-line selects
// (option data-min-capacity/data-max-capacity) or the form's own
// data-capacity-min/data-capacity-max (per room, times rooms_requested).
function guestCapacityRange(form) {
    const selects = form.querySelectorAll('select[data-capacity-line]');
    if (selects.length) {
        let min = 0;
        let max = 0;
        selects.forEach(function (sel) {
            const opt = sel.selectedOptions[0];
            if (!opt || !opt.value) return;
            const row = sel.closest('.room-line-row');
            const qtyInput = row ? row.querySelector('input[name$="[quantity]"]') : null;
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : '1', 10) || 1);
            const typeMin = parseInt(opt.dataset.minCapacity || '1', 10);
            if (typeMin > 1) min += typeMin * qty;
            max += (parseInt(opt.dataset.maxCapacity || '0', 10) || 0) * qty;
        });
        return { min: Math.max(1, min), max: max };
    }
    const qtyEl = form.querySelector('[name="rooms_requested"]');
    const qty = qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1;
    const typeMin = parseInt(form.dataset.capacityMin || '1', 10);
    const typeMax = parseInt(form.dataset.capacityMax || '0', 10) || 0;
    return { min: Math.max(1, typeMin > 1 ? typeMin * qty : 1), max: typeMax * qty };
}

function initGuestCapacity(form) {
    if (!form || form.dataset.guestCapacityBound) return;
    const adults = form.querySelector('[name="adults"]');
    if (!adults) return;
    form.dataset.guestCapacityBound = '1';
    const children = form.querySelector('[name="children"]');
    const hint = form.querySelector('.guest-capacity-hint');

    const check = function () {
        const range = guestCapacityRange(form);
        const total = (parseInt(adults.value, 10) || 0) + (parseInt(children ? children.value : '0', 10) || 0);
        let message = '';
        if (range.max > 0 && total > range.max) {
            message = 'Adults and children combined (' + total + ') exceed the capacity (' + range.max + ') of the selected room(s).';
        } else if (total < range.min) {
            message = 'The selected room(s) require at least ' + range.min + ' guest(s).';
        }
        adults.setCustomValidity(message);
        if (hint) {
            hint.textContent = message || (range.max > 0 ? 'Allowed: ' + range.min + '–' + range.max + ' guest(s), adults and children combined.' : '');
            hint.classList.toggle('text-danger', !!message);
            hint.classList.toggle('text-muted', !message);
        }
    };

    form.addEventListener('input', check);
    form.addEventListener('change', check);
    check();
}

window.initGuestCapacity = initGuestCapacity;
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-guest-capacity]').forEach(initGuestCapacity);
});

// Global confirmation modal (components/confirm-modal.blade.php). A form with
// data-confirm="<message>" is held on submit until the user confirms; the
// confirm button is then disabled so the action can't be sent twice.
// window.confirmAction({title, message, button, variant}, sourceEl) returns a
// Promise<boolean> for script-driven actions; when sourceEl sits inside an
// open modal it shows an inline confirmation bar there instead of stacking a
// second modal.
(function () {
    let pendingForm = null;
    let pendingSubmitter = null;
    let pendingResolve = null;

    function modalParts() {
        return {
            el: document.getElementById('globalConfirmModal'),
            title: document.getElementById('globalConfirmTitle'),
            message: document.getElementById('globalConfirmMessage'),
            button: document.getElementById('globalConfirmButton'),
        };
    }

    function showModal(opts) {
        const m = modalParts();
        m.title.textContent = opts.title || 'Please confirm';
        m.message.textContent = opts.message || '';
        m.button.textContent = opts.button || 'Confirm';
        m.button.className = 'btn btn-' + (opts.variant || 'primary');
        m.button.disabled = false;
        bootstrap.Modal.getOrCreateInstance(m.el).show();
    }

    function inlineConfirm(container, opts) {
        return new Promise(function (resolve) {
            container.querySelectorAll('.inline-confirm').forEach(function (old) { old.remove(); });
            const bar = document.createElement('div');
            bar.className = 'alert alert-warning d-flex flex-wrap align-items-center gap-2 inline-confirm';
            bar.setAttribute('role', 'alertdialog');
            const text = document.createElement('div');
            text.className = 'flex-grow-1';
            const strong = document.createElement('strong');
            strong.className = 'd-block';
            strong.textContent = opts.title || 'Please confirm';
            const msg = document.createElement('span');
            msg.className = 'small';
            msg.textContent = opts.message || '';
            text.append(strong, msg);
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-sm btn-outline-secondary';
            cancel.textContent = 'Cancel';
            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn btn-sm btn-' + (opts.variant || 'primary');
            ok.textContent = opts.button || 'Confirm';
            bar.append(text, cancel, ok);
            container.prepend(bar);
            bar.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            ok.focus();
            cancel.addEventListener('click', function () { bar.remove(); resolve(false); });
            ok.addEventListener('click', function () { bar.remove(); resolve(true); });
        });
    }

    window.confirmAction = function (opts, sourceEl) {
        const inModal = sourceEl && sourceEl.closest ? sourceEl.closest('.modal.show .modal-content') : null;
        if (inModal) {
            return inlineConfirm(inModal.querySelector('.modal-body') || inModal, opts);
        }
        const m = modalParts();
        if (!m.el || !window.bootstrap) {
            return Promise.resolve(window.confirm(opts.message || opts.title || 'Are you sure?'));
        }
        return new Promise(function (resolve) {
            pendingForm = null;
            pendingResolve = resolve;
            showModal(opts);
        });
    };

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form.matches || !form.matches('form[data-confirm]')) return;
        if (form.dataset.confirmGranted === '1') {
            delete form.dataset.confirmGranted;
            return;
        }
        if (!modalParts().el || !window.bootstrap) return;
        event.preventDefault();
        event.stopImmediatePropagation();

        const opts = {
            title: form.dataset.confirmTitle,
            message: form.dataset.confirm,
            button: form.dataset.confirmButton,
            variant: form.dataset.confirmVariant,
        };
        const hostModal = form.closest('.modal.show .modal-content');
        if (hostModal) {
            const submitter = event.submitter || null;
            inlineConfirm(hostModal.querySelector('.modal-body') || hostModal, opts).then(function (ok) {
                if (!ok) return;
                form.dataset.confirmGranted = '1';
                if (submitter) submitter.disabled = false;
                submitter ? form.requestSubmit(submitter) : form.requestSubmit();
            });
            return;
        }

        pendingResolve = null;
        pendingForm = form;
        pendingSubmitter = event.submitter || null;
        showModal({
            title: form.dataset.confirmTitle,
            message: form.dataset.confirm,
            button: form.dataset.confirmButton,
            variant: form.dataset.confirmVariant,
        });
    }, true);

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target.id !== 'globalConfirmModal') return;
        if (pendingResolve) {
            pendingResolve(false);
            pendingResolve = null;
        }
        pendingForm = null;
        pendingSubmitter = null;
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#globalConfirmButton')) return;
        const m = modalParts();
        if (pendingResolve) {
            const resolve = pendingResolve;
            pendingResolve = null;
            bootstrap.Modal.getOrCreateInstance(m.el).hide();
            resolve(true);
            return;
        }
        if (!pendingForm) return;
        m.button.disabled = true;
        m.button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + m.button.textContent;
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        pendingSubmitter = null;
        form.dataset.confirmGranted = '1';
        bootstrap.Modal.getOrCreateInstance(m.el).hide();
        if (form.requestSubmit) {
            submitter ? form.requestSubmit(submitter) : form.requestSubmit();
        } else {
            form.submit();
        }
    });
})();

// Forms marked data-submit-once disable their submit buttons on submit so a
// double click can't send the action twice.
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches || !form.matches('form[data-submit-once]') || event.defaultPrevented) return;
    form.querySelectorAll('button[type="submit"]').forEach(function (btn) { btn.disabled = true; });
});
