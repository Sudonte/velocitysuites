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
    
    const wrapper = document.createElement('div');
    wrapper.innerHTML = toastHTML;
    // Keep a reference before appending - appending moves it out of the wrapper.
    const toastEl = wrapper.firstElementChild;
    toastContainer.appendChild(toastEl);

    const toast = new bootstrap.Toast(toastEl);
    toast.show();

    // Remove element after toast hides
    toastEl.addEventListener('hidden.bs.toast', function() {
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
// Promise<boolean> for script-driven actions. When another modal is already
// open (e.g. the Check In panel), the confirmation opens stacked ON TOP of it -
// a real floating modal with its own backdrop - and closing it returns to the
// panel underneath.
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

    // The modal that was open underneath while the confirmation is stacked on top of it.
    let hostModalEl = null;

    function showModal(opts) {
        const m = modalParts();
        m.title.textContent = opts.title || 'Please confirm';
        m.message.textContent = opts.message || '';
        m.button.textContent = opts.button || 'Confirm';
        m.button.className = 'btn btn-' + (opts.variant || 'primary');
        m.button.disabled = false;

        hostModalEl = document.querySelector('.modal.show:not(#globalConfirmModal)');
        m.el.classList.toggle('confirm-stacked', !!hostModalEl);
        if (hostModalEl) {
            // The host modal's focus trap would pull focus back out of the confirmation.
            const host = bootstrap.Modal.getInstance(hostModalEl);
            if (host && host._focustrap) host._focustrap.deactivate();
        }
        bootstrap.Modal.getOrCreateInstance(m.el).show();
    }

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target.id !== 'globalConfirmModal' || !hostModalEl) return;
        // Bootstrap appends one backdrop per modal - lift the newest above the host modal.
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length) backdrops[backdrops.length - 1].classList.add('confirm-stacked-backdrop');
    });

    window.confirmAction = function (opts, sourceEl) {
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
        event.target.classList.remove('confirm-stacked');
        if (hostModalEl && hostModalEl.classList.contains('show')) {
            // Closing the stacked confirmation must leave the host modal fully working.
            document.body.classList.add('modal-open');
            const host = bootstrap.Modal.getInstance(hostModalEl);
            if (host && host._focustrap) host._focustrap.activate();
        }
        hostModalEl = null;
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

// Global message modal (components/notice-modal.blade.php) - the in-app
// replacement for alert(). window.showNotice({title, message, variant, link})
// returns a Promise that resolves when it is closed. Like the confirmation, it
// opens stacked on top of a modal that is already open, and closing it returns
// to that modal.
(function () {
    const ICONS = {
        success: 'fa-circle-check text-success',
        danger: 'fa-circle-exclamation text-danger',
        warning: 'fa-triangle-exclamation text-warning',
        info: 'fa-circle-info text-primary',
    };
    let hostModalEl = null;
    let pendingResolve = null;

    window.showNotice = function (opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        const el = document.getElementById('globalNoticeModal');
        if (!el || !window.bootstrap) {
            window.alert(opts.message || opts.title || '');
            return Promise.resolve();
        }
        const variant = ICONS[opts.variant] ? opts.variant : 'info';
        document.getElementById('globalNoticeTitleText').textContent = opts.title || (variant === 'danger' ? 'Something went wrong' : 'Notice');
        document.getElementById('globalNoticeIcon').className = 'fas me-1 ' + ICONS[variant];
        document.getElementById('globalNoticeMessage').textContent = opts.message || '';
        const link = document.getElementById('globalNoticeLink');
        link.classList.toggle('d-none', !(opts.link && opts.link.href));
        if (opts.link && opts.link.href) {
            link.href = opts.link.href;
            link.textContent = opts.link.label || 'Open';
        }

        hostModalEl = document.querySelector('.modal.show:not(#globalNoticeModal)');
        el.classList.toggle('confirm-stacked', !!hostModalEl);
        if (hostModalEl) {
            const host = bootstrap.Modal.getInstance(hostModalEl);
            if (host && host._focustrap) host._focustrap.deactivate();
        }
        return new Promise(function (resolve) {
            pendingResolve = resolve;
            bootstrap.Modal.getOrCreateInstance(el).show();
        });
    };

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target.id !== 'globalNoticeModal' || !hostModalEl) return;
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length) backdrops[backdrops.length - 1].classList.add('confirm-stacked-backdrop');
    });

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target.id !== 'globalNoticeModal') return;
        event.target.classList.remove('confirm-stacked');
        if (hostModalEl && hostModalEl.classList.contains('show')) {
            document.body.classList.add('modal-open');
            const host = bootstrap.Modal.getInstance(hostModalEl);
            if (host && host._focustrap) host._focustrap.activate();
        }
        hostModalEl = null;
        if (pendingResolve) {
            const resolve = pendingResolve;
            pendingResolve = null;
            resolve();
        }
    });
})();

// Live sections: a POST form inside a [data-live-region][id] element (a whole
// page opts in with @section('livePage', '1') - see layouts/app.blade.php) is
// sent in the background instead of reloading the page. The server handles it
// exactly as before (same validation, same redirect back); the redirected
// page's HTML replaces only the live regions, so the list updates where the
// user is and the scroll position stays. Runs after the confirmation modal
// (that listener is on the capture phase). A response that lands on a
// different page navigates there as usual. Opt a form out with data-live-off.
(function () {
    const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function messages(doc, selector) {
        return Array.from(doc.querySelectorAll('#pageContent ' + selector))
            .filter((el) => !el.classList.contains('d-none') && el.textContent.trim() !== '')
            .map((el) => el.textContent.replace(/\s+/g, ' ').trim());
    }

    function hideModal(modalEl) {
        return new Promise(function (resolve) {
            if (!modalEl || !modalEl.classList.contains('show')) return resolve();
            modalEl.addEventListener('hidden.bs.modal', function () { resolve(); }, { once: true });
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!form.matches || event.defaultPrevented || form.hasAttribute('data-live-off')) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post' || !form.closest('[data-live-region][id]')) return;
        if (!window.fetch || !window.DOMParser) return;
        event.preventDefault();

        const data = new FormData(form);
        if (event.submitter && event.submitter.name) data.append(event.submitter.name, event.submitter.value);
        const buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
        buttons.forEach((b) => { b.disabled = true; });
        const regions = Array.from(document.querySelectorAll('[data-live-region][id]'));
        regions.forEach((r) => r.classList.add('live-updating'));
        const done = () => {
            buttons.forEach((b) => { b.disabled = false; });
            regions.forEach((r) => r.classList.remove('live-updating'));
        };

        let response;
        try {
            response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'text/html' } });
        } catch (e) {
            done();
            form.submit(); // network trouble: fall back to the normal full-page submit
            return;
        }
        if (!response.ok) {
            done();
            window.showNotice({ variant: 'danger', message: 'That action could not be completed (error ' + response.status + '). Please try again.' });
            return;
        }
        const landed = new URL(response.url, window.location.href);
        if (landed.pathname !== window.location.pathname) {
            window.location.href = response.url; // the action moved on to another page
            return;
        }

        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const errors = messages(doc, '.alert-danger');
        const success = messages(doc, '.alert-success');
        const modalEl = form.closest('.modal');

        // A form in a modal that comes back with errors stays open with what was typed.
        if (errors.length && modalEl) {
            done();
            window.showNotice({ variant: 'danger', title: 'Please check the form', message: errors.join(' ') });
            return;
        }

        await hideModal(modalEl && modalEl.classList.contains('show') ? modalEl : null);
        regions.forEach(function (region) {
            const fresh = doc.getElementById(region.id);
            if (!fresh) return;
            // The toast below reports success; the banner at the top of the page would repeat it out of view.
            fresh.querySelectorAll('.alert-success.alert-dismissible').forEach((el) => el.remove());
            region.innerHTML = fresh.innerHTML;
            if (window.initDatePickers) window.initDatePickers(region);
        });
        // Same signal auto-refresh sends after swapping <main>: collapsible cards, preview lists etc. re-init.
        window.dispatchEvent(new Event('auto-refresh:swapped'));
        // Bootstrap leaves the body locked if a modal was swapped out while closing.
        if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
        done();

        if (errors.length) {
            window.showNotice({ variant: 'danger', message: errors.join(' ') });
        } else if (success.length && typeof showToast === 'function') {
            showToast('<i class="fas fa-check-circle me-1"></i>' + escapeHtml(success[0]), 'success');
        }
    });
})();

// Live sync (staff pages - the layout adds <meta name="live-sync"> for admin,
// manager and receptionist). Every few seconds the page asks for a fingerprint
// of the data staff screens show (LiveSyncController); only when it changes -
// a new booking, payment, request, check-in... by anyone, on the website or the
// app - does the page re-fetch itself and swap just its marked parts:
//   - every [data-live-sync="name"] element (tab counts, table bodies,
//     pagination, the bell and sidebar badges), matched by name and order;
//     only its inner HTML is replaced, so listeners bound on it survive;
//   - the whole <main> on pages that opt into live sections (livePage) or use
//     the dashboard auto-refresh.
// Never mid-action: an update waits while a modal is open, someone is typing
// in a form, or a background save is running, then goes as soon as it's clear.
(function () {
    const meta = document.querySelector('meta[name="live-sync"]');
    if (!meta || !window.fetch || !window.DOMParser) return;
    const POLL_MS = 8000;
    let stamp = null;
    let pending = false;
    let busy = false;

    function blocked() {
        if (document.querySelector('.modal.show, .live-updating')) return true;
        const el = document.activeElement;
        return !!(el && el.matches && el.matches('input, textarea, select') && el.closest('form'));
    }

    async function refreshPage() {
        busy = true;
        try {
            const resp = await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'live-sync' } });
            // A redirect (e.g. the session ended) is left for the next real navigation.
            if (!resp.ok || new URL(resp.url, window.location.href).pathname !== window.location.pathname) return false;
            const doc = new DOMParser().parseFromString(await resp.text(), 'text/html');
            const swapped = [];

            const main = document.getElementById('pageContent');
            const wholeMain = main && (main.hasAttribute('data-live-region') || window.liveSyncMain === true);
            if (wholeMain) {
                const fresh = doc.getElementById('pageContent');
                if (fresh && fresh.innerHTML !== main.innerHTML) {
                    main.innerHTML = fresh.innerHTML;
                    swapped.push(main);
                }
            }

            const names = new Set(Array.from(document.querySelectorAll('[data-live-sync]')).map((el) => el.dataset.liveSync));
            names.forEach(function (name) {
                const selector = '[data-live-sync="' + name + '"]';
                const current = Array.from(document.querySelectorAll(selector)).filter((el) => !(wholeMain && main.contains(el)));
                const fresh = doc.querySelectorAll(selector);
                current.forEach(function (el, i) {
                    if (fresh[i] && fresh[i].innerHTML !== el.innerHTML) {
                        el.innerHTML = fresh[i].innerHTML;
                        swapped.push(el);
                    }
                });
            });

            if (swapped.length) {
                if (window.initDatePickers) swapped.forEach((r) => window.initDatePickers(r));
                window.dispatchEvent(new Event('auto-refresh:swapped'));
            }
            return true;
        } catch (e) {
            return false;
        } finally {
            busy = false;
        }
    }

    async function tick() {
        if (document.hidden || busy) return;
        try {
            const resp = await fetch(meta.content, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (resp.ok) {
                const next = (await resp.json()).stamp;
                if (stamp !== null && next !== stamp) pending = true;
                stamp = next;
            }
        } catch (e) {
            // offline or a transient error - try again next tick
        }
        await flush();
    }

    // Apply a waiting update when nothing is in the way.
    async function flush() {
        if (pending && !busy && !document.hidden && !blocked() && await refreshPage()) pending = false;
    }

    setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
    // A modal closing or a field losing focus may free a waiting update.
    document.addEventListener('hidden.bs.modal', function () { if (pending) setTimeout(flush, 300); });
    document.addEventListener('focusout', function () { if (pending) setTimeout(flush, 300); });
    tick();
})();

// Forms marked data-submit-once disable their submit buttons on submit so a
// double click can't send the action twice.
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches || !form.matches('form[data-submit-once]') || event.defaultPrevented) return;
    form.querySelectorAll('button[type="submit"]').forEach(function (btn) { btn.disabled = true; });
});


// Phone number boxes - checked in the box itself while typing, and the form
// can't be submitted until the number is complete. Mark an input with:
//   data-phone="ph"     Philippine mobile: digits only, exactly 11, starts with 09
//   data-phone="gcash"  GCash number after the +63 prefix: exactly 10 digits, starts with 9
//   data-phone="ph" data-phone-country="#countrySelect"
//                       follows a country picker: Philippines -> as "ph";
//                       any other country -> international, "+" and 7-15 digits
// Matches the server rules (PhoneValidationService / gcash regex). Works for
// inputs loaded later too (e.g. the Check In panel).
(function () {
    const RULES = {
        ph: { max: 11, re: /^09\d{9}$/, plus: false, hint: 'Enter all 11 digits, starting with 09 (e.g. 09171234567).' },
        gcash: { max: 10, re: /^9\d{9}$/, plus: false, hint: 'Enter all 10 digits after +63, starting with 9.' },
        intl: { max: 16, re: /^\+?\d{7,15}$/, plus: true, hint: 'Enter the number with country code (7-15 digits).' },
    };

    function ruleFor(input) {
        const sel = input.dataset.phoneCountry;
        if (sel) {
            const country = document.querySelector(sel);
            if (country && country.value && country.value.toLowerCase() !== 'philippines') return RULES.intl;
        }
        return RULES[input.dataset.phone] || RULES.ph;
    }

    function feedback(input) {
        let fb = input.parentElement.querySelector('.phone-feedback');
        if (!fb) {
            fb = document.createElement('div');
            fb.className = 'phone-feedback small mt-1';
            input.insertAdjacentElement('afterend', fb);
        }
        return fb;
    }

    function check(input, show) {
        const rule = ruleFor(input);
        const value = input.value;
        const digits = value.replace(/\D/g, '');
        let message = '';
        if (value === '') {
            message = input.required ? 'This field is required.' : '';
        } else if (!rule.re.test(value)) {
            message = rule.hint + (rule === RULES.intl ? '' : ' (' + digits.length + '/' + rule.max + ' digits)');
        }
        input.setCustomValidity(message);
        if (!show) return;
        const fb = feedback(input);
        const ok = message === '' && value !== '';
        input.classList.toggle('is-invalid', message !== '');
        input.classList.toggle('is-valid', ok);
        fb.className = 'phone-feedback small mt-1 ' + (ok ? 'text-success' : 'text-danger');
        fb.textContent = ok ? 'Looks good.' : message;
    }

    function clean(input) {
        const rule = ruleFor(input);
        let v = input.value;
        // A saved +639XXXXXXXXX becomes 09XXXXXXXXX in a Philippine box.
        if (rule === RULES.ph && /^\+?639\d{9}$/.test(v.replace(/\s/g, ''))) v = '0' + v.replace(/\D/g, '').slice(2);
        v = rule.plus ? v.replace(/(?!^\+)[^\d]/g, '') : v.replace(/\D/g, '');
        if (v.length > rule.max) v = v.slice(0, rule.max);
        if (v !== input.value) input.value = v;
    }

    function init(input) {
        if (input.dataset.phoneReady) return;
        input.dataset.phoneReady = '1';
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'tel');
        input.maxLength = ruleFor(input).max;
        clean(input);
        check(input, input.value !== '');
        input.addEventListener('input', function () { clean(input); check(input, true); });
        input.addEventListener('blur', function () { check(input, true); });
        input.addEventListener('invalid', function () { check(input, true); });
        const sel = input.dataset.phoneCountry && document.querySelector(input.dataset.phoneCountry);
        if (sel) sel.addEventListener('change', function () { input.maxLength = ruleFor(input).max; clean(input); check(input, input.value !== ''); });
    }

    function scan(root) {
        (root.querySelectorAll ? root.querySelectorAll('input[data-phone]') : []).forEach(init);
        if (root.matches && root.matches('input[data-phone]')) init(root);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        new MutationObserver(function (records) {
            records.forEach(function (r) { r.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); }); });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();

// Desk payment fields (resources/views/receptionist/partials/desk-payment-fields.blade.php) - the Check In panel,
// Walk-in Check-in and Create Booking. Shows the fields for the chosen method (only those are required) and a hint:
// change to hand back, or how much is still owed. window.syncDeskPayment(box) re-applies it after the page changes
// the amount due (data-due) or enables the fields.
(function () {
    const peso = function (n) {
        return '\u20B1' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    function sync(box) {
        if (!box) return;
        const checked = box.querySelector('input[name="payment_method"]:checked');
        const method = checked ? checked.value : 'none';
        const due = box.dataset.due === '' || box.dataset.due === undefined ? null : parseFloat(box.dataset.due);
        const full = box.dataset.mode === 'full';

        const dueRow = box.querySelector('[data-due-row]');
        if (dueRow) dueRow.classList.toggle('d-none', due === null);
        const dueLabel = box.querySelector('[data-due-label]');
        if (dueLabel && due !== null) dueLabel.textContent = peso(due);

        box.querySelectorAll('[data-pay]').forEach(function (section) {
            const on = section.dataset.pay === method;
            section.classList.toggle('d-none', !on);
            section.querySelectorAll('input').forEach(function (input) {
                input.required = on && !input.disabled;
                if (input.type === 'number') input.setCustomValidity('');
            });
        });

        const section = box.querySelector('[data-pay="' + method + '"]');
        if (!section) return;
        const hint = section.querySelector('[data-pay-hint]');
        const amountInput = section.querySelector('input[type="number"]');
        if (!hint || !amountInput) return;
        const got = parseFloat(amountInput.value);
        let text = '', cls = 'text-muted';
        if (due !== null && !isNaN(got)) {
            const diff = Math.round((got - due) * 100) / 100;
            if (diff < 0) {
                text = full ? 'Not enough - the full ' + peso(due) + ' is required.' : 'Partial payment - ' + peso(-diff) + ' still owed.';
                cls = full ? 'text-danger' : 'text-muted';
            } else if (diff === 0) {
                text = 'Paid in full.';
                cls = 'text-success';
            } else if (method === 'cash') {
                text = 'Paid in full - change to hand back: ' + peso(diff) + '.';
                cls = 'text-primary';
            } else {
                text = 'More than the ' + peso(due) + ' owed - enter the exact GCash amount.';
                cls = 'text-danger';
            }
        }
        hint.textContent = text;
        hint.className = 'd-block mt-1 small ' + cls;
        // Check-in and walk-in take the full amount only: a short amount (or a GCash amount that isn't exact)
        // blocks the submit here too, not just on the server.
        amountInput.setCustomValidity(full && cls === 'text-danger' ? text : '');
    }

    window.syncDeskPayment = sync;
    ['change', 'input'].forEach(function (type) {
        document.addEventListener(type, function (e) {
            const box = e.target.closest && e.target.closest('[data-desk-payment]');
            if (box) sync(box);
        });
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-desk-payment]').forEach(sync);
    });
})();
