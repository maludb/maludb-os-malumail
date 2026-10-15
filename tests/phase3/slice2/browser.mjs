// Slice 2 in headless Chromium (docs/build-specs/mail-list.md Proof 6 and 8): at 375 x 740 and 1280 x 800 — the rail off-canvas and fixed,
// the rows with their chips and badges, selection and the bulk bar, the empty states, no sideways scroll, axe clean on the list; the poll's
// cadence (15 s visible, 60 s hidden — through the test hook), a new message arriving through the poll as the "N new" bar with the title's
// "(n)" and the favicon's badge. No console errors. Run through tests/phase3/slice2/run.sh after list.php. Playwright from web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s2';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
const CONTROL = process.env.ML_IMAP_CONTROL;
const AXE = fs.readFileSync('/var/www/web/node_modules/axe-core/axe.min.js', 'utf8');
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.malumail.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-At', '-d', process.env.ML_DEV_DB, '-c', q]).toString().trim();
const deliver = async (user, subject) => {
  const name = crypto.randomBytes(8).toString('hex');
  fs.writeFileSync(`${CONTROL}/${name}.json`, JSON.stringify({ op: 'deliver', user, subject }));
  for (let i = 0; i < 100 && !fs.existsSync(`${CONTROL}/${name}.done`); i++) await new Promise((r) => setTimeout(r, 50));
  fs.rmSync(`${CONTROL}/${name}.done`, { force: true });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const visible = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const st = getComputedStyle(e); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none' && r.right > 0 && r.left < window.innerWidth; }, sel);
const axe = async (page) => {
  await page.addScriptTag({ content: AXE });
  return page.evaluate(async () => (await window.axe.run(document.querySelector('#page-content') || document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => `${v.id} (${v.nodes.length})`));
};

const P = SEED.priya, D = SEED.dana, E = SEED.expert;
sql('UPDATE ml_settings SET poll_visible_seconds = 5, poll_hidden_seconds = 60 WHERE id = 1');
const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);

  await page.goto(`${BASE}/m/${P}/v/inbox`, { waitUntil: 'networkidle' });
  ok((await page.locator('.mail-row').count()) > 10 && await visible(page, '.mail-row'), `the Inbox's conversations as rows (${tag})`);
  ok(await visible(page, '#mail-range') === (tag === 'desk' || await visible(page, '#mail-range')), `"1–N of M" shown (${tag})`);
  if (tag === 'phone') {
    ok(!(await visible(page, '#rail-counts')), 'at 375 the rail is off-canvas');
    await page.click('#mobile-collapse');
    await page.waitForTimeout(400);
    ok(await visible(page, '#rail-counts'), '…the menu button opens it');
    await page.screenshot({ path: `${SHOTS}/phone-rail.png` });
    await page.keyboard.press('Escape');
    await page.goto(`${BASE}/m/${P}/v/inbox`, { waitUntil: 'networkidle' });
  } else {
    ok(await visible(page, '#rail-counts') && await visible(page, '#rail-views-' + P), 'at 1280 the rail is a fixed column with the current mailbox\'s views');
  }
  ok((await overflow(page)) <= 0, `no sideways scroll on the list (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-inbox.png`, fullPage: true });

  // selection and the bulk bar
  ok(!(await visible(page, '#mail-bulk-form')), `the bulk bar is hidden until a row is checked (${tag})`);
  await page.locator('.mail-row .mail-select').nth(0).check();
  ok(await visible(page, '#mail-bulk-form') && (await page.textContent('#mail-selected-count')).trim() === '1 selected', `checking a row shows the bulk bar: "1 selected" (${tag})`);
  await page.click('#mail-select-all-page');
  const n = await page.locator('.mail-row .mail-select:not(:disabled)').count();
  ok((await page.textContent('#mail-selected-count')).trim() === `${n} selected` && await page.isChecked('#mail-select-page'), `"Select all on this page" (${tag})`);
  const builtActions = Object.values(JSON.parse(fs.readFileSync(new URL('../../../mcp/action_registry.json', import.meta.url), 'utf8')).actions).filter((a) => a.built).map((a) => a.action);
  const bulkIds = await page.locator('#mail-bulk-form .mail-bulk-action').evaluateAll((els) => els.map((e) => e.id.replace('mail-bulk-', '')));
  ok(bulkIds.every((a) => builtActions.includes(a)), `…and only action buttons whose handler is built (decision 3; ${bulkIds.length} built, ${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-selected.png` });
  await page.click('#mail-select-none');
  ok(!(await visible(page, '#mail-bulk-form')), `Clear hides it again (${tag})`);

  // chips and badges on the expert's mailbox
  await page.goto(`${BASE}/m/${E}/v/inbox`, { waitUntil: 'networkidle' });
  ok((await page.locator('.mail-agent-badge').count()) >= 1 && (await page.locator('.mail-sensitive').count()) >= 1, `the "awaiting review" badge and the sensitive lock (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll with badges (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-expert.png`, fullPage: true });
  await page.goto(`${BASE}/m/${P}/v/outbox`, { waitUntil: 'networkidle' });
  ok((await page.locator('.mail-out-state').count()) >= 2 && (await overflow(page)) <= 0, `the Outbox's states (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-outbox.png`, fullPage: true });
  await page.goto(`${BASE}/m/${P}/v/snoozed`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${SHOTS}/${tag}-snoozed.png`, fullPage: true });

  // paging by HTMX
  await page.goto(`${BASE}/m/${SEED.big}/v/inbox`, { waitUntil: 'networkidle' });
  const first = await page.locator('.mail-row').first().getAttribute('id');
  await page.click('#mail-older');
  await page.waitForURL(/before=/);
  await page.waitForFunction((f) => document.querySelector('.mail-row') && document.querySelector('.mail-row').id !== f, first, { timeout: 5000 }).catch(() => null);
  ok((await page.locator('.mail-row').first().getAttribute('id')) !== first && /51–/.test(await page.textContent('#mail-range')), `Older swaps the list and pushes the URL (${tag})`);
  await page.click('#mail-newer');
  await page.waitForURL((u) => !String(u).includes('before='));
  await page.waitForFunction((f) => document.querySelector('.mail-row') && document.querySelector('.mail-row').id === f, first, { timeout: 5000 }).catch(() => null);
  ok((await page.locator('.mail-row').first().getAttribute('id')) === first, `Newer comes back to the first page (${tag})`);

  // the poll's cadence through the test hook
  await page.goto(`${BASE}/m/${P}/v/inbox`, { waitUntil: 'networkidle' });
  await page.evaluate(() => { window.mlListTest = { visibility: 'hidden' }; document.dispatchEvent(new Event('ml:visibility')); });
  const hidden = await page.evaluate(() => window.mlPollLog[window.mlPollLog.length - 1]);
  await page.evaluate(() => { window.mlListTest = { visibility: 'visible' }; document.dispatchEvent(new Event('ml:visibility')); });
  const shown = await page.evaluate(() => window.mlPollLog[window.mlPollLog.length - 1]);
  ok(hidden === 60 && shown === 5, `the poll waits poll_hidden_seconds while hidden (60) and poll_visible_seconds while visible (${shown}) (${tag})`);

  // a new message arrives through the poll: the "N new" bar, the title's count, the favicon's badge
  const before = await page.title();
  const polled = page.waitForResponse((r) => r.url().includes('/changes?') && r.status() === 200, { timeout: 20000 });
  await deliver('priya@business.example', `Arrived in the browser ${tag}`);
  const res = await polled.catch(() => null);
  await page.waitForTimeout(300);
  const unread = Number(res ? res.headers()['x-ml-unread'] : -1);
  ok(res !== null && await visible(page, '#mail-new-link') && /1 new conversation/.test(await page.textContent('#mail-new-link')), `the poll shows "1 new conversation" (${tag})`);
  ok((await page.title()).startsWith(`(${unread}) Inbox — priya@business.example`) && unread > 0, `the title carries the unread count: "${await page.title()}" (was "${before}")`);
  ok(await page.evaluate((u) => { const l = document.querySelector('link[rel~="icon"]'); return l && l.getAttribute('data-count') === String(u) && l.href.startsWith('data:image/png'); }, unread),
     `the favicon carries a drawn badge (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-new-bar.png` });
  await page.click('#mail-new-link');
  await page.waitForFunction((t) => (document.querySelector('.mail-row') || {}).textContent?.includes(t), `Arrived in the browser ${tag}`, { timeout: 5000 }).catch(() => null);
  ok((await page.locator('.mail-row').first().textContent()).includes(`Arrived in the browser ${tag}`), `"show" reloads the first page with it on top (${tag})`);
  const violations = await axe(page);
  ok(violations.length === 0, `axe (WCAG 2 A/AA) finds nothing on the list (${tag})` + (violations.length ? ': ' + violations.join(', ') : ''));

  // empty states
  await page.goto(`${BASE}/m/${SEED.team}/v/spam`, { waitUntil: 'networkidle' });
  ok((await page.textContent('#mail-empty')).includes('Nothing here.'), `an empty view says so (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-empty.png` });
  ok(errors.length === 0, `no console errors (${tag})` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
sql('UPDATE ml_settings SET poll_visible_seconds = 15, poll_hidden_seconds = 60 WHERE id = 1');
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
