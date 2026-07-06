/**
 * Make "Flightradar24" links open the FR24 app (with the flight loaded) on
 * Android, falling back to the website if the app isn't installed. iOS / desktop
 * keep the plain https link (a universal link that opens the app when present).
 */
(function () {
    if (!/Android/i.test(navigator.userAgent)) {
        return;
    }
    document.querySelectorAll('a[data-flightradar]').forEach(function (a) {
        var url = a.getAttribute('href') || '';
        if (url.indexOf('flightradar24.com') === -1) {
            return;
        }
        var noScheme = url.replace(/^https?:\/\//, '');
        a.setAttribute(
            'href',
            'intent://' + noScheme + '#Intent;scheme=https;package=com.flightradar24free;' +
            'S.browser_fallback_url=' + encodeURIComponent(url) + ';end'
        );
    });
})();
