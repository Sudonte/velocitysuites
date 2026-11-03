/*
 * Notifications, Transaction History and Profile for guests - the app's own endpoints:
 * /api/notifications, /api/guest/reservations + /api/guest/bookings (include_hidden), /api/guest/profile and friends.
 */
(function () {
    'use strict';
    var VS = window.VS, S = window.VSStay;
    var Acc = window.VSAccount = {};

    // ===================================================== notifications
    // The app's filter list: All, Unread, Booking, Reservation, Payment, Check-in, Promotions & discounts, System (announcements count as System).
    var FILTERS = [['All', 'All notifications'], ['Unread', 'Unread'], ['booking', 'Bookings'], ['reservation', 'Reservations'], ['payment', 'Payments'], ['checkin', 'Check-in'], ['promotion', 'Promotions & discounts'], ['system', 'System']];
    function typeOf(n) {
        switch (String(n.category || '').toLowerCase()) {
            case 'booking': return 'booking'; case 'reservation': return 'reservation'; case 'payment': return 'payment';
            case 'check_in': case 'check_out': case 'checkin_reminder': return 'checkin';
            case 'promotion': case 'discount': return 'promotion';
            default: return 'system';
        }
    }

    Acc.notifications = function (root) {
        var host = root.querySelector('#vsNotes'), form = root.querySelector('#vsNoteFilters'), all = [];
        function labels() {
            var unread = all.filter(function (n) { return !n.is_read; }), by = {};
            unread.forEach(function (n) { by[typeOf(n)] = (by[typeOf(n)] || 0) + 1; });
            var sel = form.elements.filter, cur = sel.value || 'All';
            sel.innerHTML = FILTERS.map(function (f) {
                var c = (f[0] === 'All' || f[0] === 'Unread') ? unread.length : (by[f[0]] || 0);
                return '<option value="' + f[0] + '">' + VS.esc(f[1]) + (c > 0 ? ' (' + c + ')' : '') + '</option>';
            }).join('');
            sel.value = cur;
        }
        function paint() {
            var q = form.elements.q.value.trim().toLowerCase().split(/\s+/).filter(Boolean), f = form.elements.filter.value || 'All';
            var list = all.filter(function (n) {
                if (f === 'Unread' ? n.is_read : (f !== 'All' && typeOf(n) !== f)) return false;
                var hay = (n.title + ' ' + n.message + ' ' + (n.category || '')).toLowerCase();
                return q.every(function (w) { return hay.indexOf(w) >= 0; });
            });
            var label = FILTERS.filter(function (x) { return x[0] === f; })[0][1];
            host.innerHTML = list.length ? list.map(function (n) {
                return '<button type="button" class="vs-card vs-note w-100 text-start ' + (n.is_read ? '' : 'unread') + '" data-id="' + n.id + '"><div class="d-flex justify-content-between gap-2"><span class="fw-bold">' + VS.esc(n.title) + '</span><span class="vs-chip gray">' + VS.esc((FILTERS.filter(function (x) { return x[0] === typeOf(n); })[0] || ['', 'System'])[1].replace('Bookings', 'Booking').replace('Reservations', 'Reservation').replace('Payments', 'Payment')) + '</span></div><div class="text-muted small">' + VS.esc(n.message).slice(0, 140) + '</div><div class="small text-muted mt-1">' + VS.esc(VS.dateTime(n.created_at)) + (n.is_read ? '' : ' · <strong class="text-danger">New</strong>') + '</div></button>';
            }).join('') : (all.length === 0
                ? VS.empty('fa-bell', 'No notifications yet', 'You have no notifications at this time. New updates and alerts will appear here.')
                : f === 'Unread' && !q.length ? VS.empty('fa-bell', 'No unread notifications', 'You\u2019re all caught up.')
                : q.length ? VS.empty('fa-magnifying-glass', 'Nothing matches', 'Try another word.')
                : VS.empty('fa-bell', 'No ' + label + ' notifications', 'Nothing in this category yet.'));
            host.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { open(all.find(function (x) { return x.id == b.dataset.id; })); }; });
            var u = all.filter(function (n) { return !n.is_read; }).length;
            root.querySelector('#vsNoteCount').textContent = u ? u + ' unread' : 'All caught up';
            labels();
        }
        function open(n) { VS.openNote(n, paint); }
        function load() {
            host.innerHTML = VS.skeleton(4);
            VS.api('/notifications', { query: { per_page: 200 } }).then(function (r) {
                if (!r.ok) { host.innerHTML = VS.errorState(r.message, 'vsNoteRetry'); document.getElementById('vsNoteRetry').onclick = load; return; }
                all = r.data.data || []; labels(); paint();
            });
        }
        form.addEventListener('input', VS.debounce(paint, 200)); form.addEventListener('change', paint); form.addEventListener('submit', function (e) { e.preventDefault(); });
        root.querySelector('#vsReadAll').onclick = function (e) {
            var btn = e.currentTarget;
            VS.confirm({ title: 'Mark all as read?', message: 'Mark all notifications as read? This can\u2019t be undone.', confirmText: 'Mark all as read' }).then(function (yes) {
                if (!yes) return;
                VS.busy(btn, function () { return VS.api('/notifications/read-all', { method: 'PUT' }); }).then(function (r) { if (r && r.ok) { all.forEach(function (n) { n.is_read = true; }); paint(); if (VS.setUnread) VS.setUnread(0); VS.toast('All notifications marked as read', 'success'); } });
            });
        };
        load();
    };

    // ===================================================== transaction history
    Acc.history = function (root) {
        var host = root.querySelector('#vsHist'), form = root.querySelector('#vsHistFilters'), all = [];
        var TYPES = [['', 'All transactions'], ['booking', 'Bookings'], ['reservation', 'Reservations'], ['payments', 'Payments'], ['pending', 'Pending'], ['paid', 'Paid'], ['partial', 'Partially paid'], ['cancelled', 'Cancelled'], ['rejected', 'Rejected'], ['checkedin', 'Checked in'], ['checkedout', 'Checked out']];
        var BSTAT = [['', 'All'], ['Pending', 'Pending'], ['Confirmed', 'Confirmed'], ['Checked-In', 'Checked in'], ['Checked-Out', 'Checked out'], ['Cancelled', 'Cancelled'], ['Rejected', 'Rejected']];

        // one row per transaction, with the same payment state the app badges it with (verified money decides Paid / Partially paid)
        function rowOf(x) {
            var it = x.it, st = S.status(it, x.kind), m = S.money(it), kind = S.appKind(x);
            var life = { pending: 'Pending', confirmed: 'Confirmed', checkedin: 'Checked-In', completed: 'Checked-Out', cancelled: 'Cancelled', rejected: 'Rejected', noshow: 'Rejected' }[st.key] || 'Pending';
            var tx = it.payment_transactions || (it.booking && it.booking.payment_transactions) || [];
            var last = tx.length ? tx[tx.length - 1] : null, lastRejected = !!last && (last.verification_status === 'rejected' || /rejected|failed/i.test(last.payment_status || '')) && !(m.waiting > 0.009);
            var pay;
            if (life === 'Cancelled') pay = 'cancelled'; else if (life === 'Rejected') pay = 'rejected';
            else if (m.paid > 0.009) pay = m.paid + 0.009 >= m.total ? 'paid' : 'partial';
            else pay = lastRejected ? 'rejected' : 'pending';
            var types = {}; (it.stays || []).forEach(function (s) { (s.rooms || []).forEach(function (r) { types[String(r.room_type).toLowerCase()] = 1; }); });
            var first = (it.stays && it.stays[0]) || { check_in: String(it.check_in).slice(0, 10), check_out: String(it.check_out).slice(0, 10) };
            var last2 = (it.stays && it.stays.length ? it.stays[it.stays.length - 1] : first);
            return { x: x, kind: kind, st: st, m: m, life: life, pay: pay, closed: life === 'Cancelled' || life === 'Rejected', hasPay: m.paid > 0.009 || m.waiting > 0.009 || tx.length > 0,
                types: types, inD: first.check_in, outD: last2.check_out || first.check_out,
                hay: (S.idLabel(it, x.kind) + ' ' + S.firstRoomTypes(it) + ' ' + st.label + ' ' + S.dates(it) + ' ' + (it.guest_first_name || '') + ' ' + (it.guest_last_name || '')).toLowerCase() };
        }
        function matches(r, f) {
            switch (f.type) {
                case 'booking': if (!(r.kind === 'booking' && !r.closed)) return false; break;
                case 'reservation': if (!(r.kind === 'reservation' && !r.closed)) return false; break;
                case 'payments': if (!r.hasPay) return false; break;
                case 'pending': case 'paid': case 'partial': case 'cancelled': case 'rejected': if (r.pay !== f.type) return false; break;
                case 'checkedin': if (r.life !== 'Checked-In') return false; break;
                case 'checkedout': if (r.life !== 'Checked-Out') return false; break;
            }
            if (f.bstat && r.life !== f.bstat) return false;
            if (f.room && !r.types[f.room.toLowerCase()]) return false;
            if (f.from && (!r.inD || r.inD < f.from)) return false;
            if (f.to && (r.outD || r.inD) > f.to) return false;
            return f.q.every(function (w) { return r.hay.indexOf(w) >= 0; });
        }
        function filters() {
            var e = form.elements;
            return { type: e.type.value, bstat: e.bstat.value, room: e.room.value, from: e.from.value, to: e.to.value, q: e.q.value.trim().toLowerCase().split(/\s+/).filter(Boolean) };
        }
        function paint() {
            var f = filters(), fi = form.elements, bad = f.from && f.to && f.to < f.from;
            VS.clearErrors(form);
            if (bad) { VS.fieldError(fi.to, 'Check-out date must be on or after the check-in date.'); }
            var list = bad ? [] : all.filter(function (r) { return matches(r, f); }).sort(function (a, b) { return new Date(b.x.it.created_at) - new Date(a.x.it.created_at); });
            var active = [f.type, f.bstat, f.room, f.from, f.to].filter(Boolean).length;
            root.querySelector('#vsHistCount').textContent = VS.plural(list.length, 'result');
            root.querySelector('#vsHistToggle').textContent = (root.querySelector('#vsHistPanel').classList.contains('d-none') ? 'Show filters' : 'Hide filters') + (active ? ' (' + active + ' active)' : '');
            host.innerHTML = list.length ? list.map(function (r) {
                var x = r.x, s = r.st, m = r.m, payChip = { paid: ['ok', 'Paid'], partial: ['wait', 'Partially paid'], pending: ['wait', 'Pending'], cancelled: ['bad', 'Cancelled'], rejected: ['bad', 'Rejected'] }[r.pay];
                return '<a class="vs-card vs-stay-card d-block text-decoration-none" href="/guest/' + (x.kind === 'reservation' ? 'reservations' : 'bookings') + '/' + x.it.id + '"><div class="d-flex justify-content-between gap-2"><div><div class="fw-bold">' + VS.esc(S.idLabel(x.it, x.kind)) + (x.it.hidden_by_guest ? ' <span class="small text-muted fw-normal">(removed from Booking & Reservation)</span>' : '') + '</div><div class="small text-muted">Created ' + VS.esc(VS.dateTime(x.it.created_at)) + '</div><div class="small">' + VS.esc(S.dates(x.it)) + '</div></div><div class="text-end"><span class="vs-chip ' + s.cls + '">' + s.label + '</span><div class="mt-1"><span class="vs-chip ' + payChip[0] + '">' + payChip[1] + '</span></div></div></div>' +
                    '<div class="vs-row mt-1"><span>Total</span><span>' + VS.money(m.total) + '</span></div><div class="vs-row"><span>Paid</span><span>' + VS.money(m.paid) + '</span></div></a>';
            }).join('') : (!all.length ? VS.empty('fa-clock-rotate-left', 'No transactions yet', 'Everything you book or reserve is recorded here.', '<a class="btn btn-velocity" href="/rooms">Browse rooms</a>')
                : VS.empty('fa-filter', 'No transactions match', 'Try another transaction number, room type, or date.', '<button class="btn btn-light btn-sm" data-clear2>Clear filters</button>'));
            var c2 = host.querySelector('[data-clear2]'); if (c2) c2.onclick = clear;
        }
        function clear() { form.reset(); paint(); }
        function load() {
            host.innerHTML = VS.skeleton(4);
            Promise.all([VS.api('/guest/reservations', { query: { per_page: 200, include_hidden: 1 } }), VS.api('/guest/bookings', { query: { per_page: 200, include_hidden: 1 } })]).then(function (rs) {
                var items = [];
                if (rs[0].ok) (rs[0].data.data || []).forEach(function (r) { items.push({ kind: 'reservation', it: r }); });
                if (rs[1].ok) (rs[1].data.data || []).forEach(function (b) { items.push({ kind: 'booking', it: b }); });
                if (!rs[0].ok && !rs[1].ok) { host.innerHTML = VS.errorState(rs[0].message, 'vsHistRetry'); document.getElementById('vsHistRetry').onclick = load; return; }
                if (!rs[0].ok || !rs[1].ok) VS.toast('Part of your history could not be loaded. Try again in a moment.', 'warning');
                all = items.map(rowOf);
                var names = {}; all.forEach(function (r) { (r.x.it.stays || []).forEach(function (s) { (s.rooms || []).forEach(function (rm) { names[rm.room_type] = 1; }); }); });
                form.elements.room.innerHTML = '<option value="">All room types</option>' + Object.keys(names).sort().map(function (n) { return '<option>' + VS.esc(n) + '</option>'; }).join('');
                paint();
            });
        }
        form.elements.type.innerHTML = TYPES.map(function (t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('');
        form.elements.bstat.innerHTML = BSTAT.map(function (t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('');
        form.addEventListener('input', VS.debounce(paint, 150)); form.addEventListener('change', paint); form.addEventListener('submit', function (e) { e.preventDefault(); });
        form.querySelector('[data-clear]').onclick = clear;
        root.querySelector('#vsHistToggle').onclick = function () { root.querySelector('#vsHistPanel').classList.toggle('d-none'); paint(); };
        load();
    };

    // ===================================================== profile
    function pictureUrl(p) { return p ? (/^https?:/.test(p) ? p : '/storage/' + String(p).replace(/^\/?storage\//, '')) : null; }
    function initials(u) { return ((u.first_name || '?')[0] + ((u.last_name || '')[0] || '')).toUpperCase(); }
    function pwOk(v) { return v.length >= 8 && /[A-Za-z]/.test(v) && /\d/.test(v); }

    Acc.profile = function (root) {
        var host = root.querySelector('#vsProfile');
        function load() {
            host.innerHTML = VS.skeleton(6);
            VS.api('/guest/profile').then(function (r) {
                if (!r.ok) { host.innerHTML = VS.errorState(r.message, 'vsProfRetry'); document.getElementById('vsProfRetry').onclick = load; return; }
                render(r.data.user || {}, r.data.guest || {}, r.data.profile_update || {});
            });
        }
        function render(u, g, pu) {
            var pic = pictureUrl(g.profile_picture || u.profile_picture), name = [u.first_name, u.middle_name, u.last_name].filter(Boolean).join(' ');
            var locked = pu.can_update === false, comp = S.completeness(u, g);
            var lockText = locked
                ? 'Profile information was last updated on ' + VS.date(String(pu.last_updated_at || '').slice(0, 10)) + '. You can edit your profile again on ' + VS.date(String(pu.next_update_at || '').slice(0, 10)) + ' (' + VS.plural(pu.days_remaining || 0, 'day') + ' left).'
                : 'Personal, contact and address information are saved together and can be updated once every 30 days.';
            host.innerHTML =
                '<section class="vs-card p-0 overflow-hidden"><div class="vs-cover" style="background-image:linear-gradient(rgba(166,43,52,.35),rgba(166,43,52,.35)),url(/images/hero-building-640.jpg)"></div>' +
                '<div class="px-3 pb-3"><div class="vs-avatar-wrap">' + (pic ? '<button class="vs-avatar" id="pView" aria-label="View profile photo"><img src="' + VS.esc(pic) + '" alt="Your profile photo"></button>' : '<div class="vs-avatar vs-avatar-init" aria-hidden="true">' + VS.esc(initials(u)) + '</div>') +
                '<button class="vs-cam" id="pCam" aria-label="Change profile photo" aria-haspopup="menu"><i class="fas fa-camera"></i></button></div>' +
                '<h1 class="h4 fw-bold mt-2 mb-0">' + VS.esc(name) + '</h1><div class="text-muted">' + VS.esc(u.email) + '</div>' +
                '<div class="small mt-1 ' + (comp.line ? 'text-danger' : 'text-success') + '">' + VS.esc(comp.line || 'Profile complete') + ' · ' + comp.pct + '%</div>' +
                '<div id="pMenu" class="d-none vs-menu"><button data-a="camera"><i class="fas fa-camera me-2"></i>Take a photo</button><button data-a="gallery"><i class="fas fa-image me-2"></i>Choose from gallery</button>' + (pic ? '<button data-a="remove" class="text-danger"><i class="fas fa-trash me-2"></i>Remove photo</button>' : '') + '<button data-a="cancel">Cancel</button></div>' +
                '<input type="file" id="pFileCam" accept="image/*" capture="user" class="d-none"><input type="file" id="pFile" accept="image/*" class="d-none"></div></section>' +
                '<div class="vs-editbar" id="pBar"></div>' +
                '<div id="pAlert" aria-live="polite"></div><div id="pSections"></div><div id="pSaveBar"></div>' +
                '<section class="vs-card" id="pwCard"><div class="vs-sec-head"><div><h2>Change password</h2><p class="text-muted small mb-0">Keep your account secure with a strong password</p></div><button class="btn btn-outline-velocity btn-sm" id="pwOpen">Change password</button></div><div id="pPw"></div></section>';
            wirePhoto(u, g, !!pic, pic);
            sections(u, g, pu, lockText);
            wirePassword(u);
        }

        function wirePhoto(u, g, hasPic, pic) {
            var menu = host.querySelector('#pMenu'), cam = host.querySelector('#pFileCam'), file = host.querySelector('#pFile');
            host.querySelector('#pCam').onclick = function () { menu.classList.toggle('d-none'); };
            var view = host.querySelector('#pView');
            if (view) view.onclick = function () {
                var o = document.createElement('div'); o.className = 'vs-viewer'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-label', 'Profile photo'); o.innerHTML = '<button aria-label="Close" class="vs-viewer-x">×</button><img src="' + VS.esc(pic) + '" alt="Your profile photo">';
                var scale = 1, img = o.querySelector('img');
                o.addEventListener('wheel', function (e) { e.preventDefault(); scale = Math.min(5, Math.max(1, scale + (e.deltaY < 0 ? .2 : -.2))); img.style.transform = 'scale(' + scale + ')'; }, { passive: false });
                var d0 = null; o.addEventListener('touchmove', function (e) { if (e.touches.length === 2) { e.preventDefault(); var d = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY); if (d0) { scale = Math.min(5, Math.max(1, scale * d / d0)); img.style.transform = 'scale(' + scale + ')'; } d0 = d; } }, { passive: false }); o.addEventListener('touchend', function () { d0 = null; });
                function close() { o.remove(); document.removeEventListener('keydown', esc); } function esc(e) { if (e.key === 'Escape') close(); }
                o.addEventListener('click', function (e) { if (e.target === o || e.target.classList.contains('vs-viewer-x')) close(); }); document.addEventListener('keydown', esc); document.body.appendChild(o);
            };
            menu.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = function () {
                menu.classList.add('d-none'); var a = b.dataset.a;
                if (a === 'camera') cam.click(); else if (a === 'gallery') file.click();
                else if (a === 'remove') VS.confirm({ title: 'Remove your profile photo?', message: 'Your initials will show instead.', confirmText: 'Remove', danger: true }).then(function (yes) { if (yes) VS.api('/guest/profile/picture', { method: 'DELETE' }).then(function (r) { if (r.ok) { VS.toast('Photo removed.', 'success'); load(); } else VS.toast(r.message, 'danger'); }); });
            }; });
            function chosen(e) {
                var f = e.target.files[0]; e.target.value = ''; if (!f) return;
                VS.compressImage(f).then(function (c) {
                    var url = URL.createObjectURL(c);
                    VS.confirm({ title: 'Use this photo?', html: '<div class="text-center"><img src="' + url + '" alt="New profile photo preview" class="rounded-circle" style="width:160px;height:160px;object-fit:cover"></div>', confirmText: 'Save', cancelText: 'Cancel' }).then(function (yes) {
                        if (!yes) return; var fd = new FormData(); fd.append('profile_picture', c, c.name);
                        VS.api('/guest/profile/picture', { method: 'POST', body: fd }).then(function (r) { if (r.ok) { VS.toast('Profile photo updated.', 'success'); load(); } else VS.toast(r.message, 'danger'); });
                    });
                });
            }
            cam.onchange = chosen; file.onchange = chosen;
        }

        // ---------------------------------------------------------------- Personal, Contact and Address: one Edit profile, one Save changes
        function dobText(g, u) { var d = String(g.date_of_birth || u.date_of_birth || '').slice(0, 10); return d ? VS.date(d) + ' (age ' + ageOf(d) + ')' : ''; }
        function ageOf(dob) {
            var t = VS.today().split('-').map(Number), d = dob.split('-').map(Number), a = t[0] - d[0];
            if (t[1] < d[1] || (t[1] === d[1] && t[2] < d[2])) a--; return a;
        }
        function row(label, value) { return '<div class="vs-row"><span>' + label + '</span><span>' + (value ? VS.esc(value) : '<em class="text-muted fw-normal">Not set</em>') + '</span></div>'; }
        function genderText(v) { return v === 'male' ? 'Male' : v === 'female' ? 'Female' : ''; }
        function mobileOk(country, raw) {
            var m = String(raw || '').replace(/\s+/g, '');
            if (!m) return country === 'Philippines' ? 'Enter a valid Philippine mobile number using 09XXXXXXXXX or +639XXXXXXXXX.' : 'Enter a valid mobile number with country code';
            if (country === 'Philippines') return /^(09\d{9}|\+639\d{9})$/.test(m) ? '' : 'Enter a valid Philippine mobile number using 09XXXXXXXXX or +639XXXXXXXXX.';
            return /^\+?\d{7,15}$/.test(m) ? '' : 'Enter a valid mobile number for the selected country.';
        }
        var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

        /** A small dialog with fields; onOk(wrap) returns a promise of true (close) or false (stay open, the message is shown inside). */
        function dialog(o) {
            return new Promise(function (resolve) {
                var wrap = document.createElement('div'); wrap.className = 'modal fade'; wrap.tabIndex = -1; wrap.setAttribute('aria-labelledby', 'vsDlgT');
                wrap.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content vs-modal"><div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold" id="vsDlgT">' + VS.esc(o.title) + '</h2></div><div class="modal-body"><div data-dlg-alert></div>' + o.body +
                    '</div><div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light" data-act="no">Cancel</button><button type="button" class="btn btn-velocity" data-act="yes" data-busy-text="Please wait">' + VS.esc(o.ok) + '</button></div></div></div>';
                document.body.appendChild(wrap);
                var modal = new bootstrap.Modal(wrap, { backdrop: 'static' }), done = false;
                wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); resolve(done); });
                wrap.addEventListener('shown.bs.modal', function () { var f = wrap.querySelector('input'); if (f) f.focus(); });
                wrap.querySelector('[data-act=no]').onclick = function () { modal.hide(); };
                var yes = wrap.querySelector('[data-act=yes]');
                function go() { VS.clearErrors(wrap); VS.busy(yes, function () { return o.onOk(wrap); }).then(function (ok) { if (ok) { done = true; modal.hide(); } }); }
                yes.onclick = go;
                wrap.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); go(); } });
                modal.show();
            });
        }

        function sections(u, g, pu, lockText) {
            var locked = pu.can_update === false;
            var bar = host.querySelector('#pBar'), saveBar = host.querySelector('#pSaveBar'), box = host.querySelector('#pSections');
            var editing = false, cascade = null;
            var origAddress = { country: g.country || '', region: g.region || '', province: g.province || '', city: g.city || '', barangay: g.barangay || '', street: g.street || '', zip_code: g.zip_code || '' };

            function viewHtml() {
                return card('Personal information', 'Your basic information', row('First name', u.first_name) + row('Middle name', u.middle_name) + row('Last name', u.last_name) + row('Gender', genderText(g.gender || u.gender)) + row('Date of birth', dobText(g, u))) +
                    card('Contact information', 'Your email and mobile number', row('Email address', u.email) + row('Mobile number', g.mobile_number || u.mobile_number)) +
                    card('Address', 'Where you live', row('Country', g.country) + (g.country === 'Philippines' ? row('Region', g.region) + row('Province', g.province) + row('City / municipality', g.city) + row('Barangay', g.barangay) + row('Street / house no.', g.street) + row('ZIP code', g.zip_code) : '') + (!g.country ? row('Address', g.address) : ''));
            }
            function card(title, sub, body) { return '<section class="vs-card vs-sec"><div class="vs-sec-head"><div><h2>' + title + '</h2><p class="text-muted small mb-0">' + sub + '</p></div></div><div class="vs-sec-body">' + body + '</div></section>'; }
            function formHtml() {
                var gd = g.gender || u.gender || '', dob = String(g.date_of_birth || u.date_of_birth || '').slice(0, 10);
                return card('Personal information', 'Your basic information',
                    '<div class="row g-3"><div class="col-md-4"><label class="form-label small fw-semibold" for="pf">First name</label><input class="form-control" id="pf" maxlength="100" value="' + VS.esc(u.first_name || '') + '"></div>' +
                    '<div class="col-md-4"><label class="form-label small fw-semibold" for="pm">Middle name <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" id="pm" maxlength="100" value="' + VS.esc(u.middle_name || '') + '"></div>' +
                    '<div class="col-md-4"><label class="form-label small fw-semibold" for="pl">Last name</label><input class="form-control" id="pl" maxlength="100" value="' + VS.esc(u.last_name || '') + '"></div>' +
                    '<div class="col-md-6"><span class="form-label small fw-semibold d-block" id="pgl">Gender</span><div class="d-flex gap-3 pt-1" role="radiogroup" aria-labelledby="pgl" id="pg"><div class="form-check"><input class="form-check-input" type="radio" name="pgen" id="pgm" value="male"' + (gd === 'male' ? ' checked' : '') + '><label class="form-check-label" for="pgm">Male</label></div><div class="form-check"><input class="form-check-input" type="radio" name="pgen" id="pgf" value="female"' + (gd === 'female' ? ' checked' : '') + '><label class="form-check-label" for="pgf">Female</label></div></div></div>' +
                    '<div class="col-md-6"><label class="form-label small fw-semibold" for="pd">Date of birth</label><input class="form-control" type="date" id="pd" max="' + VS.today() + '" value="' + VS.esc(dob) + '"><div class="small text-muted mt-1" id="pAge">' + (dob ? 'Age: ' + ageOf(dob) : 'You must be 18 or older.') + '</div></div></div>') +
                    card('Contact information', 'Your email and mobile number',
                    '<div class="row g-3"><div class="col-md-6"><label class="form-label small fw-semibold" for="pe">Email address</label><input class="form-control" id="pe" type="email" autocomplete="email" value="' + VS.esc(u.email || '') + '" aria-describedby="peHelp"><div class="small text-muted mt-1" id="peHelp">Changing your email needs your password and a code we email to the new address.</div></div>' +
                    '<div class="col-md-6"><label class="form-label small fw-semibold" for="pp">Mobile number</label><input class="form-control" id="pp" type="tel" inputmode="tel" autocomplete="tel" value="' + VS.esc(g.mobile_number || u.mobile_number || '') + '"><div class="small text-muted mt-1">' + (g.country === 'Philippines' || !g.country ? '09XXXXXXXXX or +639XXXXXXXXX' : 'Include the country code, such as +65.') + '</div></div></div>') +
                    card('Address', 'Where you live', '<div id="pAddrHost"></div>');
            }
            function setBar() {
                if (editing) {
                    bar.className = 'vs-editbar'; bar.innerHTML = '<div class="small text-muted"><i class="fas fa-pen me-2"></i>You are editing your profile. Personal, contact and address information are saved together.</div>';
                    saveBar.className = 'vs-savebar';
                    saveBar.innerHTML = '<div class="vs-savebar-in"><span class="small text-muted d-none d-md-inline">You can edit your profile again after 30 days.</span><div class="d-flex gap-2 ms-md-auto"><button type="button" class="btn btn-light" id="pCancel">Cancel</button><button type="button" class="btn btn-velocity" id="pSave" data-busy-text="Saving">Save changes</button></div></div>';
                    saveBar.querySelector('#pCancel').onclick = stopEdit; saveBar.querySelector('#pSave').onclick = save;
                } else {
                    saveBar.className = ''; saveBar.innerHTML = '';
                    bar.className = 'vs-editbar' + (locked ? ' is-locked' : '');
                    bar.innerHTML = '<div class="d-flex flex-wrap align-items-center gap-2 justify-content-between"><div class="small ' + (locked ? '' : 'text-muted') + '" id="pLockText"><i class="fas ' + (locked ? 'fa-lock' : 'fa-circle-info') + ' me-2"></i>' + VS.esc(lockText) + '</div>' +
                        '<button type="button" class="btn btn-velocity" id="pEdit"' + (locked ? ' disabled aria-describedby="pLockText"' : '') + '><i class="fas fa-pen me-1"></i>Edit profile</button></div>';
                    var eb = bar.querySelector('#pEdit'); if (!locked) eb.onclick = startEdit;
                }
            }
            function startEdit() {
                editing = true; box.innerHTML = formHtml(); setBar();
                var tpl = document.getElementById('vsAddrTpl'), hostEl = box.querySelector('#pAddrHost');
                hostEl.appendChild(tpl.content.cloneNode(true));
                var root = hostEl.querySelector('[data-address-cascade]');
                cascade = root.__addressCascade = new window.AddressCascade(root, { initial: { country: g.country || 'Philippines', region: g.region, province: g.province, city: g.city, barangay: g.barangay, street: g.street, zip_code: g.zip_code, timezone: g.timezone }, required: true });
                var z = root.querySelector('#addrZip'); if (z && g.zip_code) z.value = g.zip_code;
                var st = root.querySelector('#addrStreet'); if (st && g.street) st.value = g.street;
                var d = box.querySelector('#pd'); d.addEventListener('input', function () { box.querySelector('#pAge').textContent = d.value ? 'Age: ' + ageOf(d.value) : 'You must be 18 or older.'; });
                box.addEventListener('input', clearOne);
                box.querySelector('#pf').focus();
            }
            function clearOne(e) { var t = e.target; if (t.classList && t.classList.contains('is-invalid')) { t.classList.remove('is-invalid'); var fb = t.parentElement.querySelector('.vs-fb'); if (fb) fb.remove(); } }
            function stopEdit() { editing = false; cascade = null; box.innerHTML = viewHtml(); setBar(); host.querySelector('#pAlert') && (host.querySelector('#pAlert').innerHTML = ''); }

            function addressValues() {
                var root = box.querySelector('[data-address-cascade]');
                var v = function (n) { var e = root.querySelector('[name="' + n + '"]:not(:disabled)'); return e ? e.value.trim() : ''; };
                var sel = function (id) { var e = document.getElementById(id); return e && e.selectedOptions[0] ? e.selectedOptions[0].textContent.trim() : ''; };
                var country = v('country'), addr = country;
                if (country.toLowerCase() === 'philippines') {
                    var parts = [v('street')]; if (sel('addrBarangay')) parts.push('Brgy. ' + sel('addrBarangay'));
                    parts.push(sel('addrCity') || v('city')); if (sel('addrProvince') || v('province')) parts.push(sel('addrProvince') || v('province'));
                    parts.push(sel('addrRegion') || v('region')); if (v('zip_code')) parts.push(v('zip_code')); parts.push(country);
                    addr = parts.filter(Boolean).join(', ');
                }
                return { fields: { country: country, region: v('region'), province: v('province'), city: v('city'), barangay: v('barangay'), street: v('street'), zip_code: v('zip_code'), timezone: v('timezone') }, address: addr };
            }

            /** Checks every field of the three sections; each message sits under its own field. */
            function validateAll() {
                box.querySelectorAll('[data-address-error]').forEach(function (e) { e.textContent = ''; });
                var ok = true, q = function (id) { return box.querySelector(id); };
                var first = q('#pf'), last = q('#pl'), d = q('#pd'), email = q('#pe'), mob = q('#pp');
                if (!first.value.trim()) { VS.fieldError(first, 'First name is required'); ok = false; }
                if (!last.value.trim()) { VS.fieldError(last, 'Last name is required'); ok = false; }
                if (!box.querySelector('input[name=pgen]:checked')) { VS.fieldError(q('#pg'), 'Choose your gender.'); ok = false; }
                if (!d.value) { VS.fieldError(d, 'Date of birth is required'); ok = false; } else if (ageOf(d.value) < 18) { VS.fieldError(d, 'You must be at least 18 years old to create an account.'); ok = false; }
                if (!email.value.trim()) { VS.fieldError(email, 'Email is required'); ok = false; } else if (!EMAIL_RE.test(email.value.trim())) { VS.fieldError(email, 'Enter a valid email address'); ok = false; }
                var country = (cascade && cascade.countryEl && cascade.countryEl.value) || g.country || 'Philippines', msg = mobileOk(country, mob.value);
                if (msg) { VS.fieldError(mob, msg); ok = false; }
                if (!cascade.validate()) ok = false;
                if (!ok) { var bad = box.querySelector('.is-invalid, .invalid-feedback.d-block:not(:empty)'); if (bad && bad.scrollIntoView) bad.scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' }); }
                return ok;
            }

            /** What changed, as { label, from, to } rows - and the payload that carries only those fields. */
            function diff() {
                var q = function (id) { return box.querySelector(id).value.trim(); }, rows = [], body = { first_name: q('#pf'), last_name: q('#pl') };
                function chg(label, from, to, key, val) { if (String(from || '') !== String(to || '')) { rows.push({ label: label, from: from, to: to }); if (key) body[key] = val; } }
                chg('First name', u.first_name, q('#pf')); chg('Last name', u.last_name, q('#pl'));
                chg('Middle name', u.middle_name, q('#pm'), 'middle_name', q('#pm') || null);
                var gen = box.querySelector('input[name=pgen]:checked').value; chg('Gender', genderText(g.gender || u.gender), genderText(gen), 'gender', gen);
                var dob = String(g.date_of_birth || u.date_of_birth || '').slice(0, 10); chg('Date of birth', dob ? VS.date(dob) : '', VS.date(q('#pd')), 'date_of_birth', q('#pd'));
                var mob = q('#pp').replace(/\s+/g, ''); chg('Mobile number', g.mobile_number || u.mobile_number, mob, 'mobile_number', mob);
                var email = q('#pe'); if (email.toLowerCase() !== String(u.email || '').toLowerCase()) rows.push({ label: 'Email address', from: u.email, to: email, email: true });
                var av = addressValues(), changedAddr = Object.keys(av.fields).some(function (k) { return String(av.fields[k] || '') !== String(origAddress[k] || ''); });
                if (changedAddr) {
                    rows.push({ label: 'Address', from: g.address || origAddress.country, to: av.address });
                    Object.keys(av.fields).forEach(function (k) { body[k] = av.fields[k] === '' && k !== 'country' ? null : av.fields[k]; }); body.address = av.address;
                }
                return { rows: rows, body: body, email: email.toLowerCase() !== String(u.email || '').toLowerCase() ? email : null };
            }

            function askEmailCode(newEmail) {
                // password first (the code is only sent when it is right), then the 6-digit code; either dialog cancelled = nothing is saved
                return dialog({ title: 'Confirm your password', ok: 'Send code', body: '<p class="small text-muted">To change your email to <strong>' + VS.esc(newEmail) + '</strong>, enter your password. We then send a 6-digit code to the new address.</p><label class="form-label small fw-semibold" for="pcPw">Password</label><input class="form-control" id="pcPw" type="password" autocomplete="current-password">',
                    onOk: function (w) {
                        var pw = w.querySelector('#pcPw'); if (!pw.value) { VS.fieldError(pw, 'Password is required'); return false; }
                        return VS.api('/guest/profile/email/request', { method: 'POST', body: { new_email: newEmail, current_password: pw.value } }).then(function (r) { if (!r.ok) { VS.alertBox(w.querySelector('[data-dlg-alert]'), r.message || 'Incorrect password.'); return false; } return true; });
                    } }).then(function (sent) {
                    if (!sent) return null;
                    var code = null;
                    return dialog({ title: 'Enter the code', ok: 'Verify and save', body: '<p class="small text-muted">Enter the 6-digit code sent to ' + VS.esc(newEmail) + '. Nothing is saved until the code is right.</p><input class="form-control" id="pcCode" inputmode="numeric" maxlength="6" autocomplete="one-time-code" aria-label="6-digit code">',
                        onOk: function (w) { var c = w.querySelector('#pcCode'); if (!/^\d{6}$/.test(c.value.trim())) { VS.fieldError(c, 'Enter the 6-digit code.'); return false; } code = c.value.trim(); return true; } }).then(function (ok) { return ok ? code : null; });
                });
            }

            function send(body, code) {
                var payload = Object.assign({}, body); if (code) payload.email_otp = code;
                return VS.api('/guest/profile/all', { method: 'PUT', body: payload });
            }
            var FIELD = { first_name: '#pf', last_name: '#pl', gender: '#pg', date_of_birth: '#pd', mobile_number: '#pp', middle_name: '#pm' };
            function showErrors(r) {
                var errs = r.errors || {}, shown = false;
                Object.keys(errs).forEach(function (key) { var el = FIELD[key] && box.querySelector(FIELD[key]); if (el) { VS.fieldError(el, [].concat(errs[key])[0]); shown = true; } });
                if (!shown) VS.alertBox(host.querySelector('#pAlert'), r.message || 'Couldn\u2019t update your profile. Please check your details and try again.');
                var bad = box.querySelector('.is-invalid'); if (bad) bad.scrollIntoView({ block: 'center' }); else window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function save() {
                var saveBtn = saveBar.querySelector('#pSave'); VS.clearErrors(box); VS.alertBox(host.querySelector('#pAlert'), '');
                if (!validateAll()) return;
                var d = diff();
                if (!d.rows.length) { VS.toast('No changes to save.', 'info'); return; }
                var list = '<ul class="vs-changes">' + d.rows.map(function (r) { return '<li><span class="vs-ch-l">' + VS.esc(r.label) + '</span><span class="vs-ch-v"><s>' + VS.esc(r.from || 'Not set') + '</s> <i class="fas fa-arrow-right small"></i> <strong>' + VS.esc(r.to || 'Not set') + '</strong></span></li>'; }).join('') + '</ul>';
                VS.confirm({ title: 'Save these changes?', html: list + '<p class="small text-muted mb-0">You can edit your profile again after 30 days.' + (d.email ? ' Changing your email also needs your password and an emailed code.' : '') + '</p>', confirmText: 'Save changes' }).then(function (yes) {
                    if (!yes) return;
                    (d.email ? askEmailCode(d.email) : Promise.resolve('')).then(function (code) {
                        if (code === null) { VS.toast('Nothing was saved.', 'info'); return; }
                        VS.busy(saveBtn, function () { return send(d.body, code || null); }).then(function (r) {
                            if (!r) return;
                            if (r.ok) { VS.toast('Profile updated successfully.', 'success'); load(); return; }
                            if (r.errors && r.errors.email_otp) { VS.toast(r.message, 'danger'); return; }   // a wrong code saved nothing; Save changes asks for a new one
                            showErrors(r);
                        });
                    });
                });
            }

            box.innerHTML = viewHtml(); setBar();
        }

        function strength(v) {
            if (!v) return 0;
            var score = 0, ok = pwOk(v), upper = /[A-Z]/.test(v), lower = /[a-z]/.test(v), symbol = /[^A-Za-z0-9]/.test(v);
            if (v.length >= 8) score++; if (/[A-Za-z]/.test(v)) score++; if (/\d/.test(v)) score++;
            if (ok && ((v.length >= 12 && upper && lower) || symbol || (upper && lower && v.length >= 10))) score++;
            if (!ok) score = Math.min(score, 2);
            return Math.max(1, Math.min(4, score));
        }
        function wirePassword(u) {
            var box = host.querySelector('#pPw'), open = host.querySelector('#pwOpen');
            function stages(n) { return '<ol class="vs-pwstages" aria-label="Steps">' + ['1 Verify', '2 New password', '3 Done'].map(function (t, i) { return '<li class="' + (i + 1 < n ? 'done' : i + 1 === n ? 'now' : '') + '"' + (i + 1 === n ? ' aria-current="step"' : '') + '>' + t + '</li>'; }).join('') + '</ol>'; }
            function cool(btn, sec) { var left = sec; btn.disabled = true; btn.textContent = 'Resend code (' + left + 's)'; var t = setInterval(function () { left--; btn.textContent = left > 0 ? 'Resend code (' + left + 's)' : 'Resend code'; if (left <= 0) { clearInterval(t); btn.disabled = false; } }, 1000); }
            function close() { box.innerHTML = ''; open.classList.remove('d-none'); }
            function step1() {
                open.classList.add('d-none');
                box.innerHTML = '<div class="vs-subform">' + stages(1) + '<div id="ppAlert"></div><h3 class="h6 fw-bold">Confirm it is you</h3><p class="small text-muted mb-1">To change your password we will email a 6-digit code to ' + VS.esc(u.email) + '.</p><p class="small text-muted">Enter the code on the next step, then choose your new password. The code only works for a short time, and you can ask for a new one.</p><div class="d-flex gap-2"><button class="btn btn-velocity btn-sm" id="ppSend" data-busy-text="Sending">Send code</button><button class="btn btn-light btn-sm" id="ppCancel">Cancel</button></div></div>';
                box.querySelector('#ppCancel').onclick = close;
                box.querySelector('#ppSend').onclick = function (e) {
                    VS.busy(e.currentTarget, function () { return VS.api('/forgot-password', { method: 'POST', body: { email: u.email } }); }).then(function (r) { if (!r) return; if (r.ok || r.status === 429) step2(); else VS.alertBox('ppAlert', r.message); });
                };
            }
            function step2() {
                box.innerHTML = '<div class="vs-subform">' + stages(1) + '<div id="ppAlert"></div><p class="small text-muted mb-2">We sent a 6-digit verification code to ' + VS.esc(u.email) + '. Enter it below to continue.</p><input class="form-control mb-2" id="ppOtp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" aria-label="6-digit code"><p class="small text-muted">The code works once and expires in 15 minutes. Wrong or expired? Tap Resend for a new one.</p><div class="d-flex gap-2 flex-wrap"><button class="btn btn-velocity btn-sm" id="ppVerify" data-busy-text="Verifying">Verify code</button><button class="btn btn-outline-velocity btn-sm" id="ppResend">Resend code</button><button class="btn btn-light btn-sm" id="ppCancel">Cancel</button></div></div>';
                box.querySelector('#ppCancel').onclick = function () { VS.confirm({ title: 'Leave without changing your password?', message: 'Your password has not been changed. You will need to verify a new code if you leave now.', confirmText: 'Leave', cancelText: 'Stay' }).then(function (y) { if (y) close(); }); };
                var rs = box.querySelector('#ppResend'); cool(rs, 30);
                rs.onclick = function () { VS.api('/forgot-password', { method: 'POST', body: { email: u.email } }).then(function (r) { VS.toast(r.ok ? 'A new code has been sent to your email.' : r.message, r.ok ? 'success' : 'danger'); if (r.ok) cool(rs, 30); }); };
                var otp = box.querySelector('#ppOtp'); otp.focus();
                box.querySelector('#ppVerify').onclick = function (e) {
                    VS.clearErrors(box);
                    if (!/^\d{6}$/.test(otp.value.trim())) { VS.fieldError(otp, 'Enter the 6-digit code.'); return; }
                    VS.busy(e.currentTarget, function () { return VS.api('/verify-reset-otp', { method: 'POST', body: { email: u.email, otp: otp.value.trim() } }); }).then(function (r) { if (!r) return; if (r.ok) step3(r.data.reset_token); else { VS.fieldError(otp, r.message || 'That code is invalid or expired. Please try again.'); } });
                };
            }
            function step3(token) {
                box.innerHTML = '<div class="vs-subform">' + stages(2) + '<div id="ppAlert"></div><p class="small text-muted">Code verified. Choose a new password for your account.</p>' +
                    '<label class="form-label small fw-semibold" for="ppNew">New password</label><div class="input-group mb-1"><input class="form-control" id="ppNew" type="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="ppNew" aria-label="Show password"><i class="fas fa-eye"></i></button></div>' +
                    '<div class="vs-strength" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div class="small mb-2" id="ppStrength" aria-live="polite">Choose a strong password</div>' +
                    '<ul class="vs-checklist list-unstyled small mb-3" id="ppRules"><li data-k="len">At least 8 characters</li><li data-k="letter">A letter</li><li data-k="num">A number</li></ul>' +
                    '<label class="form-label small fw-semibold" for="ppCon">Confirm password</label><div class="input-group mb-3"><input class="form-control" id="ppCon" type="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="ppCon" aria-label="Show password"><i class="fas fa-eye"></i></button></div>' +
                    '<div class="d-flex gap-2"><button class="btn btn-velocity btn-sm" id="ppSave" data-busy-text="Saving">Save password</button><button class="btn btn-light btn-sm" id="ppCancel">Cancel</button></div></div>';
                var n = box.querySelector('#ppNew'), c = box.querySelector('#ppCon');
                box.querySelector('#ppCancel').onclick = function () { VS.confirm({ title: 'Leave without changing your password?', message: 'Your password has not been changed. You will need to verify a new code if you leave now.', confirmText: 'Leave', cancelText: 'Stay' }).then(function (y) { if (y) close(); }); };
                box.querySelectorAll('[data-eye]').forEach(function (b) { b.onclick = function () { var i = document.getElementById(b.dataset.eye), show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); b.querySelector('i').className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye'); }; });
                var labels = ['Choose a strong password', 'Strength: Weak', 'Strength: Fair', 'Strength: Good', 'Strength: Strong'];
                function paint() {
                    var v = n.value, st = { len: v.length >= 8, letter: /[A-Za-z]/.test(v), num: /\d/.test(v) }, sc = strength(v);
                    box.querySelectorAll('#ppRules li').forEach(function (li) { var ok = st[li.dataset.k]; li.className = ok ? 'ok' : ''; li.innerHTML = '<i class="fas ' + (ok ? 'fa-circle-check' : 'fa-circle') + ' me-2"></i>' + li.textContent; });
                    box.querySelectorAll('.vs-strength i').forEach(function (b, i) { b.className = i < sc ? 's' + sc : ''; });
                    box.querySelector('#ppStrength').textContent = labels[sc];
                }
                n.addEventListener('input', paint); c.addEventListener('input', function () { c.classList.remove('is-invalid'); }); paint(); n.focus();
                box.querySelector('#ppSave').onclick = function (e) {
                    VS.clearErrors(box);
                    if (!pwOk(n.value)) { VS.fieldError(n.closest('.input-group'), 'Use at least 8 characters with a letter and a number'); return; }
                    if (n.value !== c.value) { VS.fieldError(c.closest('.input-group'), 'Passwords do not match'); return; }
                    VS.busy(e.currentTarget, function () { return VS.api('/guest/password/change', { method: 'POST', body: { reset_token: token, password: n.value, password_confirmation: c.value } }); }).then(function (r) {
                        if (!r) return; if (!r.ok) { VS.alertBox('ppAlert', r.message); return; }
                        box.innerHTML = '<div class="vs-subform text-center">' + stages(3) + '<div class="vs-success"><i class="fas fa-check"></i></div><h3 class="h6 fw-bold mt-3">Password updated</h3><p class="small text-muted">Your password was changed. For your security you will be signed out - log in again with your new password.</p><button class="btn btn-velocity" id="ppDone">Log in again</button></div>';
                        box.querySelector('#ppDone').onclick = function () { var f = document.getElementById('navLogoutForm'); if (f) f.submit(); else window.location.href = '/login'; };
                    });
                };
            }
            open.onclick = step1;
        }
        load();
    };
})();
