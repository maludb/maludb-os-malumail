/* frame.js — the conversation page's side of the sandboxed body (docs/build-specs/reading-and-rendering.md §2): each message body is an
   <iframe sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"> with an opaque origin. The ONLY script the body's policy
   admits posts {ml: "h", h: scrollHeight} on load and on each image's load; this listener accepts it only when event.source is one of
   the page's body frames' contentWindow and event.origin is "null" (an opaque origin), and sets that frame's height (capped at 20,000 px;
   beyond, the frame scrolls). Nothing else is ever read from a frame. */
(function () {
    'use strict';
    var CAP = 20000;
    window.addEventListener('message', function (ev) {
        if (ev.origin !== 'null' || !ev.data || ev.data.ml !== 'h') return;
        var frames = document.querySelectorAll('iframe.mail-body-frame');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === ev.source) {
                var h = Math.max(40, Math.min(CAP, parseInt(ev.data.h, 10) || 0));
                frames[i].style.height = h + 'px';
                frames[i].setAttribute('data-sized', h >= CAP ? 'capped' : 'yes');
                return;
            }
        }
    });
})();
/* Show images (reading-and-rendering.md §4): the button reloads ONE frame with ?images=1 — the mail service then sanitises with
   image links through the image door — and takes the banner's button away. Nothing is fetched until the person asks. */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.mail-show-images') : null;
        if (!b) return;
        e.preventDefault();
        var f = document.getElementById(b.getAttribute('data-frame'));
        if (f) { f.src = b.getAttribute('data-src'); }
        b.disabled = true;
        b.textContent = 'Images shown';
    });
})();
