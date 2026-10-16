/* policy.js — the agent-policy page asks first when a change WIDENS what leaves unseen (oversight.md decision 5): a send mode other than
   hold_all, a higher cap, first contact or attachments review switched off, a longer allow-list. The form's hx-confirm is set to a
   sentence naming what widens, and removed when nothing does; narrowing never asks. Without this file the form saves as it is. */
(function () {
    'use strict';
    var form = document.getElementById('policy-form');
    if (!form) return;
    var RANK = { hold_all: 0, internal_auto: 1, allowlist_auto: 2, auto: 3 };
    function val(id) { var el = document.getElementById('policy-' + id); return el ? el : null; }
    function lines(t) { return (t || '').split(/[\s,;]+/).filter(function (x) { return x !== ''; }); }
    function widens() {
        var w = [], el;
        if ((el = val('send_mode')) && RANK[el.value] > RANK[el.getAttribute('data-current')]) w.push('the send mode becomes ' + el.value.replace(/_/g, ' '));
        ['max_external_per_day', 'max_per_hour', 'max_per_thread_per_day'].forEach(function (k) {
            var e = val(k); if (e && parseInt(e.value, 10) > parseInt(e.getAttribute('data-current'), 10)) w.push('a higher cap (' + k.replace(/_/g, ' ') + ')');
        });
        ['first_contact_review', 'attachments_review'].forEach(function (k) {
            var e = val(k); if (e && !e.checked && e.getAttribute('data-current') === 'yes') w.push(k.replace(/_/g, ' ') + ' off');
        });
        ['allow_domains', 'allow_addresses'].forEach(function (k) {
            var e = val(k); if (!e) return; var cur = lines(e.getAttribute('data-current'));
            if (lines(e.value).some(function (x) { return cur.indexOf(x.toLowerCase()) < 0; })) w.push('more on the allow-list');
        });
        return w;
    }
    function update() {
        var w = widens();
        if (w.length) form.setAttribute('hx-confirm', 'This lets more of the agent\'s mail leave unseen: ' + w.join('; ') + '. Save?');
        else form.removeAttribute('hx-confirm');
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
})();
