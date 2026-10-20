// Draws the "Scan to download" QR code in the Home page hero, in the browser,
// from the real download URL (no external QR service). Uses the pinned
// qrcode-generator 1.4.4 in js/vendor/. The card is hidden in the markup and
// shown here only once the code exists, so there is never an empty box.
(function () {
    var card = document.querySelector('[data-app-qr]');
    if (!card || typeof qrcode !== 'function') return;
    var url = card.getAttribute('data-app-qr');
    var target = card.querySelector('[data-app-qr-image]');
    if (!url || !target) return;
    try {
        var qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        target.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        var svg = target.querySelector('svg');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'QR code that opens the Velocity Suites Android app download');
        svg.setAttribute('focusable', 'false');
        card.hidden = false;
    } catch (e) {
        // leave the card hidden - the download button still works
    }
})();
