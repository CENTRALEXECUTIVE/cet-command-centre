/*
 * Show/hide password toggle. Any button with class "pw-toggle" and a
 * data-target pointing at a password input's id will flip it between hidden
 * and visible. Delegated on the document so it works for every field on the
 * page with one listener.
 */
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.pw-toggle');
        if (!btn) return;
        var input = document.getElementById(btn.dataset.target);
        if (!input) return;
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        btn.textContent = reveal ? 'Hide' : 'Show';
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
})();
