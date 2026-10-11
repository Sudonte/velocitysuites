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
