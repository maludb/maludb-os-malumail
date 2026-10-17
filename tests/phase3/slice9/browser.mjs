// Slice 9 in headless Chromium (notifications.md Proof 7): the notifications page and the settings at 375 x 740 and 1280 x 800 without sideways
// scroll or a page error; the bell's menu opens (the latest eight, See all), a notice opened from it posts the read and lands on its link; the
// settings saved in place; the text column hidden for a person with no phone. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s9';
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
const P = SEED.priya, E = SEED.expert, S = SEED.sales;
fs.mkdirSync(SHOTS, { recursive: true });
function seed() {
  sql(`DELETE FROM notifications WHERE member_id IN (26, 30)`);
  sql(`UPDATE members SET phone = '+15550100' WHERE id = 26; UPDATE members SET phone = NULL WHERE id = 30`);
  sql(`SELECT ml_notify(26, 'digest', 'Your agents'' mail this morning', E'expert@business.example: 1 held over a day, 2 unscreened', NULL, 'clerk_note', ${20261001 + 0}, 42)`);
  for (let i = 0; i < 10; i++) sql(`SELECT ml_notify(26, 'permission_granted', 'You can open sales@business.example (view) ${i}', NULL, ${S}, 'mailbox', ${S})`);
  sql(`SELECT ml_notify_coalesce(26, ${P}, 3)`);
  sql(`SELECT ml_notify(26, 'quota', 'priya@business.example is 92 % full', NULL, ${P}, 'mailbox', ${P})`);
  sql(`UPDATE notifications SET read_at = now() WHERE member_id = 26 AND title LIKE '%view) 9'`);
  sql(`SELECT ml_notify(30, 'permission_granted', 'You can open sales@business.example (view)', NULL, ${S}, 'mailbox', ${S})`);
}

const browser = await chromium.launch();
async function visit(page, path, screen, tag) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
  ok(s === screen && (await overflow(page)) <= 0, `${screen} at ${tag}: no sideways scroll`, `${s} overflow ${await overflow(page)}`);
  await page.screenshot({ path: `${SHOTS}/${screen}-${tag}.png`, fullPage: true });
}
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  seed();
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url()));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text() + ' @ ' + page.url()); });
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);
  await visit(page, '/notifications', 'notifications', tag);
  ok(await page.locator('#notifications-list .list-group-item').count() >= 12, `the list shows the notices (${tag})`);
  ok(await page.locator('#notifications-list .notice-unread').count() >= 11 && await page.locator('#notifications-list .fw-bold').count() >= 11, `unread in bold (${tag})`);
  await visit(page, '/settings/notifications', 'notification-settings', tag);
  ok(await page.locator('#kind-held_mail input[name="text_kind[]"]').count() === 1, `Priya (a phone on file) sees the By text column (${tag})`);
  // the bell
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  const count = await page.locator('#bell-count').textContent();
  ok(parseInt(count, 10) >= 11, `the bell shows the unread count (${tag})`, count);
  if (w < 600) await page.click('#bell'); else await page.hover('#bell');       // the theme opens a header dropdown on hover at desktop widths, on a tap at phone widths
  await page.waitForSelector('#bell-menu-rows', { state: 'visible' });
  const rows = await page.locator('#bell-menu-rows .list-group-item').count();
  ok(rows === 8 && await page.locator('#bell-see-all').isVisible(), `the bell's menu: the latest eight and See all (${tag})`, String(rows));
  ok((await overflow(page)) <= 0, `the open menu causes no sideways scroll (${tag})`);
  const box = await page.locator('#bell-menu').boundingBox();
  ok(box && box.x >= 0 && box.x + box.width <= w + 1, `the menu stays inside the viewport (${tag})`, JSON.stringify(box));
  await page.screenshot({ path: `${SHOTS}/bell-menu-${tag}.png` });
  if (tag === 'phone') {
    // open the newest notice (new mail in her Inbox) from the menu: it is read and we land on its link
    const id = sql(`SELECT id FROM notifications WHERE member_id = 26 AND kind = 'quota'`);
    await Promise.all([page.waitForURL(`${BASE}/m/${P}/settings`, { timeout: 8000 }).catch(() => {}), page.click(`#bell-row-${id}-open`).catch(() => {})]);
    await page.waitForLoadState('networkidle');
    ok(page.url().endsWith(`/m/${P}/settings`), 'a notice opened from the menu lands on its link', page.url());
    ok(sql(`SELECT read_at IS NOT NULL FROM notifications WHERE id = ${id}`) === 't', 'opening it marked it read');
    // Mark all read on the page
    await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
    await page.click('#notifications-read-all');
    await page.waitForFunction(() => document.querySelector('#notifications-unread')?.textContent.trim() === '0 unread', null, { timeout: 8000 }).catch(() => {});
    ok(sql(`SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL`) === '0', 'Mark all read marks them all');
    ok(await page.locator('#notifications-read-all').count() === 0, 'the page follows: no Mark all read button left');
    // settings saved in place
    await page.goto(BASE + '/settings/notifications', { waitUntil: 'networkidle' });
    await page.check(`#nm-${P}`);
    await page.check('#mail-new_mail');
    await page.click('#notification-save');
    await page.waitForSelector('#notice-banner', { timeout: 8000 }).catch(() => {});
    ok(sql(`SELECT new_mail_mailbox_ids FROM notification_prefs WHERE member_id = 26`) === `{${P}}` && sql(`SELECT 'new_mail' = ANY (kinds) FROM notification_prefs WHERE member_id = 26`) === 't', 'the settings saved (a mailbox switched on, new mail by mail)');
  }
  // Dana: no phone, no text column
  const ctx2 = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const p2 = await ctx2.newPage();
  p2.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + p2.url()));
  await p2.goto(mint(30), { waitUntil: 'networkidle' });
  await p2.goto(BASE + '/settings/notifications', { waitUntil: 'networkidle' });
  ok(await p2.locator('input[name="text_kind[]"]').count() === 0 && await p2.locator('#notification-text-hint').isVisible(), `Dana (no phone) sees no By text column, and why (${tag})`);
  await p2.screenshot({ path: `${SHOTS}/notification-settings-nophone-${tag}.png`, fullPage: true });
  await ctx2.close();
  ok(errors.length === 0, `no page errors (${tag})`, errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
