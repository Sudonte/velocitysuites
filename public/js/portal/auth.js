/*
 * Sign in, sign up and password reset for guests - all through the app's own API (/api/login, /api/register, /api/verify-otp,
 * /api/forgot-password, /api/verify-reset-otp, /api/reset-password), so every rule (one "Incorrect email or password" answer, 18+,
 * the password rule, codes that expire, locks) is the API's. A successful sign-in is traded for a web session (POST /auth/portal-session).
 */
(function () {
    'use strict';
    var VS = window.VS;
    var A = window.VSAuth = {};

    var $ = function (sel, root) { return (root || document).querySelector(sel); };

    function maskEmail(e) {
        var p = String(e).split('@');
        if (p.length !== 2) return e;
        var n = p[0];
        return (n.length <= 2 ? n[0] + '*' : n[0] + '***' + n[n.length - 1]) + '@' + p[1];
    }

    function toSession(token, remember) {
        return fetch('/auth/portal-session', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': VS.csrf() },
            body: JSON.stringify({ token: token, remember: !!remember })
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); });
    }

    // rooms picked before signing in (kept 30 minutes): after sign-in the wizard opens with them filled in
    function selectionTarget() {
        try {
            var v = JSON.parse(localStorage.getItem('vs_selection') || 'null');
            if (v && v.intent && v.ts && Date.now() - v.ts <= 30 * 60 * 1000) return '/guest/' + (v.intent === 'reserve' ? 'reserve' : 'book') + '/new?sel=1';
        } catch (e) { /* none */ }
        return null;
    }
    A.selectionTarget = selectionTarget;

    function enter(token, box) {
        var rm = document.getElementById('remember');
        return toSession(token, rm && rm.checked).then(function (r) {
            if (r.ok) { window.location.href = selectionTarget() || r.d.redirect || '/guest/dashboard'; return true; }
            VS.alertBox(box, r.d && r.d.message ? r.d.message : 'Sign-in could not be completed. Please try again.');
            return false;
        });
    }

    // ---- one-time code: six boxes ----

    function otpBoxes(host, onChange) {
        host.innerHTML = '<div class="vs-otp" role="group" aria-label="6-digit code">' + [0, 1, 2, 3, 4, 5].map(function (i) {
            return '<input class="form-control vs-otp-box" inputmode="numeric" autocomplete="' + (i === 0 ? 'one-time-code' : 'off') + '" maxlength="1" aria-label="Digit ' + (i + 1) + '">';
        }).join('') + '</div>';
        var boxes = [].slice.call(host.querySelectorAll('input'));
        function value() { return boxes.map(function (b) { return b.value; }).join(''); }
        boxes.forEach(function (b, i) {
            b.addEventListener('input', function () {
                b.value = b.value.replace(/\D/g, '').slice(-1);
                if (b.value && boxes[i + 1]) boxes[i + 1].focus();
                b.classList.remove('is-invalid', 'bad');
                if (onChange) onChange(value());
            });
            b.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].focus(); boxes[i - 1].value = ''; if (onChange) onChange(value()); }
                if (e.key === 'ArrowLeft' && boxes[i - 1]) boxes[i - 1].focus();
                if (e.key === 'ArrowRight' && boxes[i + 1]) boxes[i + 1].focus();
            });
            b.addEventListener('paste', function (e) {
                var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                if (!t) return;
                e.preventDefault();
                t.split('').forEach(function (c, k) { if (boxes[k]) boxes[k].value = c; });
                (boxes[Math.min(t.length, 5)]).focus();
                if (onChange) onChange(value());
            });
        });
        return {
            value: value,
            focus: function () { boxes[0].focus(); },
            clear: function () { boxes.forEach(function (b) { b.value = ''; }); boxes[0].focus(); },
            // a red outline for one second (no "!" icon, nothing stays locked)
            invalid: function () { boxes.forEach(function (b) { b.classList.add('bad'); }); setTimeout(function () { boxes.forEach(function (b) { b.classList.remove('bad'); }); }, 1000); },
            disable: function (off) { boxes.forEach(function (b) { b.disabled = !!off; }); }
        };
    }

    // ---- the password rule: 8+ characters, a letter, a number (same checklist as the app) ----

    function pwState(v) { return { len: v.length >= 8, letter: /[A-Za-z]/.test(v), num: /\d/.test(v) }; }
    function pwOk(v) { var s = pwState(v); return s.len && s.letter && s.num; }
    function checklist(input, host) {
        host.innerHTML = '<ul class="vs-checklist list-unstyled mb-0" aria-live="polite"><li data-k="len">At least 8 characters</li><li data-k="letter">A letter</li><li data-k="num">A number</li></ul>';
        function paint() {
            var s = pwState(input.value);
            host.querySelectorAll('li').forEach(function (li) {
                var ok = s[li.getAttribute('data-k')];
                li.className = ok ? 'ok' : '';
                li.innerHTML = '<i class="fas ' + (ok ? 'fa-circle-check' : 'fa-circle') + ' me-2"></i>' + li.textContent;
            });
        }
        input.addEventListener('input', paint); paint();
    }
    function eyeToggle(root) {
        root.querySelectorAll('[data-eye]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var inp = document.getElementById(btn.getAttribute('data-eye'));
                var show = inp.type === 'password';
                inp.type = show ? 'text' : 'password';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.querySelector('i').className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye');
            });
        });
    }

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    // the two domains the hotel's accounts live on (case and spaces never matter): guests and the administrator use Gmail, hotel staff use @hotel.com
    function isGmail(v) { return /^[^\s@]+@gmail\.com$/i.test(String(v).trim()); }
    function isHotel(v) { return /^[^\s@]+@hotel\.com$/i.test(String(v).trim()); }
    var GMAIL_MSG = 'Please use the Gmail address registered to your account (@gmail.com).';
    var STAFF_ON_GMAIL_MSG = 'Hotel staff accounts can\u2019t reset here. Use \u201cHotel staff: Request a staff password reset\u201d below.';
    var SIGNUP_GMAIL_MSG = 'Please use a Gmail address (@gmail.com) to create your account.';

    // ---- timer for "Resend code" ----
    function cooldown(btn, seconds, label) {
        var left = seconds; btn.disabled = true;
        var t = setInterval(function () {
            left--; btn.textContent = left > 0 ? label + ' (' + left + 's)' : label;
            if (left <= 0) { clearInterval(t); btn.disabled = false; }
        }, 1000);
        btn.textContent = label + ' (' + left + 's)';
    }

    // ===================================================== SIGN IN =====================================================

    A.login = function (root) {
        var form = $('#vsLoginForm', root), box = $('#vsLoginAlert', root), panel = $('#vsLoginPanel', root), code = $('#vsCodePanel', root);
        var email = $('#email', form), pass = $('#password', form), btn = $('#vsLoginBtn', form);
        eyeToggle(root);
        if (VS.qs('expired')) VS.alertBox(box, 'Your session ended. Please sign in again.', 'warning');
        if (VS.qs('reset')) VS.alertBox(box, 'Your password was changed. Sign in with the new password.', 'success');
        if (VS.qs('email')) email.value = VS.qs('email');

        var key = function () { return 'vs_fail_' + email.value.trim().toLowerCase(); };

        function codeScreen(opts) {
            panel.classList.add('d-none'); code.classList.remove('d-none');
            code.innerHTML = '<h2 class="h5 fw-bold">' + VS.esc(opts.title) + '</h2><p class="text-muted">' + VS.esc(opts.text) + '</p><div id="vsOtpHost" class="mb-3"></div><div id="vsCodeAlert"></div>' +
                '<button class="btn btn-velocity w-100 mb-2" id="vsCodeGo">Verify</button><button class="btn btn-link w-100" id="vsCodeResend">Resend code</button>' +
                '<button class="btn btn-link w-100 text-muted" id="vsCodeBack">Back to sign in</button>';
            var otp = otpBoxes($('#vsOtpHost', code)); otp.focus();
            var go = $('#vsCodeGo', code), resend = $('#vsCodeResend', code);
            cooldown(resend, 30, 'Resend code');
            $('#vsCodeBack', code).onclick = function () { window.location.href = '/login'; };
            resend.onclick = function () {
                VS.api(opts.resendPath, { method: 'POST', body: opts.resendBody, quiet401: true }).then(function (r) {
                    VS.toast(r.ok ? 'We sent a new code.' : r.message, r.ok ? 'success' : 'danger');
                    if (r.ok) cooldown(resend, 30, 'Resend code');
                });
            };
            go.onclick = function () {
                var v = otp.value();
                if (v.length !== 6) { VS.alertBox('vsCodeAlert', 'Enter the 6-digit code.'); return; }
                VS.busy(go, function () { return VS.api(opts.verifyPath, { method: 'POST', body: opts.verifyBody(v), quiet401: true }); }).then(function (r) {
                    if (!r) return;
                    if (r.ok && r.data && r.data.token) { enter(r.data.token, 'vsCodeAlert'); return; }
                    otp.invalid(); VS.alertBox('vsCodeAlert', r.message || 'That code is not right.');
                });
            };
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            VS.clearErrors(form); VS.alertBox(box, '');
            var bad = false;
            if (!EMAIL_RE.test(email.value.trim())) { VS.fieldError(email.closest('.input-group') || email, 'Enter a valid email address.'); bad = true; }
            if (!pass.value) { VS.fieldError(pass.closest('.input-group') || pass, 'Enter your password.'); bad = true; }
            if (bad) return;
            VS.busy(btn, function () {
                return VS.api('/login', { method: 'POST', quiet401: true, body: { email: email.value.trim(), password: pass.value, device_name: 'Web' } });
            }).then(function (r) {
                if (!r) return;
                if (r.ok && r.data && r.data.token) { localStorage.removeItem(key()); return enter(r.data.token, box); }
                var c = r.code || (r.data && r.data.code);
                if (r.status === 401 && c === 'INVALID_CREDENTIALS') {
                    var n = (+localStorage.getItem(key()) || 0) + 1; localStorage.setItem(key(), n);
                    if (n >= 3) { localStorage.removeItem(key()); window.location.href = '/forgot-password?email=' + encodeURIComponent(email.value.trim()) + '&from=signin'; return; }
                    VS.alertBox(box, 'Incorrect email or password.'); pass.value = ''; pass.focus(); return;
                }
                if (c === 'ACCOUNT_NOT_VERIFIED') {
                    codeScreen({ title: 'Verify your account', text: 'Enter the code we sent to ' + maskEmail(email.value.trim()) + ', or ask for a new one.', resendPath: '/resend-otp', resendBody: { email: email.value.trim() },
                        verifyPath: '/verify-otp', verifyBody: function (v) { return { email: email.value.trim(), otp: v }; } });
                    VS.api('/resend-otp', { method: 'POST', body: { email: email.value.trim() }, quiet401: true });
                    return;
                }
                if (c === 'ACCOUNT_DEACTIVATED' && r.data && r.data.reactivation_token) {
                    var tok = r.data.reactivation_token;
                    codeScreen({ title: 'Reactivate your account', text: 'Your account is deactivated. Enter the code sent to ' + (r.data.masked_email || 'your email') + ' to reactivate it.', resendPath: '/reactivate-resend', resendBody: { reactivation_token: tok },
                        verifyPath: '/reactivate-verify', verifyBody: function (v) { return { reactivation_token: tok, otp: v }; } });
                    return;
                }
                if (r.status === 403 && /guest accounts only/i.test(r.message || '')) {
                    // a staff account: its sign-in is the website's own
                    var f = document.createElement('form'); f.method = 'POST'; f.action = '/login';
                    [['_token', VS.csrf()], ['email', email.value.trim()], ['password', pass.value]].concat($('#remember', form) && $('#remember', form).checked ? [['remember', 'on']] : []).forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
                    document.body.appendChild(f); f.submit(); return;
                }
                VS.alertBox(box, r.message || 'We could not sign you in. Please try again.');
            });
        });
    };

    // ================================================= FORGOT / RESET =================================================

    /** POST JSON to the website itself (session + CSRF), answering { ok, status, data, message } like VS.api. */
    function webPost(path, body) {
        return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': VS.csrf() }, body: JSON.stringify(body) })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) {
                    var first = d.errors ? (Object.keys(d.errors).map(function (k) { return d.errors[k][0]; })[0] || '') : '';
                    return { ok: r.ok, status: r.status, data: d, message: (first && /password/i.test(first)) ? first : (d.message || first || 'Something went wrong. Please try again.') };
                });
            }).catch(function () { return { ok: false, status: 0, data: {}, message: 'We could not reach the server. Check your connection and try again.' }; });
    }

    A.forgot = function (root) {
        var host = $('#vsForgot', root), email = VS.qs('email') || '', state = { email: email, otp: '' };
        var SENT = 'If an account exists for that email, we\u2019ve sent a 6-digit code.';

        /** Every step: its title, one line under it and the (empty) alert area; the logo header is on the page itself. */
        function head(t, s, noAlert) { return '<h1 class="h4 fw-bold text-center mb-1">' + t + '</h1>' + (s ? '<p class="text-muted text-center mb-3">' + s + '</p>' : '') + (noAlert ? '' : '<div id="vsFAlert" aria-live="polite"></div>'); }
        function dots(n) { stepNo = n; var el = $('#vsFStep', root); if (el) el.textContent = 'Step ' + n + ' of 3'; }
        var stepNo = 1;
        // Back at the top-left of the card: step 1 leaves the page, the later steps go back to the email step (a code is single-use, so it cannot be re-entered)
        var topBack = $('[data-topback]', root);
        if (topBack) topBack.addEventListener('click', function (e) { e.preventDefault(); if (stepNo === 1) window.VSGoBack(); else stepEmail(); });

        function stepEmail() {
            dots(1);
            host.innerHTML = (VS.qs('from') === 'signin' ? '<div class="alert alert-warning vs-alert">Sign-in did not work three times. Reset your password to get back in.</div>' : '') +
                head('Reset your password', '') +
                '<form id="fEmail" novalidate><label class="form-label fw-semibold" for="fe">Email address</label><input class="form-control" id="fe" type="email" autocomplete="email" aria-describedby="feHelp" value="' + VS.esc(state.email) + '">' +
                '<div class="form-text mb-3" id="feHelp">Enter the email registered to your Velocity Suites account</div>' +
                '<button class="btn btn-velocity w-100" type="submit" data-busy-text="Sending">Send code</button></form>' +
                '<p class="text-center mt-3 mb-3"><a href="/login">Back to sign in</a></p>' +
                '<a class="btn btn-outline-secondary w-100" id="feStaff" href="/forgot-password/staff">Hotel staff: Request a staff password reset</a>';
            var f = $('#fEmail', host), inp = $('#fe', host);
            var staffBtn = $('#feStaff', host);
            function flag(msg, hotel) {
                VS.fieldError(inp, msg);
                staffBtn.classList.toggle('vs-staff-hl', !!hotel);   // a hotel address: point at the staff button
            }
            inp.addEventListener('input', function () { staffBtn.classList.remove('vs-staff-hl'); });
            f.addEventListener('submit', function (e) {
                e.preventDefault(); VS.clearErrors(f); staffBtn.classList.remove('vs-staff-hl');
                var typed = inp.value.trim();
                if (!EMAIL_RE.test(typed)) { flag('Enter a valid email address.'); return; }
                if (isHotel(typed)) { flag(STAFF_ON_GMAIL_MSG, true); return; }
                if (!isGmail(typed)) { flag(GMAIL_MSG); return; }
                state.email = typed.toLowerCase();
                // asks first, with the address half-hidden; the answer afterwards is the same for every Gmail address
                VS.confirm({ title: 'Send a reset code?', message: 'Send a reset code to ' + maskEmail(state.email) + '?', confirmText: 'Send code', cancelText: 'Cancel' }).then(function (yes) {
                    if (!yes) return;
                    VS.busy(f.querySelector('button'), function () { return webPost('/auth/reset/send', { email: state.email }); }).then(function (r) {
                        if (!r) return;
                        var code = r.data && r.data.code;
                        if (code === 'STAFF_EMAIL') { flag(r.message, true); return; }
                        if (code === 'NOT_GMAIL') { flag(r.message); return; }
                        // the same answer for every address: registered or not, staff or guest
                        if (r.ok || r.status === 429) stepCode(r.status === 429 ? 'Please wait a moment before asking for another code. If you already asked, use the code we sent.' : null);
                        else VS.alertBox('vsFAlert', r.message);
                    });
                });
            });
        }

        function stepCode(note, isError) {
            dots(2);
            var masked = maskEmail(state.email);
            host.innerHTML = head('Enter the code', 'We sent a 6-digit code to ' + VS.esc(masked), true) +
                '<div id="vsOtpHost" class="mb-2"></div><div id="vsFMsg" class="vs-fmsg" role="status" aria-live="polite"></div>' +
                '<button class="btn btn-velocity w-100 mb-2 mt-3" id="fGo" data-busy-text="Checking">Continue</button><button class="btn btn-outline-secondary w-100" id="fResend">Resend code</button>' +
                '<p class="text-center mt-3 mb-0"><a href="#" id="fChange">Change email</a></p>';
            var msgEl = $('#vsFMsg', host), locked = false;
            // ONE message under the boxes at a time: the "sent" note right after a code goes out, otherwise the error of the last try
            function say(kind, text) { msgEl.className = 'vs-fmsg ' + kind; msgEl.textContent = text; }
            if (isError) say('err', note); else say('info', (note || SENT) + ' It expires in 15 minutes.');
            var otp = otpBoxes($('#vsOtpHost', host), function (v) { if (v.length === 6 && !locked) go.click(); });
            otp.focus();
            var go = $('#fGo', host), resend = $('#fResend', host);
            cooldown(resend, 30, 'Resend code');
            function lock(off) { locked = off; otp.disable(off); go.disabled = off; }
            $('#fChange', host).onclick = function (e) { e.preventDefault(); stepEmail(); };
            resend.onclick = function () {
                webPost('/auth/reset/send', { email: state.email }).then(function (r) {
                    if (r.ok) { lock(false); otp.clear(); say('info', 'We sent a new code to ' + masked + '.'); cooldown(resend, 30, 'Resend code'); }
                    else { say('err', r.message); cooldown(resend, Math.max(5, Number(r.data && r.data.retry_after) || 30), 'Resend code'); }
                });
            };
            go.onclick = function () {
                if (locked) return;
                var v = otp.value();
                if (v.length !== 6) { say('err', 'Enter the 6-digit code.'); otp.focus(); return; }
                VS.busy(go, function () { return webPost('/auth/reset/verify', { email: state.email, otp: v }); }).then(function (r) {
                    if (!r) return;
                    if (r.ok) { state.otp = v; stepPassword(); return; }
                    var code = r.data && r.data.code;
                    say('err', r.message);
                    otp.invalid(); otp.clear();                      // empty, editable, cursor in the first box
                    if (code === 'TOO_MANY_TRIES') lock(true);       // only "Resend code" is left
                });
            };
        }

        /** 0-4 for the strength bar: length, a letter and a number are the rule; 12+ characters and a symbol make it stronger. */
        function strength(v) {
            if (!v) return 0;
            var st = pwState(v);
            if (!(st.len && st.letter && st.num)) return 1;
            var sc = 2; if (v.length >= 12) sc++; if (/[^A-Za-z0-9]/.test(v)) sc++;
            return Math.min(4, sc);
        }

        function stepPassword() {
            dots(3);
            host.innerHTML = head('Create a new password', 'Choose a password you have not used before.') +
                '<form id="fPw" novalidate><label class="form-label fw-semibold" for="np">New password</label>' +
                '<div class="input-group mb-1"><input class="form-control" id="np" type="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="np" aria-label="Show password"><i class="fas fa-eye"></i></button></div>' +
                '<div class="vs-strength mb-2" aria-hidden="true"><span></span><span></span><span></span><span></span></div><div class="small fw-semibold mb-2" id="fStrengthLbl" aria-live="polite"></div>' +
                '<div id="fCheck" class="small mb-3"></div>' +
                '<label class="form-label fw-semibold" for="cp">Confirm new password</label><div class="input-group mb-1"><input class="form-control" id="cp" type="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="cp" aria-label="Show password"><i class="fas fa-eye"></i></button></div>' +
                '<div class="small mb-3" id="fMatch" aria-live="polite"></div>' +
                '<button class="btn btn-velocity w-100" type="submit" id="fSave" data-busy-text="Saving" disabled>Save and sign in</button></form>';
            var f = $('#fPw', host), np = $('#np', host), cp = $('#cp', host), save = $('#fSave', host), bars = host.querySelectorAll('.vs-strength span'), lbl = $('#fStrengthLbl', host), match = $('#fMatch', host);
            eyeToggle(host); checklist(np, $('#fCheck', host));
            function update() {
                var sc = strength(np.value);
                bars.forEach(function (b, i) { b.className = i < sc ? 'on s' + sc : ''; });
                lbl.textContent = np.value ? ['', 'Weak', 'Fair', 'Good', 'Strong'][sc] : '';
                var same = cp.value !== '' && np.value === cp.value;
                match.innerHTML = cp.value === '' ? '' : same ? '<span class="text-success"><i class="fas fa-circle-check me-1"></i>The passwords match</span>' : '<span class="text-danger"><i class="fas fa-circle-exclamation me-1"></i>The passwords do not match</span>';
                save.disabled = !(pwOk(np.value) && same);
            }
            np.addEventListener('input', update); cp.addEventListener('input', update); update();
            f.addEventListener('submit', function (e) {
                e.preventDefault(); VS.clearErrors(f);
                if (!pwOk(np.value)) { VS.fieldError(np.closest('.input-group'), 'Use at least 8 characters with a letter and a number.'); return; }
                if (np.value !== cp.value) { VS.fieldError(cp.closest('.input-group'), 'The passwords do not match.'); return; }
                VS.busy(save, function () {
                    return webPost('/auth/reset/complete', { email: state.email, otp: state.otp, password: np.value, password_confirmation: cp.value });
                }).then(function (r) {
                    if (!r) return;
                    if (r.ok && r.data && r.data.redirect) {
                        try { sessionStorage.setItem('vs_flash', JSON.stringify({ m: 'Password changed. Welcome back!', t: 'success' })); } catch (er) { /* none */ }
                        // each person lands on THEIR dashboard (a guest first on the rooms they had picked, if any)
                        window.location.href = (/\/guest\/dashboard/.test(r.data.redirect) && selectionTarget()) || r.data.redirect; return;
                    }
                    if (r.data && /^(WRONG_CODE|CODE_EXPIRED|TOO_MANY_TRIES)$/.test(r.data.code || '')) { stepCode(r.message, true); return; }
                    VS.alertBox('vsFAlert', r.message || 'We could not change the password.');
                });
            });
        }

        stepEmail();
    };

    // ===================================================== SIGN UP =====================================================

    A.register = function (root) {
        var form = $('#vsReg', root), steps = [].slice.call(root.querySelectorAll('[data-step]'));
        var bar = $('#vsRegBar', root), line = $('#vsRegLine', root), alertId = 'vsRegAlert';
        var names = ['Your name', 'About you', 'Contact', 'Address', 'Password and photo', 'Review and terms'];
        var cur = 0, data = {}, photo = null;
        eyeToggle(root);
        var f = function (n) { return form.elements[n]; };
        checklist(f('password'), $('#vsRegCheck', root));

        // date of birth: the latest allowed day is exactly 18 years ago (hotel time)
        var today = VS.today();
        var latest = (+today.slice(0, 4) - 18) + today.slice(4);
        f('date_of_birth').max = latest;

        function ageOf(dob) {
            var t = today.split('-').map(Number), d = dob.split('-').map(Number);
            var a = t[0] - d[0]; if (t[1] < d[1] || (t[1] === d[1] && t[2] < d[2])) a--; return a;
        }
        f('date_of_birth').addEventListener('input', function () {
            var v = f('date_of_birth').value, hint = $('#vsAge', root);
            hint.textContent = v ? 'Age: ' + ageOf(v) : '';
        });

        var back = $('[data-back]', root), next = $('[data-next]', root), submitBtn = $('#vsRegSubmit', root), barFill = $('.progress-bar', bar);
        function show(n) {
            cur = n;
            steps.forEach(function (s, i) { s.classList.toggle('d-none', i !== n); });
            barFill.style.width = Math.round((n + 1) / names.length * 100) + '%';
            bar.setAttribute('aria-valuenow', n + 1);
            line.textContent = 'Step ' + (n + 1) + ' of ' + names.length + ' · ' + names[n];
            back.disabled = n === 0;
            next.classList.toggle('d-none', n === names.length - 1);
            submitBtn.classList.toggle('d-none', n !== names.length - 1);
            VS.alertBox(alertId, '');
            var first = steps[n].querySelector('input,select'); if (first) first.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: VS.reducedMotion ? 'auto' : 'smooth' });
        }

        // floating labels: the label rests inside the box and floats up once the box has a value, focus, or a hint
        function floatify() {
            form.querySelectorAll('.form-group').forEach(function (g) {
                var lab = g.querySelector(':scope > label[for]'), ctl = lab && g.querySelector(':scope > .form-control, :scope > .form-select');
                if (!ctl || ctl.type === 'file' || ctl.type === 'checkbox' || ctl.type === 'radio') return;
                g.classList.add('vs-float');
            });
        }
        function refreshFloat() {
            form.querySelectorAll('.vs-float').forEach(function (g) {
                var ctl = g.querySelector(':scope > .form-control, :scope > .form-select');
                var up = ctl.tagName === 'SELECT' || ctl.type === 'date' || !!ctl.value || document.activeElement === ctl || !!ctl.getAttribute('placeholder');
                g.classList.toggle('is-up', up);
            });
        }
        floatify(); refreshFloat();
        form.addEventListener('focusin', refreshFloat); form.addEventListener('focusout', refreshFloat);
        form.addEventListener('input', refreshFloat); form.addEventListener('change', refreshFloat);
        setInterval(refreshFloat, 500);   // the address lists fill boxes without an input event

        function req(el, msg) { if (!el.value.trim()) { VS.fieldError(el, msg); return false; } return true; }

        function validate(n) {
            VS.clearErrors(steps[n]); var ok = true;
            if (n === 0) {
                ok = req(f('first_name'), 'Enter your first name.') && ok;
                ok = req(f('last_name'), 'Enter your last name.') && ok;
            } else if (n === 1) {
                if (!form.querySelector('input[name=gender]:checked')) { VS.fieldError($('#vsGender', root), 'Choose your gender.'); ok = false; }
                var dob = f('date_of_birth').value;
                if (!dob) { VS.fieldError(f('date_of_birth'), 'Enter your date of birth.'); ok = false; }
                else if (ageOf(dob) < 18) { VS.fieldError(f('date_of_birth'), 'You must be 18 or older to create an account.'); ok = false; }
            } else if (n === 2) {
                if (!EMAIL_RE.test(f('email').value.trim())) { VS.fieldError(f('email'), 'Enter a valid email address.'); ok = false; }
                else if (!isGmail(f('email').value)) { VS.fieldError(f('email'), SIGNUP_GMAIL_MSG); ok = false; }
                var m = f('mobile_number').value.replace(/[\s-]/g, '');
                if (!/^(09\d{9}|\+639\d{9}|639\d{9})$/.test(m) && !/^\+?\d{7,15}$/.test(m)) { VS.fieldError(f('mobile_number'), 'Enter a valid mobile number, such as 09171234567.'); ok = false; }
            } else if (n === 3) {
                var cas = $('[data-address-cascade]', root).__addressCascade;
                if (cas && !cas.validate()) ok = false;
            } else if (n === 4) {
                if (!pwOk(f('password').value)) { VS.fieldError(f('password').closest('.input-group'), 'Use at least 8 characters with a letter and a number.'); ok = false; }
                if (f('password').value !== f('password_confirmation').value) { VS.fieldError(f('password_confirmation'), 'The passwords do not match.'); ok = false; }
            }
            return ok;
        }

        form.addEventListener('input', function (e) { var t = e.target; if (t.classList.contains('is-invalid')) { t.classList.remove('is-invalid'); var fb = t.closest('.vs-float,.form-group,.input-group'); fb = fb && fb.querySelector('.vs-fb'); if (fb) fb.remove(); } });
        form.addEventListener('focusout', function (e) {
            var t = e.target, id = t.id, msg = '';
            if (!t.value || !t.value.trim() && id !== 'password') { if (!t.value && ['first_name', 'last_name', 'email', 'mobile_number', 'password'].indexOf(id) < 0) return; }
            if (id === 'email' && t.value && !EMAIL_RE.test(t.value.trim())) msg = 'Enter a valid email address.';
            else if (id === 'email' && t.value && !isGmail(t.value)) msg = SIGNUP_GMAIL_MSG;
            if (id === 'password_confirmation' && t.value && t.value !== f('password').value) msg = 'The passwords do not match.';
            if (id === 'date_of_birth' && t.value && ageOf(t.value) < 18) msg = 'You must be 18 or older to create an account.';
            if (id === 'first_name' && form.dataset.touched && !t.value.trim()) msg = 'Enter your first name.';
            if (msg) VS.fieldError(t, msg);
        });

        function composeAddress() {
            var v = function (n) { var e = form.querySelector('[name="' + n + '"]:not(:disabled)'); return e ? e.value.trim() : ''; };
            var country = v('country');
            if (country.toLowerCase() !== 'philippines') return country;
            var sel = function (id) { var s = document.getElementById(id); return s && s.selectedOptions[0] ? s.selectedOptions[0].textContent.trim() : ''; };
            var parts = [v('street')];
            if (sel('addrBarangay')) parts.push('Brgy. ' + sel('addrBarangay'));
            parts.push(sel('addrCity') || v('city'));
            if (sel('addrProvince') || v('province')) parts.push(sel('addrProvince') || v('province'));
            parts.push(sel('addrRegion') || v('region'));
            if (v('zip_code')) parts.push(v('zip_code'));
            parts.push(country);
            return parts.filter(Boolean).join(', ');
        }

        function collect() {
            var val = function (n) { var e = form.querySelector('[name="' + n + '"]:not(:disabled)'); return e ? e.value.trim() : ''; };
            var d = {
                first_name: val('first_name'), middle_name: val('middle_name'), last_name: val('last_name'),
                gender: (form.querySelector('input[name=gender]:checked') || {}).value, date_of_birth: val('date_of_birth'),
                email: val('email'), mobile_number: val('mobile_number').replace(/[\s-]/g, ''),
                country: val('country'), region: val('region'), province: val('province'), city: val('city'), barangay: val('barangay'),
                street: val('street'), zip_code: val('zip_code'), timezone: val('timezone'),
                password: f('password').value, password_confirmation: f('password_confirmation').value, otp_channel: 'email'
            };
            d.age = ageOf(d.date_of_birth); d.address = composeAddress();
            Object.keys(d).forEach(function (k) { if (d[k] === '' || d[k] == null) delete d[k]; });
            return d;
        }

        function review() {
            data = collect();
            var rows = [['Name', [data.first_name, data.middle_name, data.last_name].filter(Boolean).join(' ')], ['Gender', data.gender === 'male' ? 'Male' : 'Female'],
                ['Date of birth', VS.date(data.date_of_birth) + ' (age ' + data.age + ')'], ['Email', data.email], ['Mobile number', data.mobile_number], ['Address', data.address]];
            $('#vsRegReview', root).innerHTML = rows.map(function (r) { return '<div class="vs-row"><span>' + r[0] + '</span><span>' + VS.esc(r[1]) + '</span></div>'; }).join('');
        }

        root.querySelectorAll('[data-next]').forEach(function (b) {
            b.addEventListener('click', function () { if (validate(cur)) { if (cur + 1 === steps.length - 1) review(); show(cur + 1); } });
        });
        root.querySelectorAll('[data-back]').forEach(function (b) { b.addEventListener('click', function () { show(cur - 1); }); });
        // the Back at the top-left of the card: the previous step, or the landing page from step 1
        var topBack = $('[data-topback]', root);
        if (topBack) topBack.addEventListener('click', function (e) { e.preventDefault(); if (cur > 0) show(cur - 1); else window.location.href = '/'; });
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'file') { e.preventDefault(); var n = cur === names.length - 1 ? submitBtn : next; if (n) n.click(); }
        });

        $('#vsPhoto', root).addEventListener('change', function (e) {
            var file = e.target.files[0]; photo = file || null;
            var prev = $('#vsPhotoPrev', root);
            if (file) { if (file.size > 4 * 1024 * 1024) { VS.fieldError(e.target, 'Choose a photo under 4 MB.'); photo = null; e.target.value = ''; return; } prev.src = URL.createObjectURL(file); prev.classList.remove('d-none'); }
            else prev.classList.add('d-none');
        });

        // terms
        $('#vsTermsLink', root).addEventListener('click', function (e) {
            e.preventDefault();
            VS.confirm({ title: 'Terms and agreement', html: '<div class="vs-terms">' + $('#vsTermsText', root).innerHTML + '</div>', confirmText: 'I agree', cancelText: 'Close' }).then(function (yes) { if (yes) f('terms').checked = true; });
        });

        var submit = submitBtn;
        submit.addEventListener('click', function () {
            VS.clearErrors(steps[5]); VS.alertBox(alertId, '');
            if (!f('terms').checked) { VS.fieldError(f('terms'), 'Accept the terms to continue.'); return; }
            VS.busy(submit, function () { return VS.api('/register', { method: 'POST', body: data, quiet401: true }); }).then(function (r) {
                if (!r) return;
                if (r.ok) { codeStep(); return; }
                var errs = r.errors || {};
                var map = { first_name: 0, last_name: 0, middle_name: 0, gender: 1, date_of_birth: 1, age: 1, email: 2, mobile_number: 2, address: 3, country: 3, region: 3, city: 3, barangay: 3, street: 3, password: 4 };
                var k = Object.keys(errs)[0];
                if (k && map[k] != null) { show(map[k]); if (k === 'email') VS.fieldError(f('email'), errs[k][0]); else VS.alertBox(alertId, errs[k][0]); } else VS.alertBox(alertId, r.message);
            });
        });

        function codeStep() {
            $('#vsRegWrap', root).classList.add('d-none');
            var panel = $('#vsRegCode', root); panel.classList.remove('d-none');
            panel.innerHTML = '<h1 class="h4 fw-bold">Check your email</h1><p class="text-muted">We sent a 6-digit code to <strong>' + VS.esc(maskEmail(data.email)) + '</strong>. It expires in 5 minutes.</p>' +
                '<div id="vsOtpHost" class="mb-3"></div><div id="vsCAlert"></div><button class="btn btn-velocity w-100 mb-2" id="cGo">Verify and create account</button>' +
                '<button class="btn btn-link w-100" id="cResend">Resend code</button><button class="btn btn-link w-100 text-muted" id="cBack">Change my details</button>';
            var otp = otpBoxes($('#vsOtpHost', panel), function (v) { if (v.length === 6) go.click(); }); otp.focus();
            var go = $('#cGo', panel), resend = $('#cResend', panel);
            cooldown(resend, 30, 'Resend code');
            $('#cBack', panel).onclick = function () { panel.classList.add('d-none'); $('#vsRegWrap', root).classList.remove('d-none'); show(0); };
            resend.onclick = function () {
                VS.api('/resend-otp', { method: 'POST', body: { email: data.email }, quiet401: true }).then(function (r) { VS.toast(r.ok ? 'We sent a new code.' : r.message, r.ok ? 'success' : 'danger'); if (r.ok) cooldown(resend, 30, 'Resend code'); });
            };
            go.onclick = function () {
                var v = otp.value(); if (v.length !== 6) { VS.alertBox('vsCAlert', 'Enter the 6-digit code.'); return; }
                VS.busy(go, function () { return VS.api('/verify-otp', { method: 'POST', body: { email: data.email, otp: v }, quiet401: true }); }).then(function (r) {
                    if (!r) return;
                    if (!(r.ok && r.data && r.data.token)) { otp.invalid(); VS.alertBox('vsCAlert', r.message || 'That code is not right.'); return; }
                    var token = r.data.token;
                    var up = photo ? (function () { var fd = new FormData(); fd.append('profile_picture', photo); return fetch('/api/guest/profile/picture', { method: 'POST', headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' }, body: fd }).catch(function () {}); })() : Promise.resolve();
                    up.then(function () { return toSession(token); }).then(function (s) {
                        if (!s.ok) { VS.alertBox('vsCAlert', 'Your account was created. Please sign in.'); return; }
                        panel.innerHTML = '<div class="text-center py-3"><div class="vs-success"><i class="fas fa-check"></i></div><h1 class="h4 fw-bold mt-3">Your account is ready</h1><p class="text-muted">Welcome to Velocity Suites, ' + VS.esc(data.first_name) + '.</p><a class="btn btn-velocity" href="' + VS.esc(selectionTarget() || s.d.redirect || '/guest/dashboard') + '">' + (selectionTarget() ? 'Continue with my rooms' : 'Go to my dashboard') + '</a></div>';
                    });
                });
            };
        }

        show(0);
    };
})();
