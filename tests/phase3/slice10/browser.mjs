// Slice 10 in headless Chromium (home-admin.md Proof 7): the four admin screens at 375 x 740 and 1280 x 800 without sideways scroll or a page error;
// the settings saved in place after the confirmation (and the MM3 box asking for its name); Re-sync swapping its row; Retry swapping a failed turn's row;
// the red switch banner on a page while a switch is on. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s10';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
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
const P = SEED.priya, E = SEED.expert;
fs.mkdirSync(SHOTS, { recursive: true });
sql(`INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, s.notice_version FROM members m, ml_settings s WHERE m.member_kind = 'human' ON CONFLICT DO NOTHING`);
const browser = await chromium.launch();
async function visit(page, path, screen, tag) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
  ok(s === screen && (await overflow(page)) <= 0, `${screen} at ${tag}: no sideways scroll`, `${s} overflow ${await overflow(page)}`);
  await page.screenshot({ path: `${SHOTS}/${screen}-${tag}.png`, fullPage: true });
}
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  sql(`UPDATE ml_settings SET hold_all_agents = false, hold_all_external = false, mm3_identity_enforced = false WHERE id = 1`);
  sql(`INSERT INTO agent_dispatches (agent_member_id, kind, via, status, decision, mailbox_id, attempts, run_id, created_at) VALUES (40, 'mail', 'chat', 'failed', 'wake', ${E}, 4, 4242, now())`);
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url()));
  let expect422 = false;
  page.on('console', (m) => { if (m.type() === 'error' && !(expect422 && m.text().includes('status of 422'))) errors.push('console: ' + m.text() + ' @ ' + page.url()); });
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(1), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);
  await visit(page, '/admin', 'admin', tag);
  ok(await page.locator('#admin-cards > div').count() === 7, `the overview has seven cards (${tag})`);
  if (w < 600) {
    const xs = await page.locator('#admin-cards > div').evaluateAll((els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().x))).size);
    ok(xs === 1, `…in one column (${tag})`, String(xs));
  }
  await visit(page, '/admin/sync', 'admin-sync', tag);
  ok(await page.locator(`#sync-${P}`).isVisible() && await page.locator(`#sync-${SEED.ada}`).isVisible(), `the sync rows are visible (${tag})`);
  await page.click(`#sync-${P}-resync`);
  await page.waitForSelector(`#sync-${P}`, { timeout: 8000 });
  await page.waitForTimeout(600);
  ok(await page.locator('#sync-' + P + ' [data-cell="state"]').count() === 1 && await page.locator('.page-header').count() === 1, `Re-sync swaps its row and nothing else (${tag})`);
  ok(sql(`SELECT count(*) FROM activity_log WHERE action = 'mailbox.resync' AND mailbox_id = ${P} AND occurred_at > now() - interval '1 minute'`) >= '1', `…and logged it (${tag})`);
  await visit(page, '/admin/agents', 'admin-agents', tag);
  const retry = page.locator('[id^="dispatch-"][id$="-retry"]').first();
  const rid = (await retry.getAttribute('id')).replace('dispatch-', '').replace('-retry', '');
  await retry.click();
  await page.waitForSelector(`#dispatch-${rid}[data-status="queued"]`, { timeout: 8000 }).catch(() => {});
  ok(sql(`SELECT status FROM agent_dispatches WHERE id = ${rid}`) === 'queued' && await page.locator(`#dispatch-${rid}-retry`).count() === 0, `Retry swaps the row to queued (${tag})`);
  await page.screenshot({ path: `${SHOTS}/admin-agents-retried-${tag}.png`, fullPage: true });
  await visit(page, '/admin/settings', 'admin-settings', tag);
  // MM3 asks for its name
  expect422 = true;
  await page.check('#set-mm3_identity_enforced');
  await page.click('#admin-settings-save');
  await page.waitForTimeout(1200);
  ok(sql(`SELECT mm3_identity_enforced FROM ml_settings`) === 'f', `MM3 without its name typed is refused in the browser (${tag})`);
  expect422 = false;
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  const undo = tag === 'phone' ? '12' : '13';
  await page.fill('#set-undo_send_seconds', undo);
  await page.check('#set-hold_all_external');
  await page.click('#admin-settings-save');
  await page.waitForSelector('#notice-banner', { timeout: 8000 }).catch(() => {});
  ok(sql(`SELECT undo_send_seconds FROM ml_settings`) === undo && sql(`SELECT hold_all_external FROM ml_settings`) === 't', `the settings saved in place after the confirmation (${tag})`);
  ok(await page.locator('#switch-banner').isVisible() && (await overflow(page)) <= 0, `the red banner is across the page while a switch is on (${tag})`);
  await page.screenshot({ path: `${SHOTS}/admin-settings-saved-${tag}.png` });
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  ok(await page.locator('#switch-banner').isVisible(), `…and on the overview (${tag})`);
  await page.screenshot({ path: `${SHOTS}/admin-banner-${tag}.png` });
  ok(errors.length === 0, `no page errors (${tag})`, errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
