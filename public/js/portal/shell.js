/*
 * The guest shell's header: the bell with the unread count, its dropdown of the latest notifications (the app's own
 * /api/notifications), "Mark all as read", and the notification detail that opens first - the same as the app.
 */
(function () {
    'use strict';
    var VS = window.VS;
    var CATS = { booking: 'Bookings', payment: 'Payments', reservation: 'Reservations', promotion: 'Promotions', announcement: 'Announcements', amenity: 'Amenities', receipt: 'Receipts', system: 'Account' };
    VS.noteCats = CATS;
    VS.noteCat = function (n) { var c = String(n.category || 'booking').toLowerCase(); return CATS[c] ? c : 'booking'; };

    /**
     * The guest's notifications as the website shows them: the app's own list (/api/notifications) plus the live announcements as
     * "System" notifications (/api/guest/system-notices, with their own read state). The one-time announcement rows the server stored when
     * an announcement first went live are left out here - the live announcement itself replaces them, so nothing shows twice, and an
     * archived or expired announcement disappears. Resolves { ok, list (newest first), unread, message }.
     */
    VS.loadNotes = function (perPage, opts) {
        return Promise.all([VS.api('/notifications', { query: { per_page: 200 }, quiet401: opts && opts.quiet401 }), VS.api('/guest/system-notices', { quiet401: opts && opts.quiet401 })]).then(function (rs) {
            if (!rs[0].ok) return { ok: false, message: rs[0].message, list: [], unread: 0 };
            var rows = rs[0].data.data || [], notices = rs[1].ok ? (rs[1].data.data || []) : null;
            if (notices) rows = rows.filter(function (n) { return String(n.category || '').toLowerCase() !== 'announcement'; });
            var list = rows.concat(notices || []).sort(function (a, b) { return new Date(b.created_at) - new Date(a.created_at); });
            return { ok: true, list: perPage ? list.slice(0, perPage) : list, all: list, unread: list.filter(function (n) { return !n.is_read; }).length };
        });
    };
    /** Read / unread for one notification: a System notification keeps its state in /guest/system-notices, the others in /notifications. */
    VS.noteMark = function (n, action) {
        return VS.api(n.announcement_id ? '/guest/system-notices/' + n.announcement_id + '/' + action : '/notifications/' + n.id + '/' + action, { method: 'PUT' });
    };
    VS.noteReadAll = function () {
        return Promise.all([VS.api('/notifications/read-all', { method: 'PUT' }), VS.api('/guest/system-notices/read-all', { method: 'PUT' })]).then(function (rs) { return rs[0]; });
    };
    VS.noteWhen = function (n) { return (n.updated ? 'Updated ' : '') + VS.dateTime(n.created_at); };

    function target(n) {
        if (n.receipt_number) return { href: '/guest/receipts/' + encodeURIComponent(n.receipt_number), label: 'View receipt' };
        if (n.reference_id && /booking|payment|reservation|amenity|receipt/.test(VS.noteCat(n))) return { href: '/guest/reservations/' + n.reference_id, label: 'View transaction' };
        return null;
    }

    function setBadge(count) {
        document.querySelectorAll('[data-notif-unread-count]').forEach(function (b) {
            b.textContent = count > 9 ? '9+' : count;
            b.classList.toggle('d-none', count <= 0);
        });
        var bell = document.getElementById('vsBell');
        if (bell) bell.setAttribute('aria-label', 'Notifications (' + count + ' unread)');
    }
    VS.setUnread = setBadge;

    /** The detail first (message, time, Mark as unread, and the receipt or transaction it is about); opening marks it read. */
    VS.openNote = function (n, changed) {
        if (!n) return;
        var t = target(n);
        var html = '<p>' + VS.esc(n.message).replace(/\n/g, '<br>') + '</p><div class="small text-muted">' + VS.esc(VS.noteWhen(n)) + '</div>';
        if (!n.is_read) {
            n.is_read = true;
            VS.noteMark(n, 'read').then(function () { refreshCount(); if (changed) changed(); });
            if (changed) changed();
        }
        var wrap = document.createElement('div'); wrap.className = 'modal fade'; wrap.tabIndex = -1;
        wrap.setAttribute('aria-labelledby', 'vsNoteTitle');
        wrap.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content vs-modal"><div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="vsNoteTitle">' + VS.esc(n.title) + '</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' + html + '</div>' +
            '<div class="modal-footer border-0 pt-0"><button class="btn btn-light" data-toggle>Mark as unread</button>' + (t ? '<a class="btn btn-velocity" href="' + t.href + '">' + t.label + '</a>' : '') + '</div></div></div>';
        document.body.appendChild(wrap);
        var m = new bootstrap.Modal(wrap); m.show();
        wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
        var tg = wrap.querySelector('[data-toggle]');
        tg.onclick = function () {
            var toUnread = n.is_read;
            VS.noteMark(n, toUnread ? 'unread' : 'read').then(function (r) {
                if (r.ok) { n.is_read = !toUnread; tg.textContent = n.is_read ? 'Mark as unread' : 'Mark as read'; refreshCount(); if (changed) changed(); }
            });
        };
    };

    var list = null;
    function refreshCount() {
        return VS.loadNotes(6, { quiet401: true }).then(function (r) {
            if (!r.ok) return;
            list = r.list;
            setBadge(r.unread);
            paint();
        });
    }
    VS.refreshBell = refreshCount;

    function paint() {
        var host = document.getElementById('vsBellList');
        if (!host || list === null) return;
        host.innerHTML = list.length ? list.map(function (n) {
            return '<button type="button" class="vs-bell-item' + (n.is_read ? '' : ' unread') + '" data-id="' + n.id + '">' +
                '<span class="vs-bell-dot" aria-hidden="true"></span><span class="vs-bell-text"><strong>' + VS.esc(n.title) + '</strong>' + (n.is_read ? '' : '<span class="visually-hidden"> (unread)</span>') +
                '<span class="vs-bell-msg">' + VS.esc(String(n.message || '').replace(/\s+/g, ' ')) + '</span><span class="vs-bell-time">' + VS.esc(VS.noteWhen(n)) + '</span></span></button>';
        }).join('') : '<div class="vs-bell-empty">No notifications yet</div>';
        host.querySelectorAll('[data-id]').forEach(function (b) {
            b.onclick = function () {
                var n = list.find(function (x) { return x.id == b.dataset.id; });
                var dd = bootstrap.Dropdown.getInstance(document.getElementById('vsBell')); if (dd) dd.hide();
                VS.openNote(n, paint);
            };
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var bell = document.getElementById('vsBell');
        if (!bell) return;
        bell.addEventListener('show.bs.dropdown', refreshCount);
        var all = document.getElementById('vsBellReadAll');
        all.addEventListener('click', function () {
            VS.busy(all, function () { return VS.noteReadAll(); }).then(function (r) {
                if (r && r.ok) { (list || []).forEach(function (n) { n.is_read = true; }); setBadge(0); paint(); }
            });
        });
        refreshCount();
        (function beat() { setTimeout(function () { if (!document.hidden) refreshCount(); beat(); }, 30000); })();
    });
})();
