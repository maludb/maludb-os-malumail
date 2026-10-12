/* mail-list.js — the conversation list's behaviour (docs/build-specs/mail-list.md): selection and the bulk bar (plain JavaScript, no
   request), the poll by change counter with the visibility guard (every poll_visible_seconds while the tab is visible, poll_hidden_seconds
   while hidden; three 401/404 answers stop it), the out-of-band swaps of its answer, the unread count in the title and on the favicon.
   Test hook: window.mlListTest = {visibility: 'visible' | 'hidden'} overrides document.visibilityState, and every delay the poll schedules
   is pushed onto window.mlPollLog — a proof reads the cadence without waiting for it. */
(function () {
    'use strict';
    var timer = null, failures = 0, list = null, baseIcon = null;
    window.mlPollLog = window.mlPollLog || [];

    function visibility() {
        return (window.mlListTest && window.mlListTest.visibility) || document.visibilityState;
    }

    // ---- selection and the bulk bar ------------------------------------------------------------------------------------------
    function boxes() { return list ? Array.prototype.slice.call(list.querySelectorAll('.mail-select:not(:disabled)')) : []; }
    function refreshBar() {
        if (!list) return;
        var bar = list.querySelector('#mail-bulk-form'), checked = boxes().filter(function (b) { return b.checked; });
        var all = list.querySelector('#mail-all-matching');
        if (!bar) return;
        if (checked.length === 0 && all) all.value = '';
        bar.classList.toggle('d-none', checked.length === 0);
        bar.classList.toggle('d-flex', checked.length > 0);
        var count = bar.querySelector('#mail-selected-count');
        var everything = all && all.value !== '';
        var allBtn = bar.querySelector('#mail-select-all-matching');
        if (count) count.textContent = everything && allBtn ? 'All ' + allBtn.getAttribute('data-total') + ' selected' : checked.length + ' selected';
        var page = list.querySelector('#mail-select-page');
        if (page) {
            var n = boxes().length;
            page.checked = n > 0 && checked.length === n;
            page.indeterminate = checked.length > 0 && checked.length < n;
        }
        checked.forEach(function (b) { b.closest('.mail-row').classList.add('mail-selected'); });
        boxes().filter(function (b) { return !b.checked; }).forEach(function (b) { b.closest('.mail-row').classList.remove('mail-selected'); });
    }
    function selectAll(on) { boxes().forEach(function (b) { b.checked = on; }); refreshBar(); }

    document.addEventListener('change', function (e) {
        if (!list || !list.contains(e.target)) return;
        if (e.target.id === 'mail-select-page') { selectAll(e.target.checked); return; }
        if (e.target.classList.contains('mail-select')) refreshBar();
    });
    document.addEventListener('click', function (e) {
        if (!list) return;
        var t = e.target.closest ? e.target.closest('button') : null;
        if (!t || !list.contains(t)) return;
        if (t.id === 'mail-select-all-page') { selectAll(true); }
        else if (t.id === 'mail-select-none') { var a = list.querySelector('#mail-all-matching'); if (a) a.value = ''; selectAll(false); }
        else if (t.id === 'mail-select-all-matching') {
            selectAll(true);
            var all = list.querySelector('#mail-all-matching');
            if (all) all.value = list.getAttribute('data-view') || '1';
            refreshBar();
        }
    });

    // ---- the title and the favicon ---------------------------------------------------------------------------------------------
    function setCount(n) {
        if (!list) return;
        var base = list.getAttribute('data-title') || '';
        var app = (document.title.split(' · ').pop()) || 'MaluMail';
        var counted = list.getAttribute('data-view') === 'inbox';
        document.title = (counted && n > 0 ? '(' + n + ') ' : '') + base + ' · ' + app;
        drawFavicon(counted ? n : 0);
    }
    function drawFavicon(n) {
        var link = document.querySelector('link[rel~="icon"]');
        if (!link) return;
        if (baseIcon === null) baseIcon = link.getAttribute('data-base') || link.href;
        link.setAttribute('data-base', baseIcon);
        link.setAttribute('data-count', String(n));
        if (n <= 0) { link.href = baseIcon; return; }
        var img = new Image();
        img.onload = function () {
            var c = document.createElement('canvas'); c.width = 32; c.height = 32;
            var g = c.getContext('2d');
            g.drawImage(img, 0, 0, 32, 32);
            g.fillStyle = '#dc3545'; g.beginPath(); g.arc(22, 10, 10, 0, 2 * Math.PI); g.fill();
            g.fillStyle = '#fff'; g.font = 'bold 13px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
            g.fillText(n > 9 ? '9+' : String(n), 22, 11);
            try { link.href = c.toDataURL('image/png'); } catch (err) { /* a tainted canvas: keep the plain icon */ }
        };
        img.src = baseIcon;
    }

    // ---- the poll ----------------------------------------------------------------------------------------------------------------
    function schedule() {
        clearTimeout(timer);
        if (!list || failures >= 3) return;
        var secs = parseInt(list.getAttribute(visibility() === 'visible' ? 'data-poll-visible' : 'data-poll-hidden'), 10) || 15;
        window.mlPollLog.push(secs);
        timer = setTimeout(poll, secs * 1000);
    }
    function pageIds() {
        return Array.prototype.slice.call(list.querySelectorAll('.mail-row[data-thread]')).map(function (r) { return r.getAttribute('data-thread'); })
            .filter(function (x) { return x; }).join(',');
    }
    function poll() {
        if (!list || !document.body.contains(list)) { list = null; return; }
        var p = new URLSearchParams({
            since: list.getAttribute('data-change-seq') || '0', view: list.getAttribute('data-view') || 'inbox',
            folder: list.getAttribute('data-folder') || '', top: list.getAttribute('data-top') || '',
            limit: list.getAttribute('data-limit') || '50', unread: list.getAttribute('data-unread') || '', ids: pageIds()
        });
        var url = '/m/' + list.getAttribute('data-mailbox') + '/changes?' + p.toString();
        var mine = list;
        fetch(url, {credentials: 'same-origin', headers: {'HX-Request': 'true', 'Accept': 'text/html'}}).then(function (res) {
            if (mine !== list) return;
            if (res.status === 401 || res.status === 404 || res.status === 403) { failures++; schedule(); return; }
            failures = 0;
            var seq = res.headers.get('X-ML-Seq');
            if (res.status === 204) { schedule(); return; }
            var unread = parseInt(res.headers.get('X-ML-Unread') || '0', 10);
            return res.text().then(function (html) {
                apply(html);
                if (seq) list.setAttribute('data-change-seq', seq);
                setCount(unread);
                document.dispatchEvent(new CustomEvent('ml:polled', {detail: {seq: seq, unread: unread}}));
                schedule();
            });
        }).catch(function () { schedule(); });
    }
    // hx-swap-oob's two forms, applied here (the answer is not an htmx request's): outerHTML replaces the element of the same id,
    // delete removes it. What is swapped in is handed to htmx so its links work.
    function apply(html) {
        var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
        Array.prototype.slice.call(doc.body.children).forEach(function (el) {
            var how = el.getAttribute('hx-swap-oob') || '';
            var old = el.id ? document.getElementById(el.id) : null;
            el.removeAttribute('hx-swap-oob');
            if (how === 'delete') { if (old) old.remove(); return; }
            if (!old) return;
            var wasChecked = old.querySelector ? old.querySelector('.mail-select') : null;
            var keep = wasChecked && wasChecked.checked;
            var fresh = document.importNode(el, true);
            old.replaceWith(fresh);
            if (keep) { var b = fresh.querySelector('.mail-select'); if (b) b.checked = true; }
            if (window.htmx) window.htmx.process(fresh);
        });
        var rows = list.querySelector('#mail-rows');
        if (rows && rows.children.length === 0) {
            var card = rows.closest('.card'); if (card) card.classList.add('d-none');
        }
        refreshBar();
    }

    function init() {
        var found = document.getElementById('mail-list');
        if (found === list) return;
        list = found;
        failures = 0;
        clearTimeout(timer);
        if (!list) { drawFavicon(0); return; }
        refreshBar();
        var m = document.title.match(/^\((\d+)\)/);
        drawFavicon(list.getAttribute('data-view') === 'inbox' && m ? parseInt(m[1], 10) : 0);
        schedule();
    }
    document.addEventListener('visibilitychange', function () { if (list) { schedule(); } });
    document.addEventListener('ml:visibility', function () { if (list) { schedule(); } });   // the test hook's nudge
    document.body.addEventListener('htmx:afterSettle', init);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
