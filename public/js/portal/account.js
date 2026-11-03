/*
 * Notifications, Transaction History and Profile for guests - the app's own endpoints:
 * /api/notifications, /api/guest/reservations + /api/guest/bookings (include_hidden), /api/guest/profile and friends.
 */
(function () {
    'use strict';
    var VS = window.VS, S = window.VSStay;
    var Acc = window.VSAccount = {};

    // ===================================================== notifications
    var CATS = { booking: 'Bookings', payment: 'Payments', reservation: 'Reservations', promotion: 'Promotions', announcement: 'Announcements', amenity: 'Amenities', receipt: 'Receipts', system: 'Account' };
    function catOf(n) { var c = String(n.category || 'booking').toLowerCase(); return CATS[c] ? c : 'booking'; }

    Acc.notifications = function (root) {
        var host = root.querySelector('#vsNotes'), form = root.querySelector('#vsNoteFilters'), all = [];
        function paint() {
            var q = form.elements.q.value.trim().toLowerCase(), show = form.elements.show.value, cat = form.elements.cat.value;
            var list = all.filter(function (n) {
                if (show === 'unread' && n.is_read) return false; if (show === 'read' && !n.is_read) return false;
                if (cat && catOf(n) !== cat) return false;
                return !q || (n.title + ' ' + n.message).toLowerCase().indexOf(q) >= 0;
            });
            host.innerHTML = list.length ? list.map(function (n) {
                return '<button type="button" class="vs-card vs-note w-100 text-start ' + (n.is_read ? '' : 'unread') + '" data-id="' + n.id + '"><div class="d-flex justify-content-between gap-2"><span class="fw-bold">' + VS.esc(n.title) + '</span><span class="vs-chip gray">' + VS.esc(CATS[catOf(n)]) + '</span></div><div class="text-muted small">' + VS.esc(n.message).slice(0, 140) + '</div><div class="small text-muted mt-1">' + VS.esc(VS.dateTime(n.created_at)) + (n.is_read ? '' : ' · <strong class="text-danger">New</strong>') + '</div></button>';
            }).join('') : VS.empty('fa-bell', all.length ? 'Nothing matches' : 'No notifications yet', all.length ? 'Try other filters.' : 'Updates about your stays and payments appear here.');
            host.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { open(all.find(function (x) { return x.id == b.dataset.id; })); }; });
            var u = all.filter(function (n) { return !n.is_read; }).length;
            root.querySelector('#vsNoteCount').textContent = u ? u + ' unread' : 'All caught up';
        }
        function target(n) {
            if (n.receipt_number) return { href: '/guest/receipts/' + encodeURIComponent(n.receipt_number), label: 'View receipt' };
            if (n.reference_id && /booking|payment|reservation|amenity|receipt/.test(catOf(n))) return { href: '/guest/reservations/' + n.reference_id, label: 'View transaction' };
            return null;
        }
        function open(n) {
            if (!n) return;
            var t = target(n);
            var html = '<p>' + VS.esc(n.message).replace(/\n/g, '<br>') + '</p><div class="small text-muted">' + VS.esc(VS.dateTime(n.created_at)) + '</div>';
            if (!n.is_read) { n.is_read = true; VS.api('/notifications/' + n.id + '/read', { method: 'PUT' }).then(function () { paint(); }); paint(); }
            var wrap = document.createElement('div'); wrap.className = 'modal fade'; wrap.tabIndex = -1;
            wrap.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content vs-modal"><div class="modal-header border-0 pb-0"><h2 class="modal-title h5 fw-bold">' + VS.esc(n.title) + '</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">' + html + '</div>' +
                '<div class="modal-footer border-0 pt-0"><button class="btn btn-light" data-toggle>Mark as unread</button>' + (t ? '<a class="btn btn-velocity" href="' + t.href + '">' + t.label + '</a>' : '') + '</div></div></div>';
            document.body.appendChild(wrap);
            var m = new bootstrap.Modal(wrap); m.show();
            wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
            var tg = wrap.querySelector('[data-toggle]');
            tg.onclick = function () {
                var toUnread = n.is_read;
                VS.api('/notifications/' + n.id + (toUnread ? '/unread' : '/read'), { method: 'PUT' }).then(function (r) { if (r.ok) { n.is_read = !toUnread; tg.textContent = n.is_read ? 'Mark as unread' : 'Mark as read'; paint(); } });
            };
        }
        function load() {
            host.innerHTML = VS.skeleton(4);
            VS.api('/notifications', { query: { per_page: 200 } }).then(function (r) {
                if (!r.ok) { host.innerHTML = VS.errorState(r.message, 'vsNoteRetry'); document.getElementById('vsNoteRetry').onclick = load; return; }
                all = r.data.data || []; paint();
            });
        }
        form.addEventListener('input', VS.debounce(paint, 120)); form.addEventListener('change', paint); form.addEventListener('submit', function (e) { e.preventDefault(); });
        root.querySelector('#vsReadAll').onclick = function (e) {
            VS.busy(e.currentTarget, function () { return VS.api('/notifications/read-all', { method: 'PUT' }); }).then(function (r) { if (r && r.ok) { all.forEach(function (n) { n.is_read = true; }); paint(); VS.toast('All notifications marked as read.', 'success'); } });
        };
        load();
    };

    // ===================================================== transaction history
    Acc.history = function (root) {
        var host = root.querySelector('#vsHist'), form = root.querySelector('#vsHistFilters'), all = [];
        function paint() {
            var q = form.elements.q.value.trim().toLowerCase(), type = form.elements.type.value, st = form.elements.status.value, from = form.elements.from.value, to = form.elements.to.value;
            var list = all.filter(function (x) {
                var s = S.status(x.it, x.kind), created = String(x.it.created_at || '').slice(0, 10);
                if (type && x.kind !== type) return false; if (st && s.key !== st) return false; if (from && created < from) return false; if (to && created > to) return false;
                return !q || (S.idLabel(x.it, x.kind) + ' ' + S.firstRoomTypes(x.it) + ' ' + s.label + ' ' + S.dates(x.it)).toLowerCase().indexOf(q) >= 0;
            }).sort(function (a, b) { return new Date(b.it.created_at) - new Date(a.it.created_at); });
            root.querySelector('#vsHistCount').textContent = VS.plural(list.length, 'transaction');
            host.innerHTML = list.length ? list.map(function (x) {
                var s = S.status(x.it, x.kind), m = S.money(x.it);
                return '<a class="vs-card vs-stay-card d-block text-decoration-none" href="/guest/' + (x.kind === 'reservation' ? 'reservations' : 'bookings') + '/' + x.it.id + '"><div class="d-flex justify-content-between"><div><div class="fw-bold">' + VS.esc(S.idLabel(x.it, x.kind)) + (x.it.hidden_by_guest ? ' <span class="small text-muted">(removed from My stays)</span>' : '') + '</div><div class="small text-muted">Created ' + VS.esc(VS.dateTime(x.it.created_at)) + '</div><div class="small">' + VS.esc(S.dates(x.it)) + '</div></div><span class="vs-chip ' + s.cls + '">' + s.label + '</span></div>' +
                    '<div class="vs-row mt-1"><span>Total</span><span>' + VS.money(m.total) + '</span></div><div class="vs-row"><span>Paid</span><span>' + VS.money(m.paid) + '</span></div></a>';
            }).join('') : VS.empty('fa-clock-rotate-left', all.length ? 'Nothing matches' : 'No transactions yet', all.length ? 'Try other filters.' : 'Everything you book or reserve is recorded here.');
        }
        function load() {
            host.innerHTML = VS.skeleton(4);
            Promise.all([VS.api('/guest/reservations', { query: { per_page: 200, include_hidden: 1 } }), VS.api('/guest/bookings', { query: { per_page: 200, include_hidden: 1 } })]).then(function (rs) {
                all = [];
                if (rs[0].ok) (rs[0].data.data || []).forEach(function (r) { all.push({ kind: 'reservation', it: r }); });
                if (rs[1].ok) (rs[1].data.data || []).forEach(function (b) { all.push({ kind: 'booking', it: b }); });
                if (!rs[0].ok && !rs[1].ok) { host.innerHTML = VS.errorState(rs[0].message, 'vsHistRetry'); document.getElementById('vsHistRetry').onclick = load; return; }
                paint();
            });
        }
        form.addEventListener('input', VS.debounce(paint, 120)); form.addEventListener('change', paint); form.addEventListener('submit', function (e) { e.preventDefault(); });
        form.querySelector('[data-clear]').onclick = function () { form.reset(); paint(); };
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
            host.innerHTML =
                '<section class="vs-card p-0 overflow-hidden"><div class="vs-cover" style="background-image:linear-gradient(rgba(166,43,52,.35),rgba(166,43,52,.35)),url(/images/hero-building-640.jpg)"></div>' +
                '<div class="px-3 pb-3"><div class="vs-avatar-wrap">' + (pic ? '<button class="vs-avatar" id="pView" aria-label="View profile photo"><img src="' + VS.esc(pic) + '" alt="Your profile photo"></button>' : '<div class="vs-avatar vs-avatar-init" aria-hidden="true">' + VS.esc(initials(u)) + '</div>') +
                '<button class="vs-cam" id="pCam" aria-label="Change profile photo" aria-haspopup="menu"><i class="fas fa-camera"></i></button></div>' +
                '<h1 class="h4 fw-bold mt-2 mb-0">' + VS.esc(name) + '</h1><div class="text-muted">' + VS.esc(u.email) + '</div><div id="pMenu" class="d-none vs-menu"><button data-a="camera"><i class="fas fa-camera me-2"></i>Take a photo</button><button data-a="gallery"><i class="fas fa-image me-2"></i>Choose from gallery</button>' + (pic ? '<button data-a="remove" class="text-danger"><i class="fas fa-trash me-2"></i>Remove photo</button>' : '') + '<button data-a="cancel">Cancel</button></div>' +
                '<input type="file" id="pFileCam" accept="image/*" capture="user" class="d-none"><input type="file" id="pFile" accept="image/*" class="d-none"></div></section>' +
                '<section class="vs-card"><h2>Personal information</h2><div id="pInfoMsg"></div><form id="pForm" novalidate>' +
                '<div class="row g-3"><div class="col-md-4"><label class="form-label small fw-semibold" for="pf">First name</label><input class="form-control" id="pf" value="' + VS.esc(u.first_name) + '"></div><div class="col-md-4"><label class="form-label small fw-semibold" for="pm">Middle name</label><input class="form-control" id="pm" value="' + VS.esc(u.middle_name || '') + '"></div><div class="col-md-4"><label class="form-label small fw-semibold" for="pl">Last name</label><input class="form-control" id="pl" value="' + VS.esc(u.last_name) + '"></div>' +
                '<div class="col-md-6"><label class="form-label small fw-semibold" for="pg">Gender</label><select class="form-select" id="pg"><option value="male"' + ((g.gender || u.gender) === 'male' ? ' selected' : '') + '>Male</option><option value="female"' + ((g.gender || u.gender) === 'female' ? ' selected' : '') + '>Female</option></select></div>' +
                '<div class="col-md-6"><label class="form-label small fw-semibold" for="pd">Date of birth</label><input class="form-control" type="date" id="pd" value="' + VS.esc(String(g.date_of_birth || u.date_of_birth || '').slice(0, 10)) + '"></div>' +
                '<div class="col-md-6"><label class="form-label small fw-semibold" for="pp">Mobile number</label><input class="form-control" id="pp" value="' + VS.esc(g.mobile_number || u.mobile_number || '') + '"></div>' +
                '<div class="col-md-6"><label class="form-label small fw-semibold" for="pa">Address</label><input class="form-control" id="pa" value="' + VS.esc(g.address || u.address || '') + '"></div></div>' +
                '<p class="text-muted small mt-3 mb-2">' + (pu.can_update === false ? 'You can update your information again on ' + VS.esc(VS.date(String(pu.next_update_at || '').slice(0, 10))) + ' (' + VS.plural(pu.days_remaining || 0, 'day') + ' left).' : 'For account security, your information can be updated once every 30 days.') + '</p>' +
                '<button class="btn btn-velocity" type="submit"' + (pu.can_update === false ? ' disabled' : '') + ' data-busy-text="Saving">Save changes</button></form></section>' +
                '<section class="vs-card"><h2>Email address</h2><p class="mb-2">' + VS.esc(u.email) + '</p><div id="pEmail"><button class="btn btn-outline-velocity btn-sm" id="pEmailBtn">Change email address</button></div></section>' +
                '<section class="vs-card"><h2>Password</h2><div id="pPw"><button class="btn btn-outline-velocity btn-sm" id="pPwBtn">Change password</button></div></section>';
            wirePhoto(u, g, !!pic, pic);
            wireInfo(u, g);
            wireEmail(u);
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

        function wireInfo(u, g) {
            var f = host.querySelector('#pForm');
            f.addEventListener('submit', function (e) {
                e.preventDefault(); VS.clearErrors(f);
                var first = f.querySelector('#pf'), last = f.querySelector('#pl'), ok = true;
                if (!first.value.trim()) { VS.fieldError(first, 'Enter your first name.'); ok = false; } if (!last.value.trim()) { VS.fieldError(last, 'Enter your last name.'); ok = false; } if (!ok) return;
                VS.confirm({ title: 'Save your changes?', message: 'You can update your information again after 30 days.', confirmText: 'Save changes' }).then(function (yes) {
                    if (!yes) return;
                    var body = { first_name: first.value.trim(), middle_name: f.querySelector('#pm').value.trim() || null, last_name: last.value.trim(), gender: f.querySelector('#pg').value, date_of_birth: f.querySelector('#pd').value || null, mobile_number: f.querySelector('#pp').value.trim() || null, address: f.querySelector('#pa').value.trim() || null, country: g.country || 'Philippines' };
                    VS.busy(f.querySelector('button[type=submit]'), function () { return VS.api('/guest/profile', { method: 'PUT', body: body }); }).then(function (r) {
                        if (!r) return; if (r.ok) { VS.toast('Your information was updated.', 'success'); load(); } else { VS.alertBox('pInfoMsg', r.message); window.scrollTo({ top: 0 }); }
                    });
                });
            });
        }

        function wireEmail(u) {
            host.querySelector('#pEmailBtn').onclick = function () {
                var box = host.querySelector('#pEmail');
                box.innerHTML = '<div id="peAlert"></div><label class="form-label small fw-semibold" for="peNew">New email address</label><input class="form-control mb-2" id="peNew" type="email"><label class="form-label small fw-semibold" for="peCur">Current password</label><input class="form-control mb-2" id="peCur" type="password" autocomplete="current-password"><button class="btn btn-velocity btn-sm" id="peSend" data-busy-text="Sending">Send code</button>';
                box.querySelector('#peSend').onclick = function (e) {
                    var n = box.querySelector('#peNew').value.trim(), c = box.querySelector('#peCur').value; VS.clearErrors(box);
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(n)) { VS.fieldError(box.querySelector('#peNew'), 'Enter a valid email address.'); return; } if (!c) { VS.fieldError(box.querySelector('#peCur'), 'Enter your current password.'); return; }
                    VS.busy(e.currentTarget, function () { return VS.api('/guest/profile/email/request', { method: 'POST', body: { new_email: n, current_password: c } }); }).then(function (r) {
                        if (!r) return; if (!r.ok) { VS.alertBox('peAlert', r.message); return; }
                        box.innerHTML = '<div id="peAlert"></div><p class="small text-muted">We sent a 6-digit code to ' + VS.esc(n) + '.</p><input class="form-control mb-2" id="peOtp" inputmode="numeric" maxlength="6" placeholder="6-digit code" aria-label="6-digit code"><button class="btn btn-velocity btn-sm" id="peOk" data-busy-text="Verifying">Confirm new email</button>';
                        box.querySelector('#peOk').onclick = function (e2) {
                            VS.busy(e2.currentTarget, function () { return VS.api('/guest/profile/email/confirm', { method: 'POST', body: { otp: box.querySelector('#peOtp').value.trim() } }); }).then(function (r2) {
                                if (!r2) return; if (r2.ok) { VS.toast('Your email address was changed.', 'success'); load(); } else VS.alertBox('peAlert', r2.message);
                            });
                        };
                    });
                };
            };
        }

        function wirePassword(u) {
            host.querySelector('#pPwBtn').onclick = function () {
                var box = host.querySelector('#pPw');
                function step1() {
                    box.innerHTML = '<div id="ppAlert"></div><p class="small text-muted">We will send a 6-digit code to ' + VS.esc(u.email) + ' to confirm it is you.</p><button class="btn btn-velocity btn-sm" id="ppSend" data-busy-text="Sending">Send code</button>';
                    box.querySelector('#ppSend').onclick = function (e) {
                        VS.busy(e.currentTarget, function () { return VS.api('/forgot-password', { method: 'POST', body: { email: u.email } }); }).then(function (r) { if (!r) return; if (r.ok || r.status === 429) step2(); else VS.alertBox('ppAlert', r.message); });
                    };
                }
                function step2() {
                    box.innerHTML = '<div id="ppAlert"></div><label class="form-label small fw-semibold" for="ppOtp">6-digit code</label><input class="form-control mb-2" id="ppOtp" inputmode="numeric" maxlength="6"><button class="btn btn-velocity btn-sm" id="ppVerify" data-busy-text="Verifying">Verify code</button>';
                    box.querySelector('#ppVerify').onclick = function (e) {
                        VS.busy(e.currentTarget, function () { return VS.api('/verify-reset-otp', { method: 'POST', body: { email: u.email, otp: box.querySelector('#ppOtp').value.trim() } }); }).then(function (r) { if (!r) return; if (r.ok) step3(r.data.reset_token); else VS.alertBox('ppAlert', r.message); });
                    };
                }
                function step3(token) {
                    box.innerHTML = '<div id="ppAlert"></div><label class="form-label small fw-semibold" for="ppNew">New password</label><input class="form-control mb-1" id="ppNew" type="password" autocomplete="new-password"><div class="small text-muted mb-2">At least 8 characters with a letter and a number.</div><label class="form-label small fw-semibold" for="ppCon">Confirm password</label><input class="form-control mb-2" id="ppCon" type="password" autocomplete="new-password"><button class="btn btn-velocity btn-sm" id="ppSave" data-busy-text="Saving">Change password</button>';
                    box.querySelector('#ppSave').onclick = function (e) {
                        var n = box.querySelector('#ppNew'), c = box.querySelector('#ppCon'); VS.clearErrors(box);
                        if (!pwOk(n.value)) { VS.fieldError(n, 'Use at least 8 characters with a letter and a number.'); return; } if (n.value !== c.value) { VS.fieldError(c, 'The passwords do not match.'); return; }
                        VS.busy(e.currentTarget, function () { return VS.api('/guest/password/change', { method: 'POST', body: { reset_token: token, password: n.value, password_confirmation: c.value } }); }).then(function (r) {
                            if (!r) return; if (r.ok) { VS.notice({ title: 'Password changed', message: 'Please sign in again with your new password.', button: 'Sign in' }).then(function () { document.getElementById('vsLogoutForm').submit(); }); } else VS.alertBox('ppAlert', r.message);
                        });
                    };
                }
                step1();
            };
        }
        load();
    };
})();
