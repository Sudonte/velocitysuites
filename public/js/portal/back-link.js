/*
 * The "Back" button at the top-left of the sign-in, sign-up and password-reset cards: back to the previous page of this site, or to the landing
 * page when there is none (the page was opened directly). Pages that step through their own screens mark it data-custom and handle it themselves.
 */
(function () {
    'use strict';
    function previousPageIsHere() {
        try { return window.history.length > 1 && document.referrer !== '' && new URL(document.referrer).origin === window.location.origin && document.referrer !== window.location.href; } catch (e) { return false; }
    }
    window.VSGoBack = function () { if (previousPageIsHere()) window.history.back(); else window.location.href = '/'; };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-topback]:not([data-custom])').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); window.VSGoBack(); });
        });
    });
})();
