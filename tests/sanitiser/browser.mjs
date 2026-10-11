// The sanitiser in a real browser (design §6.4; research 04 Part A) — headless Chromium through Playwright from the kernel's
// web/node_modules (read only), every request answered by a route on a made-up origin: nothing reaches the network. Three layers:
//
//   0. CONTROL — each RAW payload in a bare page. Counts how many run script (a dialog, or any script compiled — the DevTools
//      protocol's Debugger.scriptParsed, which also sees an inline handler when it fires). Proves the corpus is live and the
//      detector sees it; not a pass/fail on the payloads.
//   1. THE SANITISER — each sanitised output in the same bare page, with no CSP and no sandbox to help: no script compiled, no
//      dialog, no request but the page and the image URLs the sanitiser wrote, no navigation, no popup, and the DOM Chromium
//      builds from the output holds only allowed elements and attributes (the parser-differential check, by the browser's own
//      parser).
//   2. THE RESPONSE — each RAW payload served as design §6.4's body response (its exact Content-Security-Policy, a nonce'd
//      script) inside <iframe sandbox> without allow-same-origin, as if the sanitiser had let everything through: no payload
//      script runs (a nonce'd hook in the head turns alert/confirm/prompt/print into a message to the parent; a control proves
//      the hook reports), nothing loads from another origin.
//
// argv[2]: the JSON check.py wrote. Prints ok/FAIL per check; exits non-zero on any failure.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const ORIGIN = 'http://ml.test';
const NONCE = 'c2FuaXRpc2VyLXByb29m';
const CSP = "sandbox allow-popups allow-popups-to-escape-sandbox allow-scripts; default-src 'none'; script-src 'nonce-" + NONCE
  + "'; img-src 'self' data:; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self'";
const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
const HTML = 'text/html; charset=utf-8';
let passed = 0, failed = 0;
const ok = (c, l, detail = '') => { if (c) passed++; else failed++; console.log((c ? 'ok   ' : 'FAIL ') + l + (c || !detail ? '' : ' — ' + detail)); };
const page = (body) => '<!doctype html><html><head><meta charset="utf-8"></head><body>' + body + '</body></html>';
const timeout = (p, ms) => Promise.race([p, new Promise((r) => setTimeout(() => r('timeout'), ms))]);

const browser = await chromium.launch();
const context = await browser.newContext({ javaScriptEnabled: true });

// One visit: a fresh page, every request through `serve(url)` (null → 404 and recorded as unexpected), the signals collected.
async function visit(url, serve, { debug = false, report = false, waitMs = 250 } = {}) {
  const p = await context.newPage();
  const ev = { dialogs: 0, scripts: 0, parsed: [], requests: [], unexpected: [], offsite: [], popups: 0, navigations: 0, messages: [] };
  p.on('dialog', (d) => { ev.dialogs++; d.dismiss().catch(() => {}); });
  p.on('popup', (pp) => { ev.popups++; pp.close().catch(() => {}); });
  p.on('framenavigated', (f) => { if (f === p.mainFrame()) ev.navigations++; });
  if (report) await p.exposeBinding('__report', (_src, m) => { ev.messages.push(m); });
  let cdp = null;
  if (debug) {
    cdp = await context.newCDPSession(p);
    // the page's own worlds only — Playwright's utility world is an isolated one
    cdp.on('Debugger.scriptParsed', (e) => { if (e.executionContextAuxData?.isDefault !== false && !e.url.includes('playwright')) ev.parsed.push(e.url || '(inline)'); });
    await cdp.send('Debugger.enable');
  }
  await p.route('**/*', (route) => {
    const u = new URL(route.request().url());
    if (u.origin !== ORIGIN) { ev.offsite.push(u.href.slice(0, 80)); return route.abort(); }
    ev.requests.push(u.pathname + u.search);
    const r = serve(u);
    if (!r) { ev.unexpected.push(u.pathname + u.search); return route.fulfill({ status: 404, body: '' }); }
    return route.fulfill(r);
  });
  const nav = await timeout(p.goto(ORIGIN + url, { waitUntil: 'load', timeout: 4000 }).catch((e) => 'error'), 5000);
  if (nav === 'timeout') ev.timedOut = true;
  await p.waitForTimeout(waitMs);
  ev.scripts = ev.parsed.length;                                         // before anything of ours is evaluated
  return { p, ev, cdp };
}
async function close(v) { await timeout(v.p.close().catch(() => {}), 3000); }

// The DOM Chromium built from a sanitised body, walked against the allow-lists and rules of its own.
async function domProblems(p, images) {
  return await timeout(p.evaluate(({ tags, generic, attributes, images }) => {
    const out = [];
    for (const el of document.body.querySelectorAll('*')) {
      const t = el.localName;
      if (el.namespaceURI !== 'http://www.w3.org/1999/xhtml') out.push('namespace ' + t);
      if (!tags.includes(t)) out.push('element ' + t);
      for (const a of el.attributes) {
        const allowed = generic.includes(a.name) || (attributes[t] || []).includes(a.name) || (t === 'a' && (a.name === 'target' || a.name === 'rel'));
        if (a.name.startsWith('on') || !allowed) out.push('attribute ' + t + '[' + a.name + ']');
      }
      if (t === 'a' && el.hasAttribute('href')) {
        if (!['http:', 'https:', 'mailto:'].includes(new URL(el.href).protocol)) out.push('href ' + el.getAttribute('href').slice(0, 40));
        if (el.target !== '_blank' || !/noopener/.test(el.rel) || !/noreferrer/.test(el.rel)) out.push('link opens in place');
      }
      if (t === 'img') {
        const s = el.getAttribute('src') || '';
        if (!images.includes(s) && !/^data:image\/(png|gif|jpeg|jpg|webp)[;,]/i.test(s)) out.push('img ' + s.slice(0, 40));
      }
      const cs = getComputedStyle(el);
      if (cs.position === 'fixed' || cs.position === 'absolute' || cs.position === 'sticky') out.push('positioned ' + t);
      if (cs.backgroundImage !== 'none' || cs.listStyleImage !== 'none' || cs.content.includes('url(')) out.push('css loads in ' + t);
    }
    if (document.querySelectorAll('script,style,link,meta:not(head > meta),base,iframe,frame,object,embed,form,input,button,svg,math,template,noscript').length) out.push('a forbidden element');
    if (document.head.children.length !== 1) out.push('the head holds ' + document.head.children.length + ' elements');
    return out;
  }, { tags: data.tags, generic: data.generic, attributes: data.attributes, images }), 3000);
}

const dompurify = data.cases.filter((c) => c.id.startsWith('d'));
const everything = data.cases;

// ---- 0. control ----------------------------------------------------------------------------------------------------------------
let ran = 0;
for (const c of dompurify) {
  const v = await visit('/raw/' + c.id, (u) => (u.pathname === '/raw/' + c.id ? { status: 200, contentType: HTML, body: page(c.raw) } : { status: 404, body: '' }), { debug: true });
  if (v.ev.scripts > 0 || v.ev.dialogs > 0) ran++;
  await close(v);
}
console.log(`     control: ${ran} of ${dompurify.length} raw payloads run script in an unprotected page`);
ok(ran > 0, `the detector sees script run: ${ran} raw payloads did, unprotected`);

// ---- 1. the sanitiser ----------------------------------------------------------------------------------------------------------
{ // the walk's own control: a body breaking the rules without any script must be caught on every count
  const bad = '<svg></svg><p data-x="1" style="position:fixed">p</p><a href="ftp://files.example/" target="_blank" rel="noopener noreferrer">f</a>';
  const v = await visit('/case/walk-control', (u) => (u.pathname === '/case/walk-control' ? { status: 200, contentType: HTML, body: page(bad) } : null));
  const dom = await domProblems(v.p, []);
  ok(Array.isArray(dom) && ['namespace svg', 'element svg', 'attribute p[data-x]', 'positioned p', 'href ftp:', 'a forbidden element'].every((x) => dom.some((d) => d.startsWith(x))),
    'the DOM walk catches a foreign element, a stray attribute, a fixed position and a link scheme', JSON.stringify(dom));
  await close(v);
}
const l1 = { scripts: [], dialogs: [], unexpected: [], offsite: [], navigations: [], popups: [], dom: [], timeouts: [] };
for (const c of everything) {
  const v = await visit('/case/' + c.id, (u) => {
    if (u.pathname === '/case/' + c.id) return { status: 200, contentType: HTML, body: page(c.clean) };
    if (c.images.includes(u.pathname + u.search)) return { status: 200, contentType: 'image/gif', body: GIF };
    return null;
  }, { debug: true });
  const dom = await domProblems(v.p, c.images);
  if (v.ev.scripts) l1.scripts.push(c.id);
  if (v.ev.dialogs) l1.dialogs.push(c.id);
  if (v.ev.unexpected.length) l1.unexpected.push(c.id + ' ' + v.ev.unexpected[0]);
  if (v.ev.offsite.length) l1.offsite.push(c.id + ' ' + v.ev.offsite[0]);
  if (v.ev.navigations !== 1) l1.navigations.push(c.id + ' (' + v.ev.navigations + ')');
  if (v.ev.popups) l1.popups.push(c.id);
  if (dom === 'timeout' || v.ev.timedOut) l1.timeouts.push(c.id);
  else if (dom.length) l1.dom.push(c.id + ' ' + dom.slice(0, 2).join(', '));
  await close(v);
}
const n1 = everything.length;
ok(!l1.scripts.length, `sanitised: no script compiled in any of ${n1} bodies`, l1.scripts.slice(0, 8).join(', '));
ok(!l1.dialogs.length, `sanitised: no dialog in any of ${n1}`, l1.dialogs.slice(0, 8).join(', '));
ok(!l1.unexpected.length && !l1.offsite.length, `sanitised: no request but the page and the image URLs the sanitiser wrote, in any of ${n1}`,
  [...l1.unexpected, ...l1.offsite].slice(0, 5).join('; '));
ok(!l1.navigations.length && !l1.popups.length, `sanitised: no navigation and no popup in any of ${n1}`, [...l1.navigations, ...l1.popups].slice(0, 5).join('; '));
ok(!l1.dom.length && !l1.timeouts.length, `sanitised: the DOM Chromium builds holds only allowed elements and attributes, nothing positioned or loading through CSS, in all ${n1}`,
  [...l1.dom, ...l1.timeouts.map((t) => t + ' timed out')].slice(0, 5).join('; '));

// ---- 2. the response: raw payloads behind the CSP and the sandbox -------------------------------------------------------------
const hook = '<script nonce="' + NONCE + '">for (const f of ["alert", "confirm", "prompt", "print"]) window[f] = () => parent.postMessage("executed:" + f, "*");'
  + 'document.addEventListener("securitypolicyviolation", (e) => parent.postMessage("csp:" + e.effectiveDirective, "*"));</script>';
const height = '<script nonce="' + NONCE + '">parent.postMessage("height:" + document.documentElement.scrollHeight, "*")</script>';
const frame = (id) => page('<iframe sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts" src="/body/' + id + '"></iframe>'
  + '<script>addEventListener("message", (e) => window.__report(String(e.data)))</script>');
const response = (body) => ({ status: 200, contentType: HTML, headers: { 'Content-Security-Policy': CSP, 'X-Content-Type-Options': 'nosniff' },
  body: '<!doctype html><html><head><meta charset="utf-8">' + hook + '</head><body>' + body + height + '</body></html>' });
async function framed(id, body) {
  const v = await visit('/frame/' + id, (u) => {
    if (u.pathname === '/frame/' + id) return { status: 200, contentType: HTML, body: frame(id) };
    if (u.pathname === '/body/' + id) return response(body);
    return { status: 404, body: '' };                                     // 'self' images the policy admits: answered, counted
  }, { report: true, waitMs: 300 });
  const r = { executed: v.ev.messages.filter((m) => m.startsWith('executed:')), csp: v.ev.messages.filter((m) => m.startsWith('csp:')).length,
    height: v.ev.messages.some((m) => m.startsWith('height:')), dialogs: v.ev.dialogs, offsite: v.ev.offsite,
    selfLoads: v.ev.requests.filter((q) => !q.startsWith('/frame/') && !q.startsWith('/body/')).length };
  await close(v);
  return r;
}
const control = await framed('control', '<p>control</p><script nonce="' + NONCE + '">alert("control")</script>');
ok(control.executed.length === 1 && control.height, 'framed: the control — a nonce\'d script calling alert — is reported, and the height script runs');
const l2 = { executed: [], dialogs: [], offsite: [] };
let csp = 0, heights = 0, selfLoads = 0;
for (const c of dompurify) {
  const r = await framed(c.id, c.raw);
  if (r.executed.length) l2.executed.push(c.id + ' ' + r.executed[0]);
  if (r.dialogs) l2.dialogs.push(c.id);
  if (r.offsite.length) l2.offsite.push(c.id + ' ' + r.offsite[0]);
  if (r.csp) csp++;
  if (r.height) heights++;
  selfLoads += r.selfLoads;
}
ok(!l2.executed.length && !l2.dialogs.length, `framed: no raw payload's script runs behind the CSP and the sandbox, in any of ${dompurify.length}`,
  [...l2.executed, ...l2.dialogs].slice(0, 8).join('; '));
ok(!l2.offsite.length, `framed: nothing loads from another origin, in any of ${dompurify.length}`, l2.offsite.slice(0, 5).join('; '));
console.log(`     framed: the policy reported a refusal in ${csp} of ${dompurify.length}; the height script ran in ${heights}`
  + ` (a payload that swallows the rest of the document stops it); ${selfLoads} same-origin image request(s) the policy admits (layer one removes them)`);

await browser.close();
console.log(`\n${passed} ok, ${failed} failed`);
process.exit(failed ? 1 : 0);
