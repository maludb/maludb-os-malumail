/* keys.js — Gmail's keyboard on the conversation page (design §9; docs/build-specs/reading.md "The keyboard"): j/k the next and the
   previous conversation in the list's order (the page's data-next / data-prev), u back to the list, e archive, # delete, ! spam, s star,
   Shift+I / Shift+U read / unread, v move, l label, b snooze, r / a / f reply, reply all, forward (slice 4 — when built), g then i the
   Inbox, ? the list of shortcuts (a page section, not a modal). Off when the page says data-keys="off" (the mailbox's setting), and
   ignored while focus is in a field. Each key presses the matching control, so the page's own forms do the work. */
(function () {
    'use strict';
    var pendingG = 0;
    function root() { return document.getElementById('mail-thread'); }
    function inField(el) {
        if (!el) return false;
        var t = (el.tagName || '').toLowerCase();
        return t === 'input' || t === 'textarea' || t === 'select' || el.isContentEditable;
    }
    function press(id) {
        var r = root();
        var el = r && r.querySelector('#' + id);
        if (!el) return false;
        if (el.tagName === 'FORM') { if (window.htmx) window.htmx.trigger(el, 'submit'); else el.requestSubmit(); return true; }
        if (el.tagName === 'DETAILS') { el.open = !el.open; return true; }
        el.click();
        return true;
    }
    function go(attr) {
        var r = root();
        var url = r && r.getAttribute(attr);
        if (!url) return false;
        window.location.href = url;                      // a whole page: the next conversation marks itself read as an open does
        return true;
    }
    document.addEventListener('keydown', function (e) {
        var r = root();
        if (!r || r.getAttribute('data-keys') === 'off' || e.ctrlKey || e.metaKey || e.altKey || inField(e.target)) return;
        var k = e.key;
        if (pendingG && Date.now() - pendingG < 1500) {
            pendingG = 0;
            if (k === 'i') { e.preventDefault(); go('data-inbox'); return; }
        }
        var handled = true;
        switch (k) {
            case 'j': go('data-next'); break;
            case 'k': go('data-prev'); break;
            case 'u': go('data-list'); break;
            case 'e': press('act-archive'); break;
            case '#': press('act-trash'); break;
            case '!': press('act-spam'); break;
            case 's': press('act-star'); break;
            case 'I': press('act-read'); break;
            case 'U': press('act-unread'); break;
            case 'v': press('act-move'); break;
            case 'l': press('act-label'); break;
            case 'b': press('act-snooze'); break;
            case 'r': press('act-reply'); break;
            case 'a': press('act-reply-all'); break;
            case 'f': press('act-forward'); break;
            case 'g': pendingG = Date.now(); break;
            case '?': press('mail-shortcuts'); break;
            default: handled = false;
        }
        if (handled) e.preventDefault();
    });
})();
