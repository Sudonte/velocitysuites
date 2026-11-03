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

    function toSession(token) {
        return fetch('/auth/portal-session', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': VS.csrf() },
            body: JSON.stringify({ token: token })
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); });
    }

    function enter(token, box) {
        return toSession(token).then(function (r) {
            if (r.ok) { window.location.href = r.d.redirect || '/guest/dashboard'; return true; }
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
                b.classList.remove('is-invalid');
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
            invalid: function () { boxes.forEach(function (b) { b.classList.add('is-invalid'); }); }
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
            if (!EMAIL_RE.test(email.value.trim())) { VS.fieldError(email, 'Enter a valid email address.'); bad = true; }
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
                    [['_token', VS.csrf()], ['email', email.value.trim()], ['password', pass.value]].forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
                    document.body.appendChild(f); f.submit(); return;
                }
                VS.alertBox(box, r.message || 'We could not sign you in. Please try again.');
            });
        });
    };

    // ================================================= FORGOT / RESET =================================================

    A.forgot = function (root) {
        var host = $('#vsForgot', root), email = VS.qs('email') || '', state = { email: email, otp: '' };
        var stepNo = 1;

        function head(t, s) { return '<h1 class="h4 fw-bold mb-1">' + t + '</h1><p class="text-muted">' + s + '</p><div id="vsFAlert"></div>'; }

        function stepEmail() {
            host.innerHTML = (VS.qs('from') === 'signin' ? '<div class="alert alert-warning vs-alert">Sign-in did not work three times. Reset your password to get back in.</div>' : '') +
                head('Reset your password', 'Enter your email and we will send you a code.') +
                '<form id="fEmail" novalidate><label class="form-label fw-semibold" for="fe">Email address</label><input class="form-control mb-3" id="fe" type="email" autocomplete="email" value="' + VS.esc(state.email) + '">' +
                '<button class="btn btn-velocity w-100" type="submit">Send code</button></form>' +
                '<p class="text-center mt-3 mb-0"><a href="/login">Back to sign in</a></p>';
            var f = $('#fEmail', host), inp = $('#fe', host);
            f.addEventListener('submit', function (e) {
                e.preventDefault(); VS.clearErrors(f);
                if (!EMAIL_RE.test(inp.value.trim())) { VS.fieldError(inp, 'Enter a valid email address.'); return; }
                state.email = inp.value.trim();
                VS.busy(f.querySelector('button'), function () { return VS.api('/forgot-password', { method: 'POST', body: { email: state.email }, quiet401: true }); }).then(function (r) {
                    if (!r) return;
                    if (r.ok || r.status === 429) { if (r.status === 429) VS.toast(r.message, 'warning'); stepCode(); }
                    else VS.alertBox('vsFAlert', r.message);
                });
            });
        }

        function stepCode() {
            host.innerHTML = head('Enter the code', 'We sent a 6-digit code to ' + maskEmail(state.email) + '. It expires in 15 minutes.') +
                '<div id="vsOtpHost" class="mb-3"></div><button class="btn btn-velocity w-100 mb-2" id="fGo">Continue</button><button class="btn btn-link w-100" id="fResend">Resend code</button>' +
                '<p class="text-center mb-0"><a href="#" id="fChange">Use a different email</a></p>';
            var otp = otpBoxes($('#vsOtpHost', host), function (v) { if (v.length === 6) go.click(); });
            otp.focus();
            var go = $('#fGo', host), resend = $('#fResend', host);
            cooldown(resend, 30, 'Resend code');
            $('#fChange', host).onclick = function (e) { e.preventDefault(); stepEmail(); };
            resend.onclick = function () {
                VS.api('/forgot-password', { method: 'POST', body: { email: state.email }, quiet401: true }).then(function (r) {
                    VS.toast(r.ok ? 'We sent a new code.' : r.message, r.ok ? 'success' : 'warning'); cooldown(resend, 30, 'Resend code');
                });
            };
            go.onclick = function () {
                var v = otp.value();
                if (v.length !== 6) { VS.alertBox('vsFAlert', 'Enter the 6-digit code.'); return; }
                VS.busy(go, function () { return VS.api('/verify-reset-otp', { method: 'POST', body: { email: state.email, otp: v }, quiet401: true }); }).then(function (r) {
                    if (!r) return;
                    if (r.ok) { state.otp = v; stepPassword(); } else { otp.invalid(); VS.alertBox('vsFAlert', r.message || 'Invalid or expired code.'); }
                });
            };
        }

        function stepPassword() {
            host.innerHTML = head('Create a new password', 'Choose a password you have not used before.') +
                '<form id="fPw" novalidate><label class="form-label fw-semibold" for="np">New password</label>' +
                '<div class="input-group mb-1"><input class="form-control" id="np" type="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="np" aria-label="Show password"><i class="fas fa-eye"></i></button></div>' +
                '<div id="fCheck" class="small mb-3"></div>' +
                '<label class="form-label fw-semibold" for="cp">Confirm password</label><input class="form-control mb-3" id="cp" type="password" autocomplete="new-password">' +
                '<button class="btn btn-velocity w-100" type="submit">Change password and sign in</button></form>';
            var f = $('#fPw', host), np = $('#np', host), cp = $('#cp', host);
            eyeToggle(host); checklist(np, $('#fCheck', host));
            f.addEventListener('submit', function (e) {
                e.preventDefault(); VS.clearErrors(f);
                if (!pwOk(np.value)) { VS.fieldError(np.closest('.input-group'), 'Use at least 8 characters with a letter and a number.'); return; }
                if (np.value !== cp.value) { VS.fieldError(cp, 'The passwords do not match.'); return; }
                VS.busy(f.querySelector('button[type=submit]'), function () {
                    return VS.api('/reset-password', { method: 'POST', quiet401: true, body: { email: state.email, otp: state.otp, password: np.value, password_confirmation: cp.value, device_name: 'Web' } });
                }).then(function (r) {
                    if (!r) return;
                    if (r.ok && r.data && r.data.token) { enter(r.data.token, 'vsFAlert'); return; }
                    if (r.status === 422 && /code/i.test(r.message)) { stepCode(); VS.alertBox('vsFAlert', r.message); return; }
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

        function show(n) {
            cur = n;
            steps.forEach(function (s, i) { s.classList.toggle('d-none', i !== n); });
            bar.innerHTML = names.map(function (_, i) { return '<li class="' + (i < n ? 'done' : i === n ? 'now' : '') + '"></li>'; }).join('');
            line.textContent = 'Step ' + (n + 1) + ' of ' + names.length + ' · ' + names[n];
            VS.alertBox(alertId, '');
            var first = steps[n].querySelector('input,select'); if (first) first.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: VS.reducedMotion ? 'auto' : 'smooth' });
        }

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
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'file') { e.preventDefault(); var n = steps[cur].querySelector('[data-next]'); if (n) n.click(); }
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

        var submit = $('#vsRegSubmit', root);
        submit.addEventListener('click', function () {
            VS.clearErrors(steps[5]); VS.alertBox(alertId, '');
            if (!f('terms').checked) { VS.fieldError(f('terms'), 'Accept the terms to continue.'); return; }
            VS.busy(submit, function () { return VS.api('/register', { method: 'POST', body: data, quiet401: true }); }).then(function (r) {
                if (!r) return;
                if (r.ok) { codeStep(); return; }
                var errs = r.errors || {};
                var map = { first_name: 0, last_name: 0, middle_name: 0, gender: 1, date_of_birth: 1, age: 1, email: 2, mobile_number: 2, address: 3, country: 3, region: 3, city: 3, barangay: 3, street: 3, password: 4 };
                var k = Object.keys(errs)[0];
                if (k && map[k] != null) { show(map[k]); VS.alertBox(alertId, errs[k][0]); } else VS.alertBox(alertId, r.message);
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
                        panel.innerHTML = '<div class="text-center py-3"><div class="vs-success"><i class="fas fa-check"></i></div><h1 class="h4 fw-bold mt-3">Your account is ready</h1><p class="text-muted">Welcome to Velocity Suites, ' + VS.esc(data.first_name) + '.</p><a class="btn btn-velocity" href="' + VS.esc(s.d.redirect || '/guest/dashboard') + '">Go to my dashboard</a></div>';
                    });
                });
            };
        }

        show(0);
    };
})();
