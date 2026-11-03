/*
 * Payment on the web, the same as the app: Review Billing, then five GCash steps (Check amount, Send via GCash, Enter reference,
 * Upload receipt, Submit). Online payment is a partial payment of 20% to 50% of the grand total (never the whole balance); the
 * rule below is the app's PaymentRules and the server (ReservationWorkflowService) enforces it again. A submitted payment waits
 * for the front desk: it is "Waiting for verification" until it is verified, and only verified money counts as paid.
 */
(function () {
    'use strict';
    var VS = window.VS;
    var P = window.VSPay = {};

    var MIN = 0.20, MAX = 0.50;
    function r2(v) { return Math.round(v * 100) / 100; }

    /** Port of the app's PaymentRules.Range for one bill at one moment. */
    P.range = function (total, paid, discountPending, depositCap) {
        total = r2(total); paid = r2(paid);
        var remaining = Math.max(0, r2(total - paid));
        var min = r2(total * MIN);
        var capLeft = discountPending && depositCap != null ? Math.max(0, r2(depositCap - paid)) : Infinity;
        var most = Math.min(r2(total * MAX), remaining, capLeft);
        var max = most >= remaining - 0.009 ? 0 : most;
        var r = { total: total, paid: paid, remaining: remaining, min: min, max: max, capLeft: capLeft, discountPending: !!discountPending, depositCap: depositCap };
        r.settled = remaining <= 0.009;
        r.canPartial = !r.settled && min <= max + 0.009;
        r.capReached = !!discountPending && depositCap != null && capLeft + 0.009 < min;
        return r;
    };
    P.partialAmount = function (range, fraction) { return Math.min(r2(range.total * fraction), Math.min(range.remaining, range.capLeft)); };

    P.blockedMessage = function (range) {
        if (range.settled) return 'Nothing is left to pay. This is fully paid.';
        if (range.discountPending && !range.canPartial && range.capReached) return 'The most you can pay online while your discount is being verified has been reached. The rest is settled at the front desk.';
        if (!range.canPartial) return range.discountPending ? 'Online payment is on hold until the front desk verifies your discount ID.' : 'The remaining balance is paid at the front desk, at check-in or check-out.';
        return '';
    };

    var QR = '/images/gcash-official-qr.jpg';

    /**
     * ctx: { total, paid, discountPending, depositCap, kindLabel ('New booking' | 'Reservation #12'), lines: [{label, amount}],
     *        submit(fields) -> Promise<{ok, message, data}>, success(data) -> html, onCancel }
     * fields: { percent, amount, gcash_number, reference_number, receipt }
     */
    P.run = function (host, ctx) {
        var range = P.range(ctx.total, ctx.paid || 0, ctx.discountPending, ctx.depositCap);
        var st = { step: 0, percent: null, number: '', ref: '', receipt: null, key: 'pay' + Date.now().toString(36) };
        var names = ['Check amount', 'Send via GCash', 'Enter reference', 'Upload receipt', 'Submit'];

        function head(title, sub) { return '<h2 class="vs-section-title">' + title + '</h2>' + (sub ? '<p class="text-muted">' + sub + '</p>' : '') + '<div id="vsPayErr" aria-live="polite"></div>'; }
        function err(m) { VS.alertBox('vsPayErr', m); }
        function bar() { return '<ol class="vs-steps" aria-hidden="true">' + names.map(function (_, i) { return '<li class="' + (i < st.step ? 'done' : i === st.step ? 'now' : '') + '"></li>'; }).join('') + '</ol><div class="vs-stepline">Step ' + (st.step + 1) + ' of 5 · ' + names[st.step] + '</div>'; }
        function amount() { return P.partialAmount(range, st.percent / 100); }

        function review() {
            var blocked = P.blockedMessage(range);
            host.innerHTML = head('Review billing', ctx.kindLabel) +
                '<div class="vs-card"><h3 class="h6 fw-bold">What you’re paying for</h3>' + (ctx.lines || []).map(function (l) { return '<div class="vs-row"><span>' + VS.esc(l.label) + '</span><span>' + VS.money(l.amount) + '</span></div>'; }).join('') +
                '<hr class="vs-rule"><div class="vs-row vs-strong"><span>Grand total</span><span>' + VS.money(range.total) + '</span></div>' +
                (range.paid > 0 ? '<div class="vs-row"><span>Paid so far</span><span>' + VS.money(range.paid) + '</span></div><div class="vs-row vs-strong"><span>Remaining balance</span><span>' + VS.money(range.remaining) + '</span></div>' : '') + '</div>' +
                (blocked ? '<div class="alert alert-info vs-alert">' + VS.esc(blocked) + '</div><a class="btn btn-light" href="javascript:history.back()">Back</a>' :
                    '<div class="vs-card"><h3 class="h6 fw-bold">How much do you want to pay now?</h3><p class="text-muted small">You can pay 20% to 50% of the grand total online. The rest is paid at the hotel.</p><div id="vsPct" class="row g-2">' +
                    [20, 30, 40, 50].map(function (p) {
                        var amt = P.partialAmount(range, p / 100), ok = amt >= range.min - 0.005 && amt <= range.max + 0.005;
                        return '<div class="col-6 col-md-3"><input type="radio" class="btn-check" name="pct" id="pct' + p + '" value="' + p + '"' + (ok ? '' : ' disabled') + (st.percent === p ? ' checked' : '') + '><label class="btn btn-outline-velocity w-100 py-2" for="pct' + p + '"><div class="fw-bold">' + p + '%</div><div class="vs-num small">' + VS.money(amt) + '</div></label></div>';
                    }).join('') + '</div></div><div class="d-flex justify-content-between gap-2"><button class="btn btn-light" id="pBack">Back</button><button class="btn btn-velocity" id="pNext">Continue to GCash</button></div>');
            var back = host.querySelector('#pBack') || host.querySelector('a.btn-light');
            if (back && back.id) back.onclick = function () { if (ctx.onCancel) ctx.onCancel(); else history.back(); };
            host.querySelectorAll('[name=pct]').forEach(function (r) { r.onchange = function () { st.percent = +r.value; }; });
            var next = host.querySelector('#pNext'); if (next) next.onclick = function () { if (!st.percent) { err('Choose how much you want to pay now.'); return; } st.step = 0; gcash(); };
        }

        function gcash() {
            ({ 0: s1, 1: s2, 2: s3, 3: s4, 4: s5 })[st.step]();
            host.scrollIntoView({ behavior: VS.reducedMotion ? 'auto' : 'smooth', block: 'start' });
        }
        function nav(label) { return '<div class="d-flex justify-content-between mt-3 gap-2"><button class="btn btn-light" id="gBack">Back</button><button class="btn btn-velocity" id="gNext">' + (label || 'Next') + '</button></div>'; }
        function wire(onNext) {
            host.querySelector('#gBack').onclick = function () { if (st.step === 0) review(); else { st.step--; gcash(); } };
            host.querySelector('#gNext').onclick = function (e) { onNext(e.currentTarget); };
        }

        function s1() {
            host.innerHTML = bar() + head('Check the amount') + '<div class="vs-card"><div class="vs-row"><span>Grand total</span><span>' + VS.money(range.total) + '</span></div>' +
                '<div class="vs-row"><span>' + st.percent + '% share</span><span>' + VS.money(amount()) + '</span></div><div class="vs-row vs-strong"><span>Amount to send</span><span class="vs-pay">' + VS.money(amount()) + '</span></div>' +
                '<p class="text-muted small mt-2 mb-0">' + VS.esc(P.share(range, st.percent)) + '</p></div>' + nav();
            wire(function () { st.step = 1; gcash(); });
        }
        function s2() {
            host.innerHTML = bar() + head('Send via GCash') + '<div class="vs-card text-center"><img src="' + QR + '" alt="Official Velocity Suites GCash QR code" style="max-width:260px;width:100%;border-radius:12px">' +
                '<p class="mt-2 mb-1"><a href="' + QR + '" download="velocity-suites-gcash-qr.jpg">Save the QR code</a></p></div>' +
                '<div class="vs-card"><h3 class="h6 fw-bold">How to pay</h3><ol class="mb-0 ps-3"><li>Open the GCash app and tap Scan QR.</li><li>Scan the code above, or save it to your photos first.</li><li>Pay exactly <strong class="vs-num">' + VS.money(amount()) + '</strong>.</li><li>Keep your receipt, then continue to enter your details and upload it.</li></ol></div>' + nav();
            wire(function () { st.step = 2; gcash(); });
        }
        function fmtRef(v) { v = v.replace(/\D/g, '').slice(0, 13); return [v.slice(0, 3), v.slice(3, 7), v.slice(7)].filter(Boolean).join(' '); }
        function s3() {
            host.innerHTML = bar() + head('Enter your GCash details') + '<div class="vs-card"><label class="form-label fw-semibold" for="gNum">GCash registered mobile number</label><div class="input-group mb-1"><span class="input-group-text">+63</span><input class="form-control" id="gNum" inputmode="numeric" maxlength="10" placeholder="9171234567" value="' + VS.esc(st.number) + '"></div><div class="text-muted small mb-3">10 digits after +63, starting with 9.</div>' +
                '<label class="form-label fw-semibold" for="gRef">GCash reference number</label><input class="form-control" id="gRef" inputmode="numeric" placeholder="XXX XXXX XXXXXX" value="' + VS.esc(fmtRef(st.ref)) + '"><div class="text-muted small">Enter the 13-digit reference number from your GCash receipt.</div></div>' + nav();
            var num = host.querySelector('#gNum'), ref = host.querySelector('#gRef');
            num.oninput = function () { num.value = num.value.replace(/\D/g, '').slice(0, 10); };
            ref.oninput = function () { ref.value = fmtRef(ref.value); };
            wire(function () {
                VS.clearErrors(host); var ok = true;
                if (!/^9\d{9}$/.test(num.value)) { VS.fieldError(num.closest('.input-group'), 'Enter 10 digits after +63, starting with 9.'); ok = false; }
                if (ref.value.replace(/\D/g, '').length !== 13) { VS.fieldError(ref, 'The reference number has 13 digits.'); ok = false; }
                if (!ok) return; st.number = num.value; st.ref = ref.value.replace(/\D/g, ''); st.step = 3; gcash();
            });
        }
        function s4() {
            host.innerHTML = bar() + head('Upload your receipt', 'A screenshot or photo of your GCash receipt (JPG or PNG).') + '<div class="vs-card"><input class="form-control" type="file" id="gFile" accept="image/jpeg,image/png"><div id="gPrev" class="mt-3"></div></div>' + nav();
            var f = host.querySelector('#gFile'), prev = host.querySelector('#gPrev');
            function paint() { prev.innerHTML = st.receipt ? '<img src="' + URL.createObjectURL(st.receipt) + '" alt="Your GCash receipt" class="rounded-3" style="max-width:100%;max-height:360px"><div class="small text-muted mt-1">' + VS.esc(st.receipt.name) + '</div>' : ''; }
            paint();
            f.onchange = function () {
                var file = f.files[0]; if (!file) return; VS.clearErrors(host);
                if (!/^image\/(jpeg|png)$/.test(file.type)) { VS.fieldError(f, 'Choose a JPG or PNG image.'); f.value = ''; return; }
                if (file.size > 50 * 1024 * 1024) { VS.fieldError(f, 'The image is over 50 MB.'); f.value = ''; return; }
                VS.compressImage(file).then(function (c) { st.receipt = c; paint(); });
            };
            wire(function () { if (!st.receipt) { VS.fieldError(f, 'Upload your GCash receipt to continue.'); return; } st.step = 4; gcash(); });
        }
        function s5() {
            host.innerHTML = bar() + head('Review and submit', 'Please review your payment details. The front desk verifies them before the payment counts.') + '<div class="vs-card">' +
                '<div class="vs-row"><span>Amount</span><span>' + VS.money(amount()) + '</span></div><div class="vs-row"><span>Payment type</span><span>Partial payment (' + st.percent + '%)</span></div><div class="vs-row"><span>GCash number</span><span>+63 ' + VS.esc(st.number) + '</span></div>' +
                '<div class="vs-row"><span>Reference number</span><span>' + VS.esc(fmtRef(st.ref)) + '</span></div><div class="vs-row"><span>Receipt</span><span>' + VS.esc(st.receipt.name) + '</span></div></div>' + nav('Submit payment');
            wire(function (btn) {
                VS.confirm({ title: 'Submit payment?', message: 'You are sending ' + VS.money(amount()) + ' by GCash for verification.', confirmText: 'Submit payment' }).then(function (yes) {
                    if (!yes) return;
                    VS.busy(btn, function () { return ctx.submit({ percent: st.percent, amount: amount(), gcash_number: st.number, reference_number: st.ref, receipt: st.receipt, key: st.key }); }).then(function (r) {
                        if (!r) return;
                        if (!r.ok) { err(r.message); return; }
                        host.innerHTML = '<div class="text-center py-4"><div class="vs-success"><i class="fas fa-check"></i></div><h2 class="h4 fw-bold mt-3">Payment submitted</h2>' +
                            '<p class="text-muted">Waiting for verification. Your payment of <strong class="vs-num">' + VS.money(amount()) + '</strong> (reference ' + VS.esc(fmtRef(st.ref)) + ') is with the front desk. You will be notified once it is verified.</p>' + ctx.success(r.data) + '</div>';
                    });
                });
            });
        }

        review();
    };

    P.share = function (range, percent) {
        var rest = r2(range.total - P.partialAmount(range, percent / 100) - range.paid);
        return percent + '% of ' + VS.money(range.total) + ' (grand total) · the rest (' + VS.money(Math.max(0, rest)) + ') is paid at the hotel';
    };
})();
