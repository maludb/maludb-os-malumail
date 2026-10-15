// Slice 3 in headless Chromium (reading.md Proof 1, 4; reading-and-rendering.md §7): at 375 x 740 and 1280 x 800 — the conversation page,
// the frame with exactly the three sandbox flags, the height line sizing it (and nothing else running: a DOMPurify payload delivered as
// mail runs no script and loads nothing), earlier messages collapsed and opened in place, the banners for each fixture, Show images
// loading through /img/ with the 1x1 pixel never loaded, the actions as a toolbar at 1280 and Archive · Delete · More at 375, the
// keyboard (e archives, u goes back, ? opens the shortcuts), no sideways scroll, no console error, axe clean. Playwright from the kernel's
// web/node_modules. Run through tests/phase3/slice3/run.sh after reading.php.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s3';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
const CONTROL = process.env.ML_IMAP_CONTROL;
const FIX = `http://127.0.0.1:${process.env.P_IMAGE_FIXTURE || '9208'}`;
const AXE = fs.readFileSync('/var/www/web/node_modules/axe-core/axe.min.js', 'utf8');
const ROOT = new URL('../../../', import.meta.url).pathname;
const fixture = JSON.parse(fs.readFileSync(ROOT + 'bin/dev_directory.json', 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.malumail.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-At', '-d', process.env.ML_DEV_DB, '-c', q]).toString().trim();
const ctl = async (cmd) => {
  const name = crypto.randomBytes(8).toString('hex');
  fs.writeFileSync(`${CONTROL}/${name}.json`, JSON.stringify(cmd));
  for (let i = 0; i < 200 && !fs.existsSync(`${CONTROL}/${name}.done`); i++) await new Promise((r) => setTimeout(r, 50));
  const out = JSON.parse(fs.readFileSync(`${CONTROL}/${name}.done`, 'utf8'));
  fs.rmSync(`${CONTROL}/${name}.done`, { force: true });
  return out;
};
const deliver = async (user, subject, html, headers = {}) => {
  const mid = `<${crypto.randomBytes(8).toString('hex')}@outside.example>`;
  const h = { From: 'Outside Person <someone@outside.example>', ...headers };
  const raw = Object.entries(h).map(([k, v]) => `${k}: ${v}`).join('\r\n') + `\r\nTo: ${user}\r\nSubject: ${subject}\r\nDate: ${new Date().toUTCString()}\r\nMessage-ID: ${mid}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n${html}`;
  await ctl({ op: 'deliver', user, raw });
  const mb = SEED[user.split('@')[0]];
  for (let i = 0; i < 300; i++) {
    const r = sql(`SELECT id || ' ' || thread_id FROM mail_messages WHERE mailbox_id = ${mb} AND message_id_header = '${mid}'`);
    if (r) return r.split(' ').map(Number);
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('not indexed: ' + subject);
};
let failed = 0, passed = 0;
const ok = (c, l, d = '') => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l + (c || !d ? '' : ' — ' + d)); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const visible = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const st = getComputedStyle(e); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none' && r.right > 0 && r.left < window.innerWidth; }, sel);
const axe = async (page) => {
  await page.addScriptTag({ content: AXE });
  return page.evaluate(async () => (await window.axe.run(document.querySelector('#page-content') || document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => `${v.id} (${v.nodes.length})`));
};
const PRIYA = 'priya@business.example';
const P = SEED.priya;
const DMARC_OK = 'default: False [-1.20 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]';
const corpus = (n) => fs.readFileSync(ROOT + 'tests/sanitiser/corpus/benign/' + n, 'utf8');
const dompurify = JSON.parse(execFileSync(ROOT + 'mcp/venv/bin/python', ['-I', '-c', `
import sys, json; sys.path.insert(0, ${JSON.stringify(ROOT + 'tests/sanitiser')}); from read_dompurify import cases; c = cases(${JSON.stringify(ROOT + 'tests/sanitiser/corpus/dompurify/expect.mjs')}); print(json.dumps([x['payload'] for x in c[:40]]))`]).toString());

// the fixtures: one of each banner, the corpus, three DOMPurify payloads as mail
const [m1, t1] = await deliver(PRIYA, 'Notes from the call', corpus('gmail-thread.html'), { From: 'Leo <leo@marchetti.example>', 'X-Spamd-Result': DMARC_OK });
const [m2, t2] = await deliver(PRIYA, 'Your account', '<p>Sign in at <a href="https://evil.example/login">https://bank.example/login</a> now.</p><div style="display:none">hidden words</div>',
  { From: 'SMOKE Priya <priya@phish.example>', 'Reply-To': 'help@elsewhere.example', 'X-Spamd-Result': 'default: False [7.10 / 15.00]; DMARC_POLICY_REJECT(2.00)[phish.example,reject]' });
const [m3, t3] = await deliver(PRIYA, 'With images', `<p>Look:</p><img src="${FIX}/logo.png" width="100" height="100" id="big"><img src="${FIX}/pixel.gif" width="1" height="1">`, { From: 'Chairs <news@chairs.example>', 'X-Spamd-Result': DMARC_OK });
const payloads = [];
for (let i = 0; i < 3; i++) {
  const html = '<p>Hello</p>' + dompurify[i * 7] + '<script>window.__pwned = 1</script><img src=x onerror="window.__pwned=2">';
  payloads.push(await deliver(PRIYA, 'Payload ' + i, html, { From: 'Attacker <a@outside.example>' }));
}
// a reply so the first message collapses
await ctl({ op: 'deliver', user: PRIYA, raw: `From: Leo <leo@marchetti.example>\r\nTo: ${PRIYA}\r\nSubject: Re: Notes from the call\r\nDate: ${new Date().toUTCString()}\r\nMessage-ID: <r-${Date.now()}@m.example>\r\nIn-Reply-To: ${sql(`SELECT message_id_header FROM mail_messages WHERE id = ${m1}`)}\r\nContent-Type: text/plain\r\n\r\nSecond message.\r\n` });
for (let i = 0; i < 100 && sql(`SELECT message_count FROM mail_threads WHERE id = ${t1}`) !== '2'; i++) await new Promise((r) => setTimeout(r, 100));

const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  const requests = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('request', (r) => requests.push(r.url()));
  const frameRequests = [];                                   // what the BODY frames ask for (a payload's loads would be theirs)
  page.on('request', (r) => { const f = r.frame(); if (f && f !== page.mainFrame() && /\/msg\/\d+\/body/.test(f.url())) frameRequests.push(r.url()); });
  page.on('dialog', (d) => { errors.push('dialog: ' + d.message()); d.dismiss().catch(() => {}); });
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);

  await page.goto(`${BASE}/m/${P}/t/${t1}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  ok(await visible(page, '#mail-thread') && await visible(page, `#msg-${m1}`), `the conversation opens (${tag})`);
  ok(await page.locator(`#msg-${m1}.mail-msg-collapsed`).count() === 1, `the earlier message is collapsed to one line (${tag})`);
  await page.click(`#msg-${m1}`);
  await page.waitForSelector(`#frame-${m1}`, { timeout: 5000 });
  const frame = page.locator(`#frame-${m1}`);
  ok(await frame.getAttribute('sandbox') === 'allow-popups allow-popups-to-escape-sandbox allow-scripts', `…opened in place: the frame has exactly the three sandbox flags (${tag})`);
  await page.waitForFunction((id) => (document.getElementById(id)?.getAttribute('data-sized') || '') !== '', m1 ? `frame-${m1}` : '', { timeout: 5000 }).catch(() => {});
  const height = await page.evaluate((id) => document.getElementById(id)?.getBoundingClientRect().height, `frame-${m1}`);
  ok(height > 120 && height < 20000, `the height line sized the frame (${Math.round(height)} px, ${tag})`);
  const inner = page.frameLocator(`#frame-${m1}`);
  ok(await inner.locator('text=Ship the Q4 price list').count() === 1, `the body shows the message (${tag})`);
  ok(await page.evaluate((id) => { const f = document.getElementById(id); try { return f.contentDocument === null; } catch (e) { return true; } }, `frame-${m1}`), `the frame's origin is opaque: the page cannot reach into it (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll (${tag})`);
  if (tag === 'phone') {
    ok(await visible(page, '#mail-actions-phone #act-archive') && !(await visible(page, '#mail-actions-desk #act-archive')) && await visible(page, '#mail-actions-more'), 'at 375: Archive, Delete and More');
    await page.click('#mail-actions-more summary');
    await page.waitForTimeout(200);
    ok(await visible(page, '#mail-actions-phone #act-star'), '…More opens the rest (a <details>, not a modal)');
    await page.click('#mail-actions-more summary');
  } else {
    ok(await visible(page, '#mail-actions-desk #act-archive') && await visible(page, '#mail-actions-desk #act-star') && !(await visible(page, '#mail-actions-more')), 'at 1280: the actions as a toolbar');
    const widthOk = await page.evaluate(() => document.querySelector('#mail-thread').getBoundingClientRect().width <= 900);
    ok(widthOk, 'the column is at most 900 px');
  }
  await page.screenshot({ path: `${SHOTS}/thread-${tag}.png`, fullPage: true });

  // the banners
  await page.goto(`${BASE}/m/${P}/t/${t2}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  for (const b of ['outside', 'mismatch', 'dmarc-fail', 'spam', 'reply-to', 'deceptive', 'hidden']) ok(await visible(page, `[data-banner="${b}"]`), `banner: ${b} (${tag})`);
  ok(await page.locator('[data-banner="mismatch"]').textContent().then((t) => t.includes('This says it is from SMOKE Priya, but the address priya@phish.example is not theirs')), `the mismatch banner names the name and the address (${tag})`);

  // Show images
  await page.goto(`${BASE}/m/${P}/t/${t3}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const before = requests.filter((u) => u.includes('/img/')).length;
  ok(await visible(page, `#msg-${m3}-show-images`) && before === 0, `images not shown by default, nothing fetched through /img/ (${tag})`);
  await page.click(`#msg-${m3}-show-images`);
  await page.waitForTimeout(1500);
  const imgReqs = requests.filter((u) => u.includes('/img/'));
  ok(imgReqs.length === 1 && !requests.some((u) => u.includes('pixel.gif')) && !requests.some((u) => u.includes(FIX)), `Show images: one request through /img/, the pixel never, the origin never reached by the browser (${tag})`);
  const shown = await page.frameLocator(`#frame-${m3}`).locator('img[width="100"]').first().evaluate((i) => i.naturalWidth).catch(() => 0);
  ok(shown === 2, `…the image loaded in the frame (${tag})`);

  // the payloads: nothing runs, nothing loads from elsewhere
  for (const [pm, pt] of payloads) {
    const n0 = errors.length;
    const f0 = frameRequests.length;
    await page.goto(`${BASE}/m/${P}/t/${pt}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    const pwned = await page.frameLocator(`#frame-${pm}`).locator('body').evaluate(() => window.__pwned || 0).catch(() => 0);
    const offsite = frameRequests.slice(f0).filter((u) => !u.startsWith(BASE + '/m/') && !u.startsWith(BASE + '/assets/') && !u.startsWith(BASE + '/p/') && !u.startsWith('data:'));
    ok(pwned === 0 && offsite.length === 0 && errors.length === n0, `a DOMPurify payload delivered as mail runs nothing and loads nothing (message ${pm}, ${tag})`, `pwned=${pwned} offsite=${offsite.slice(0, 3).join(',')} ${errors.slice(n0).join(' | ')}`);
  }

  // the keyboard
  await page.goto(`${BASE}/m/${P}/t/${t2}`, { waitUntil: 'networkidle' });
  await page.keyboard.press('?');
  await page.waitForTimeout(200);
  ok(await page.evaluate(() => document.getElementById('mail-shortcuts').open), `? opens the shortcuts section (${tag})`);
  await page.keyboard.press('u');
  await page.waitForURL(`**/m/${P}/v/inbox`, { timeout: 5000 }).catch(() => {});
  ok(page.url().endsWith(`/m/${P}/v/inbox`), `u goes back to the list (${tag})`);
  await page.goto(`${BASE}/m/${P}/t/${t2}`, { waitUntil: 'networkidle' });
  await page.keyboard.press('e');
  await page.waitForURL('**/v/inbox?notice=archived**', { timeout: 5000 }).catch(() => {});
  ok(page.url().includes('notice=archived') && sql(`SELECT f.special_use FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = ${m2}`) === 'archive', `e archives and lands on the list with the notice (${tag})`);
  ok(await visible(page, '#mail-undo'), `…with Undo (${tag})`);
  await page.click('#mail-undo');
  await page.waitForTimeout(1500);
  ok(sql(`SELECT f.special_use FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = ${m2}`) === 'inbox', `Undo puts it back (${tag})`);

  await page.goto(`${BASE}/m/${P}/t/${t1}`, { waitUntil: 'networkidle' });
  const v = await axe(page);
  ok(v.length === 0, `axe: no WCAG 2 A/AA violation on the conversation (${tag})`, v.join(', '));
  ok(errors.length === 0, `no console error, no dialog (${tag})`, errors.slice(0, 3).join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
