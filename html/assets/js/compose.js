/* compose.js — the composer's behaviour (docs/build-specs/writing.md): Squire (vendored 2.4.9) on the editor with the rich/plain toggle,
   recipient chips with address validation (an outside address outlined), autosave by the settings' seconds and on leaving (sendBeacon),
   attachments by the paper-clip, drop and paste (one request each through the draft's files door), Send through fetch → the bar with Undo
   for the undo window, then "Sent — View message"; Schedule… as an inline panel; Discard. Without this file the plain form still sends.
   The identity form reuses the editor for the signature, and the review page's edit (slice 5) for the held message's body. Test hooks:
   window.mlCompose = {draft, version, lastSave}. */
(function () {
    'use strict';
    var root = document.getElementById('mail-compose') || document.getElementById('identity-form-page') || document.getElementById('review-edit-page');
    if (!root) return;
    if (root.id === 'review-edit-page') { reviewEdit(root); return; }
    var isIdentity = root.id === 'identity-form-page';
    var form = document.getElementById(isIdentity ? 'identity-form' : 'compose-form');
    if (!form) return;
    var csrf = (document.querySelector('#csrf-token-meta') || {}).content || root.getAttribute('data-csrf') || '';
    var ADDR = /^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/;
    var editorEl = document.getElementById(isIdentity ? 'identity-signature-editor' : 'compose-editor');
    var textarea = document.getElementById(isIdentity ? 'identity-signature' : 'compose-body');
    var toolbar = root.querySelector('#compose-toolbar');
    var formatInput = document.getElementById('compose-body-format');
    var editor = null;
    var rich = isIdentity ? true : (root.getAttribute('data-format') === 'html');
    window.mlCompose = window.mlCompose || {};

    // ---- the editor -------------------------------------------------------------------------------------------------------------------
    function startEditor() {
        if (editor || !window.Squire || !editorEl) return;
        editorEl.classList.remove('d-none');
        editor = new window.Squire(editorEl, { blockTag: 'div' });
        var initial = editorEl.getAttribute('data-initial-html') || '';
        if (textarea && textarea.value.trim() !== '' && isIdentity) initial = textarea.value;
        editor.setHTML(initial || '<div><br></div>');
        if (toolbar) toolbar.classList.remove('d-none');
        if (!isIdentity) { textarea.classList.add('d-none'); if (formatInput) formatInput.value = 'html'; }
        if (!isIdentity) editor.addEventListener('input', changed);   // the identity form has no draft to save
        editor.addEventListener('pathChange', updateToolbar);
        if (isIdentity) { textarea.classList.add('d-none'); }
        window.mlCompose.editor = editor;
    }
    function stopEditor() {
        if (!editor) return;
        var text = editorToText(editor.getHTML());
        editor.destroy(); editor = null; window.mlCompose.editor = null;
        editorEl.classList.add('d-none'); if (toolbar) toolbar.classList.add('d-none');
        textarea.classList.remove('d-none'); textarea.value = text; if (formatInput) formatInput.value = 'text';
    }
    function editorToText(html) {
        var d = document.createElement('div'); d.innerHTML = html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(div|p|li|blockquote|h[1-6])>/gi, '\n');
        return d.textContent.replace(/\n{3,}/g, '\n\n').trim();
    }
    function updateToolbar() {
        if (!editor || !toolbar) return;
        var path = editor.getPath();
        toolbar.querySelectorAll('[data-cmd]').forEach(function (b) {
            var cmd = b.getAttribute('data-cmd'), on = false;
            if (cmd === 'bold') on = /(^|>)B\b/.test(path) || editor.hasFormat('B');
            else if (cmd === 'italic') on = editor.hasFormat('I');
            else if (cmd === 'underline') on = editor.hasFormat('U');
            b.classList.toggle('active', on);
        });
    }
    if (toolbar) toolbar.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cmd]'); if (!b || !editor) return;
        e.preventDefault();
        var c = b.getAttribute('data-cmd');
        if (c === 'bold') editor.hasFormat('B') ? editor.removeBold() : editor.bold();
        else if (c === 'italic') editor.hasFormat('I') ? editor.removeItalic() : editor.italic();
        else if (c === 'underline') editor.hasFormat('U') ? editor.removeUnderline() : editor.underline();
        else if (c === 'ul') editor.hasFormat('UL') ? editor.removeList() : editor.makeUnorderedList();
        else if (c === 'ol') editor.hasFormat('OL') ? editor.removeList() : editor.makeOrderedList();
        else if (c === 'quote') editor.hasFormat('BLOCKQUOTE') ? editor.decreaseQuoteLevel() : editor.increaseQuoteLevel();
        else if (c === 'clear') editor.removeAllFormatting();
        else if (c === 'link') { var url = window.prompt('Link to (http://…)'); if (url && /^https?:\/\//i.test(url)) editor.makeLink(url); }
        editor.focus(); changed();
    });
    var formatToggle = document.getElementById('compose-format-toggle');
    if (formatToggle) formatToggle.addEventListener('click', function () {
        if (editor) { stopEditor(); rich = false; } else { rich = true; editorEl.setAttribute('data-initial-html', '<div style="white-space:pre-wrap"></div>'); startEditor(); editor.setHTML(textarea.value ? '<div>' + escapeHtml(textarea.value).replace(/\n/g, '<br>') + '</div>' : '<div><br></div>'); }
        formatToggle.title = rich ? 'Plain text' : 'Rich text';
        var remember = form.querySelector('input[name="compose_format_remember"]');
        if (!remember) { remember = document.createElement('input'); remember.type = 'hidden'; remember.name = 'compose_format_remember'; form.appendChild(remember); }
        remember.value = '1';
        changed();
    });
    function escapeHtml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    if (rich) startEditor();
    // the identity form: the signature editor's HTML goes into the textarea on submit
    if (isIdentity) {
        form.addEventListener('submit', function () { if (editor) textarea.value = editor.getHTML(); });
        document.body.addEventListener('htmx:configRequest', function (e) { if (e.detail.elt === form && editor) e.detail.parameters.signature = editor.getHTML(); });
        return;
    }

    // ---- the signature follows From, unless the person edited it ----------------------------------------------------------------------
    var identity = document.getElementById('compose-identity');
    var sigEdited = false;
    if (identity) identity.addEventListener('change', function () {
        var opt = identity.options[identity.selectedIndex]; if (!opt) return;
        if (editor && !sigEdited) {
            var html = editor.getHTML();
            var d = document.createElement('div'); d.innerHTML = html;
            var old = d.querySelector('.mail-signature'); if (old) old.remove();
            var sig = opt.getAttribute('data-signature-html') || '';
            var quote = d.querySelector('.mail-quote-attr, .mail-forwarded');
            var tmp = document.createElement('div'); tmp.innerHTML = sig;
            if (quote && tmp.firstChild) quote.parentNode.insertBefore(tmp.firstChild, quote); else if (tmp.firstChild) d.appendChild(tmp.firstChild);
            editor.setHTML(d.innerHTML);
        } else if (!editor && !sigEdited) {
            var t = textarea.value.replace(/\n\n-- \n[\s\S]*?(?=\n\nOn |\n\n----------|$)/, '');
            textarea.value = t + (opt.getAttribute('data-signature-text') || '');
        }
        changed();
    });

    // ---- recipients as chips ------------------------------------------------------------------------------------------------------------
    var domains = []; try { domains = JSON.parse(root.getAttribute('data-domains') || '[]'); } catch (e) { domains = []; }
    function isInternal(a) { var d = a.split('@')[1] || ''; return domains.indexOf(d.toLowerCase()) >= 0; }
    function renderChips(field) {
        var wrap = document.getElementById('compose-chips-' + field); if (!wrap) return;
        wrap.querySelectorAll('.mail-chip').forEach(function (c) { c.remove(); });
        var input = wrap.querySelector('.mail-chip-input');
        wrap.querySelectorAll('input[data-chip]').forEach(function (h) {
            var chip = document.createElement('span');
            chip.className = 'mail-chip badge rounded-pill fw-normal me-1 mb-1 ' + (isInternal(h.value) ? 'bg-light text-dark border' : 'bg-warning-subtle text-dark border border-warning mail-chip-outside');
            chip.setAttribute('data-address', h.value);
            chip.innerHTML = escapeHtml(h.value) + ' <button type="button" class="btn-close btn-close-sm ms-1 align-middle" aria-label="Remove ' + escapeHtml(h.value) + '"></button>';
            chip.querySelector('button').addEventListener('click', function () { h.remove(); chip.remove(); changed(); });
            wrap.insertBefore(chip, input);
        });
        var count = wrap.querySelectorAll('input[data-chip]').length;
        if (count > 100) { input.setCustomValidity('At most 100 recipients'); } else { input.setCustomValidity(''); }
    }
    function addChip(field, raw) {
        var wrap = document.getElementById('compose-chips-' + field); var input = wrap.querySelector('.mail-chip-input');
        var m = raw.match(/<([^<>]+)>\s*$/); var a = (m ? m[1] : raw).trim().toLowerCase();
        if (!a) return true;
        if (!ADDR.test(a)) { input.classList.add('is-invalid'); return false; }
        input.classList.remove('is-invalid');
        if (wrap.querySelector('input[data-chip][value="' + CSS.escape(a) + '"]')) return true;
        var h = document.createElement('input'); h.type = 'hidden'; h.name = field + '[]'; h.value = a; h.setAttribute('data-chip', '');
        wrap.insertBefore(h, input);
        renderChips(field); changed();
        return true;
    }
    function takeTyped(field) {
        var wrap = document.getElementById('compose-chips-' + field); if (!wrap) return true;
        var input = wrap.querySelector('.mail-chip-input'); var ok = true;
        input.value.split(/[,;\s]+/).forEach(function (p) { if (p.trim() && !addChip(field, p)) ok = false; });
        if (ok) input.value = '';
        return ok;
    }
    ['to', 'cc', 'bcc'].forEach(function (f) {
        var wrap = document.getElementById('compose-chips-' + f); if (!wrap) return;
        var input = wrap.querySelector('.mail-chip-input');
        input.setAttribute('name', f + '_typed');
        renderChips(f);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') { if (input.value.trim()) { e.preventDefault(); takeTyped(f); } }
            if (e.key === 'Backspace' && !input.value) { var last = wrap.querySelector('input[data-chip]:last-of-type'); if (last) { last.remove(); renderChips(f); changed(); } }
        });
        input.addEventListener('blur', function () { takeTyped(f); });
        // a phone's keyboard sends no key for a comma (keydown is 'Unidentified'): the value itself is read on every input
        input.addEventListener('input', function () { if (/[,;]/.test(input.value)) takeTyped(f); });
        input.addEventListener('paste', function (e) { var t = (e.clipboardData || window.clipboardData).getData('text'); if (t && /[,;\s]/.test(t.trim())) { e.preventDefault(); t.split(/[,;\s]+/).forEach(function (p) { addChip(f, p); }); } });
    });
    var showCc = document.getElementById('compose-show-cc'), showBcc = document.getElementById('compose-show-bcc');
    if (showCc) showCc.addEventListener('click', function (e) { e.preventDefault(); document.getElementById('compose-row-cc').classList.remove('d-none'); document.getElementById('compose-cc').focus(); });
    if (showBcc) showBcc.addEventListener('click', function (e) { e.preventDefault(); document.getElementById('compose-row-bcc').classList.remove('d-none'); document.getElementById('compose-bcc').focus(); });

    // ---- recipient suggestions (slice 8: GET /m/{mailbox}/complete) ----------------------------------------------------------------------
    // After two characters, 150 ms after the last key; at most eight rows; arrows move, Enter takes, Escape closes. The door logs nothing.
    ['to', 'cc', 'bcc'].forEach(function (f) {
        var wrap = document.getElementById('compose-chips-' + f); if (!wrap) return;
        var input = wrap.querySelector('.mail-chip-input');
        var list = document.createElement('ul');
        list.className = 'list-group position-absolute shadow-sm d-none mail-suggest'; list.id = 'compose-suggest-' + f; list.setAttribute('role', 'listbox');
        list.style.zIndex = '1050'; list.style.maxWidth = '100%'; list.style.minWidth = '14rem'; list.style.top = '100%'; list.style.left = '0';
        wrap.style.position = 'relative'; wrap.appendChild(list);
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-controls', list.id); input.setAttribute('aria-expanded', 'false');
        var timer = null, active = -1, seq = 0;
        function items() { return list.querySelectorAll('li[data-address]'); }
        function hide() { list.classList.add('d-none'); list.innerHTML = ''; active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
        function mark(i) {
            var li = items(); active = li.length ? (i + li.length) % li.length : -1;
            li.forEach(function (x, k) { x.classList.toggle('active', k === active); x.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
            if (active >= 0) input.setAttribute('aria-activedescendant', li[active].id); else input.removeAttribute('aria-activedescendant');
        }
        function pick(li) { addChip(f, li.getAttribute('data-address')); input.value = ''; hide(); input.focus(); }
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2 || /[,;]/.test(q)) { hide(); return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                fetch('/m/' + root.getAttribute('data-mailbox') + '/complete?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                    .then(function (r) { return r.ok ? r.text() : ''; })
                    .then(function (html) {
                        if (mine !== seq || input.value.trim() !== q) return;
                        list.innerHTML = html;
                        if (items().length) { list.classList.remove('d-none'); input.setAttribute("aria-expanded", "true"); active = -1; } else { hide(); }
                    }).catch(function () { hide(); });
            }, 150);
        });
        // in the capture phase, so an Enter that takes a suggestion never reaches the chip handler that would take the typed text
        input.addEventListener('keydown', function (e) {
            var open = !list.classList.contains('d-none');
            if (open && e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
            else if (open && e.key === 'ArrowUp') { e.preventDefault(); mark(active < 0 ? -1 : active - 1); }
            else if (open && e.key === 'Enter' && active >= 0) { e.preventDefault(); e.stopImmediatePropagation(); pick(items()[active]); }
            else if (open && e.key === 'Escape') { e.preventDefault(); hide(); }
        }, true);
        list.addEventListener('mousedown', function (e) { e.preventDefault(); });
        list.addEventListener('click', function (e) { var li = e.target.closest('li[data-address]'); if (li) pick(li); });
        input.addEventListener('blur', function () { setTimeout(hide, 100); });
    });

    // ---- autosave ---------------------------------------------------------------------------------------------------------------------
    var status = document.getElementById('compose-status');
    var saveSecs = parseInt(root.getAttribute('data-save-seconds') || '10', 10);
    var timer = null, dirty = false, saving = false, sent = false;
    var draftInput = document.getElementById('compose-draft-id'), versionInput = document.getElementById('compose-version');
    function setStatus(t) { if (status) status.textContent = t; }
    function changed() {
        if (sent) return;
        dirty = true; sigEdited = sigEdited || !!(editor && document.activeElement === editorEl && editorEl.querySelector('.mail-signature') && editorEl.querySelector('.mail-signature').contains(window.getSelection().anchorNode));
        clearTimeout(timer); timer = setTimeout(function () { save(false); }, saveSecs * 1000);
        setStatus('Unsaved changes');
    }
    form.addEventListener('input', function (e) { if (e.target.closest('#compose-schedule')) return; changed(); });
    function fields(extra) {
        var fd = new FormData();
        fd.append('csrf_token', csrf); fd.append('mailbox', root.getAttribute('data-mailbox'));
        if (draftInput.value) fd.append('draft', draftInput.value);
        if (versionInput.value) fd.append('version', versionInput.value);
        var ident = document.getElementById('compose-identity'); if (ident && ident.value) fd.append('identity', ident.value);
        ['to', 'cc', 'bcc'].forEach(function (f) { takeTyped(f); form.querySelectorAll('#compose-chips-' + f + ' input[data-chip]').forEach(function (h) { fd.append(f + '[]', h.value); }); });
        fd.append('subject', document.getElementById('compose-subject').value);
        fd.append('body_format', editor ? 'html' : 'text');
        fd.append('body', editor ? editor.getHTML() : textarea.value);
        fd.append('mode', form.querySelector('input[name="mode"]').value);
        var rt = form.querySelector('input[name="reply_to"]'); if (rt) fd.append('reply_to', rt.value);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fd;
    }
    function save(closing) {
        if (saving || sent) return Promise.resolve();
        if (!dirty && draftInput.value) return Promise.resolve();
        saving = true; setStatus('Saving…');
        var fd = fields(closing ? { close: '1' } : {});
        if (closing && navigator.sendBeacon) { dirty = false; saving = false; navigator.sendBeacon(form.getAttribute('data-draft-url'), fd); return Promise.resolve(); }
        return fetch(form.getAttribute('data-draft-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); }).then(function (res) {
            saving = false;
            if (res.status === 409) { setStatus('A newer version was saved elsewhere — '); var a = document.createElement('a'); a.href = '/m/' + root.getAttribute('data-mailbox') + '/drafts/' + res.j.draft; a.textContent = 'Open it'; status.appendChild(a); dirty = false; return; }
            if (!res.j.ok) { setStatus(res.j.error && res.j.error.message ? res.j.error.message : 'Not saved'); return; }
            dirty = false;
            if (res.j.draft) { draftInput.value = res.j.draft; root.setAttribute('data-draft', res.j.draft); }
            if (res.j.version) versionInput.value = res.j.version;
            window.mlCompose.draft = res.j.draft; window.mlCompose.version = res.j.version; window.mlCompose.lastSave = Date.now();
            setStatus(res.j.filed ? 'Saved to Drafts' : 'Saved');
            document.dispatchEvent(new CustomEvent('ml:draftSaved', { detail: res.j }));
        }).catch(function () { saving = false; setStatus('Saved here; it will reach your Drafts folder when the mail service is back'); });
    }
    window.addEventListener('beforeunload', function () { if (dirty && !sent) save(true); });
    window.mlCompose.save = save;

    // ---- attachments ------------------------------------------------------------------------------------------------------------------
    var fileInput = document.getElementById('compose-file');
    function ensureDraft() { if (draftInput.value) return Promise.resolve(); dirty = true; return save(false); }
    function upload(file) {
        return ensureDraft().then(function () {
            if (!draftInput.value) { setStatus('Could not start the draft'); return; }
            var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('mailbox', root.getAttribute('data-mailbox')); fd.append('draft', draftInput.value); fd.append('file', file, file.name);
            setStatus('Attaching ' + file.name + '…');
            return fetch(form.getAttribute('data-files-url').replace('{draft}', draftInput.value), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'HX-Request': 'true' } })
                .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); })
                .then(function (res) {
                    if (!res.ok) { var d = document.createElement('div'); d.innerHTML = res.t; setStatus(d.textContent.trim() || 'Not attached'); return; }
                    document.getElementById('compose-attachments').innerHTML = res.t; setStatus('Attached ' + file.name);
                    document.dispatchEvent(new CustomEvent('ml:attached'));
                });
        });
    }
    if (fileInput) fileInput.addEventListener('change', function () { Array.prototype.slice.call(fileInput.files).reduce(function (p, f) { return p.then(function () { return upload(f); }); }, Promise.resolve()).then(function () { fileInput.value = ''; }); });
    form.addEventListener('dragover', function (e) { e.preventDefault(); form.classList.add('mail-dropping'); });
    form.addEventListener('dragleave', function () { form.classList.remove('mail-dropping'); });
    form.addEventListener('drop', function (e) { e.preventDefault(); form.classList.remove('mail-dropping'); Array.prototype.slice.call(e.dataTransfer.files).reduce(function (p, f) { return p.then(function () { return upload(f); }); }, Promise.resolve()); });
    document.addEventListener('paste', function (e) {
        if (!form.contains(e.target) && e.target !== document.body) return;
        var items = (e.clipboardData || {}).items || []; var files = [];
        for (var i = 0; i < items.length; i++) if (items[i].kind === 'file') files.push(items[i].getAsFile());
        if (files.length) { e.preventDefault(); setStatus('A pasted image becomes an attachment'); files.reduce(function (p, f) { return p.then(function () { return upload(f); }); }, Promise.resolve()); }
    });
    document.getElementById('compose-attachments').addEventListener('click', function (e) {
        var b = e.target.closest('.mail-attachment-remove'); if (!b) return;
        var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('mailbox', root.getAttribute('data-mailbox')); fd.append('draft', draftInput.value);
        fetch(b.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'HX-Request': 'true' } }).then(function (r) { return r.text(); }).then(function (t) { document.getElementById('compose-attachments').innerHTML = t; });
    });

    // ---- the sticky bar sits above whatever is fixed at the bottom (the tab bar, the command bar), never under it ----------------------
    var composeBar = document.getElementById('compose-bar');
    function placeBar() {
        if (!composeBar) return; var h = 0;
        ['shell-tabbar', 'assistant-bar'].forEach(function (id) {
            var el = document.getElementById(id); if (!el || !el.getClientRects().length) return;
            var st = getComputedStyle(el); if (st.position !== 'fixed' || st.display === 'none' || st.visibility === 'hidden') return;
            h = Math.max(h, window.innerHeight - el.getBoundingClientRect().top);
        });
        composeBar.style.bottom = Math.max(0, Math.round(h)) + 'px';
        composeBar.classList.add('mail-compose-bar-stuck');
    }
    placeBar(); window.addEventListener('resize', placeBar);
    // ---- schedule ---------------------------------------------------------------------------------------------------------------------
    var panel = document.getElementById('compose-schedule'), sendAt = document.getElementById('compose-send-at');
    var toggle = document.getElementById('compose-schedule-toggle');
    if (toggle) toggle.addEventListener('click', function () { panel.classList.toggle('d-none'); toggle.setAttribute('aria-expanded', panel.classList.contains('d-none') ? 'false' : 'true'); if (!panel.classList.contains('d-none')) panel.scrollIntoView({ block: 'center' }); });
    var closeBtn = document.getElementById('compose-schedule-close'); if (closeBtn) closeBtn.addEventListener('click', function () { panel.classList.add('d-none'); });
    if (panel) panel.addEventListener('click', function (e) {
        var c = e.target.closest('.mail-schedule-choice'); if (c) { sendAt.value = c.getAttribute('data-at'); submitSend(); return; }
        if (e.target.id === 'compose-schedule-send') { var v = document.getElementById('compose-schedule-at').value; if (!v) { setStatus('Pick a date and time'); return; } sendAt.value = v; submitSend(); }
    });

    // ---- send -------------------------------------------------------------------------------------------------------------------------
    var bar = document.getElementById('compose-sent-bar'), barText = document.getElementById('compose-sent-text'), undoBtn = document.getElementById('compose-undo'), viewLink = document.getElementById('compose-view-message');
    var sendBtn = document.getElementById('compose-send');
    var outbound = null, countdown = null, poll = null;
    form.addEventListener('submit', function (e) { e.preventDefault(); submitSend(); });
    function submitSend() {
        if (sent) return;
        var ok = ['to', 'cc', 'bcc'].every(function (f) { return takeTyped(f); });
        if (!ok) { setStatus('Check the address in red'); return; }
        clearTimeout(timer);
        var fd = fields({ send_at: sendAt.value || '', quote: 'no' });
        var remember = form.querySelector('input[name="compose_format_remember"]'); if (remember) fd.append('compose_format_remember', '1');
        if (sendBtn) sendBtn.disabled = true;
        setStatus('Sending…');
        fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
            .then(function (res) {
                var j = res.j;
                if (!j.ok) { if (sendBtn) sendBtn.disabled = false; setStatus(j.error && j.error.message ? j.error.message : 'Not sent'); return; }
                sent = true; dirty = false; outbound = j.record_id; sendAt.value = '';
                bar.classList.remove('d-none'); bar.setAttribute('data-outbound', outbound);
                if (j.status === 'queued' && j.seconds_left > 0) {
                    var left = j.seconds_left;
                    barText.textContent = 'Sending in ' + left + ' s'; undoBtn.classList.remove('d-none');
                    countdown = setInterval(function () { left--; if (left <= 0) { clearInterval(countdown); undoBtn.classList.add('d-none'); barText.textContent = 'Sending…'; startPoll(); } else barText.textContent = 'Sending in ' + left + ' s'; }, 1000);
                } else if (j.status === 'scheduled') {
                    barText.textContent = 'Scheduled for ' + (j.send_after_local || j.send_after); undoBtn.textContent = 'Cancel'; undoBtn.classList.remove('d-none');
                } else if (j.status === 'held') {
                    barText.textContent = j.did;
                } else if (j.status === 'refused') {
                    barText.textContent = j.did;
                } else { barText.textContent = j.did || 'Sent'; startPoll(); }
                setStatus(''); document.dispatchEvent(new CustomEvent('ml:sent', { detail: j }));
            }).catch(function () { if (sendBtn) sendBtn.disabled = false; setStatus('Not sent: the server did not answer'); });
    }
    function startPoll() {
        clearInterval(poll);
        poll = setInterval(function () {
            fetch(bar.getAttribute('data-status-url') + outbound, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
                var d = j.data || {};
                if (d.status === 'sent') { clearInterval(poll); barText.textContent = 'Sent'; if (d.thread_id) { viewLink.href = '/m/' + root.getAttribute('data-mailbox') + '/t/' + d.thread_id; viewLink.classList.remove('d-none'); } document.dispatchEvent(new CustomEvent('ml:delivered', { detail: d })); }
                else if (d.status === 'failed') { clearInterval(poll); barText.textContent = 'Not sent: ' + (d.error || 'failed'); }
                else if (d.status === 'deferred') { barText.textContent = 'MaluMail asked us to wait; trying again'; }
                else if (d.status === 'held') { clearInterval(poll); barText.textContent = 'Held for review: ' + (d.hold_reasons || []).join(', '); }
            }).catch(function () {});
        }, 2000);
    }
    if (undoBtn) undoBtn.addEventListener('click', function () {
        var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('mailbox', root.getAttribute('data-mailbox')); fd.append('outbound', outbound);
        fetch(bar.getAttribute('data-cancel-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) { barText.textContent = j.error && j.error.message ? j.error.message : 'Too late to undo'; undoBtn.classList.add('d-none'); return; }
            clearInterval(countdown); clearInterval(poll);
            if (j.draft) { window.location.href = '/m/' + root.getAttribute('data-mailbox') + '/drafts/' + j.draft; } else { bar.classList.add('d-none'); sent = false; if (sendBtn) sendBtn.disabled = false; }
        });
    });
    var discard = document.getElementById('compose-discard');
    if (discard) discard.addEventListener('click', function () {
        if (!draftInput.value) { window.location.href = '/m/' + root.getAttribute('data-mailbox') + '/v/inbox'; return; }
        if (!window.confirm('Discard this draft?')) return;
        var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('mailbox', root.getAttribute('data-mailbox')); fd.append('draft', draftInput.value);
        sent = true; dirty = false;
        fetch(discard.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function () { window.location.href = '/m/' + root.getAttribute('data-mailbox') + '/v/inbox'; });
    });

    // ---- the review page's edit (oversight.md review-edit): the held message's body in the same editor; HTML posted with body_format html ----
    function reviewEdit(page) {
        var form = document.getElementById('review-edit-form'), el = document.getElementById('review-edit-editor'), ta = document.getElementById('review-edit-body');
        var fmt = document.getElementById('review-edit-format'), bar = page.querySelector('#compose-toolbar'), ed = null;
        if (!form || !el || !ta || !window.Squire) return;
        el.classList.remove('d-none'); ta.classList.add('d-none'); if (bar) bar.classList.remove('d-none');
        ed = new window.Squire(el, { blockTag: 'div' });
        var html = el.getAttribute('data-initial-html') || '';
        if (html === '') { var d = document.createElement('div'); d.textContent = ta.value; html = '<div style="white-space:pre-wrap">' + d.innerHTML + '</div>'; }
        ed.setHTML(html);
        fmt.value = 'html';
        window.mlReviewEdit = ed;
        if (bar) bar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-cmd]'); if (!b) return; e.preventDefault();
            var c = b.getAttribute('data-cmd');
            if (c === 'bold') ed.hasFormat('B') ? ed.removeBold() : ed.bold();
            else if (c === 'italic') ed.hasFormat('I') ? ed.removeItalic() : ed.italic();
            else if (c === 'underline') ed.hasFormat('U') ? ed.removeUnderline() : ed.underline();
            else if (c === 'ul') ed.hasFormat('UL') ? ed.removeList() : ed.makeUnorderedList();
            else if (c === 'ol') ed.hasFormat('OL') ? ed.removeList() : ed.makeOrderedList();
            else if (c === 'quote') ed.hasFormat('BLOCKQUOTE') ? ed.decreaseQuoteLevel() : ed.increaseQuoteLevel();
            else if (c === 'clear') ed.removeAllFormatting();
            else if (c === 'link') { var url = window.prompt('Link to (http://…)'); if (url && /^https?:\/\//i.test(url)) ed.makeLink(url); }
            ed.focus();
        });
        form.addEventListener('submit', function () { ta.value = ed.getHTML(); fmt.value = 'html'; });
        document.body.addEventListener('htmx:configRequest', function (e) { if (e.detail.elt === form) { e.detail.parameters.body = ed.getHTML(); e.detail.parameters.body_format = 'html'; } });
    }
})();
