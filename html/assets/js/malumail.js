/* malumail.js — the shell's own behaviour (docs/build-specs/sso-shell.md): the rail's drawer closes after a navigation on a phone, and the
   mailbox the page is in follows HTMX navigation (data-mailbox on #page-content, read by the command bar). Nothing else lives here yet. */
(function () {
    document.body.addEventListener('htmx:afterSwap', function (e) {
        if (!e.detail || !e.detail.target || e.detail.target.id !== 'page-content') return;
        var m = (location.pathname.match(/^\/m\/([0-9]+)(\/|$)/) || [])[1] || '';
        e.detail.target.dataset.mailbox = m;
    });
})();
