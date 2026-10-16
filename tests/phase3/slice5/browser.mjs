// Slice 5 in headless Chromium (oversight.md Proof 10): every oversight screen at 375 x 740 and 1280 x 800 without sideways scroll; the
// review page's body inside its sandboxed frame — a held message whose HTML tries to run script (written into the row directly, past the
// sanitiser, as the worst case) runs nothing under the body's policy; Pause asks for its reason inline; the policy page asks first when a
// change widens (policy.js → hx-confirm) and not when it narrows; the review edit runs Squire. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s5';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
const HELD = JSON.parse(fs.readFileSync(process.env.ML_DEV_STATE + '/s5-held.json', 'utf8'));
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
let failed = 0, passed = 0;
const ok = (c, l, d = '') => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l + (c || !d ? '' : ' — ' + d)); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const E = SEED.expert;
const evil = HELD.held.attachment;
sql(`UPDATE outbound_email SET body_html = '<p id="evil">Hello</p><img src="x" onerror="parent.postMessage({pwn:1},''*'')"><script>parent.postMessage({pwn:2},''*'');document.title=''pwned''</script><a href="https://evil.example/">bank.example</a>' WHERE id = ${evil}`);
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url()));
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);
  for (const [path, screen] of [['/oversight', 'oversight'], ['/oversight/review', 'review-queue'], [`/oversight/review/${evil}`, 'review-view'],
                                 [`/oversight/review/${HELD.held.over_thread}/edit`, 'review-edit'], ['/oversight/unscreened', 'unscreened'], [`/m/${E}/policy`, 'agent-policy']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
    ok(s === screen && (await overflow(page)) <= 0, `${screen} at ${tag}: no sideways scroll`, `${s} overflow ${await overflow(page)}`);
    await page.screenshot({ path: `${SHOTS}/${screen}-${tag}.png`, fullPage: true });
  }
  // the sandboxed body
  const pwns = [];
  await page.exposeFunction('__mlPwn', (v) => pwns.push(v));
  await page.addInitScript(() => { window.addEventListener('message', (e) => { if (e.data && e.data.pwn) window.__mlPwn(e.data.pwn); }); });
  await page.goto(`${BASE}/oversight/review/${evil}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  const frame = page.frames().find((f) => f.url().includes(`/oversight/review/${evil}/body`));
  const sandbox = await page.getAttribute('#review-body-frame', 'sandbox');
  ok(frame && sandbox && !sandbox.includes('allow-same-origin'), `the body in an <iframe sandbox> without allow-same-origin (${tag})`);
  ok(pwns.length === 0 && (await page.title()) !== 'pwned', `no script in the agent's HTML runs (${tag})`, JSON.stringify(pwns));
  const sized = await page.getAttribute('#review-body-frame', 'style');
  ok(/height:\s*\d+px/.test(sized || ''), `the frame sized by its one admitted script (${tag})`, sized);
  ok(await page.locator('#review-links [data-host="evil.example"]').count() === 1, `the link listed with its real host (${tag})`);
  ok(errors.length === 0, `no page errors (${tag})`, errors.join(' | '));
  if (tag === 'phone') {
    // the edit runs Squire
    await page.goto(`${BASE}/oversight/review/${HELD.held.over_thread}/edit`, { waitUntil: 'networkidle' });
    ok(await page.evaluate(() => !!window.mlReviewEdit) && await page.locator('#review-edit-body').isHidden(), 'review-edit: Squire runs over the hidden textarea');
    // Pause asks for its reason inline (no modal)
    await page.goto(`${BASE}/oversight`, { waitUntil: 'networkidle' });
    await page.click(`#agent-card-${E}-pause-open`);
    ok(await page.locator(`#agent-card-${E}-reason`).isVisible() && await page.locator('.modal.show').count() === 0, 'Pause opens its reason inline (no modal)');
    // the policy page asks first when a change widens, never when it narrows
    await page.goto(`${BASE}/m/${E}/policy`, { waitUntil: 'networkidle' });
    await page.fill('#policy-max_per_hour', '10');
    ok(await page.getAttribute('#policy-form', 'hx-confirm') === null, 'narrowing a cap asks nothing');
    await page.selectOption('#policy-send_mode', 'auto');
    const conf = await page.getAttribute('#policy-form', 'hx-confirm');
    ok((conf || '').includes('send mode becomes auto'), 'widening the send mode asks first, naming what widens', conf);
    dialogs.length = 0;
    await page.click('#policy-save');
    await page.waitForTimeout(600);
    ok(dialogs.length === 1 && sql(`SELECT send_mode FROM agent_mail_policies WHERE mailbox_id = ${E}`) === 'hold_all', 'declined: nothing saved', dialogs.join('|'));
  }
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
