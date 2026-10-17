// Slice 7 in headless Chromium (delegation-access.md Proof 9): Access, the grant form with the record picker, the break-glass form, the log
// and the settings at 375 x 740 and 1280 x 800 without sideways scroll and without a page error; a share made THROUGH the picker and taken
// back through the confirm (phone); the Mail admin's emergency access through the break-glass form (the row red, the reason kept) and
// revoked; the settings saved in place. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s7';
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

const browser = await chromium.launch();
async function visit(page, path, screen, tag) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
  ok(s === screen && (await overflow(page)) <= 0, `${screen} at ${tag}: no sideways scroll`, `${s} overflow ${await overflow(page)}`);
  await page.screenshot({ path: `${SHOTS}/${screen}-${tag}-${path.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: true });
}
async function choose(page, field, id) {
  await page.click(`#${field}-open`);
  await page.waitForSelector(`#record-picker-row-${id}`, { state: 'visible' });
  await page.click(`#record-picker-row-${id}`);
  await page.waitForFunction((f) => document.querySelector(`#${f}`).value !== '', field);
}
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url()));
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); d.accept().catch(() => {}); });
  console.log(`${w} x ${h}`);
  // Pat, the owner
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  await visit(page, `/m/${P}/access`, 'mailbox-access', tag);
  await visit(page, `/m/${P}/access/new`, 'access-grant-new', tag);
  await visit(page, `/m/${P}/access-log`, 'mailbox-access-log', tag);
  await visit(page, `/m/${P}/settings`, 'mailbox-settings', tag);
  await visit(page, `/m/${E}/access`, 'mailbox-access', tag);
  if (tag === 'phone') {
    // a share THROUGH the picker, then taken back through the confirm
    await page.goto(`${BASE}/m/${P}/access/new`, { waitUntil: 'networkidle' });
    await choose(page, 'grant-field-person', 28);
    ok(((await page.textContent('#grant-field-person-picker .record-picker-label')) || '').includes('Bea'), 'the picker shows the chosen person');
    await page.check('#grant-level-view');
    await page.click('#grant-save');
    await page.waitForURL(/\/access\?notice=granted/);
    ok(await page.locator('#notice-banner').count() === 1 && (await page.textContent('#access-shared')).includes('Bea'), 'shared through the picker: back on Access with a notice and the new card');
    dialogs.length = 0;
    const rid = await page.locator('#access-shared [id^="access-row-"][id$="-name"]', { hasText: 'Bea' }).first().getAttribute('id');
    await page.locator(`#${rid.replace('-name', '-revoke')}`).click();
    await page.waitForFunction(() => !document.querySelector('#access-shared')?.textContent.includes('Bea'));
    ok(dialogs.length === 1 && dialogs[0].includes('Take back') && sql(`SELECT count(*) FROM mailbox_permissions WHERE mailbox_id = ${P} AND principal_id = 28`) === '0', 'Revoke asked first (hx-confirm) and the card went', dialogs.join('|'));
    // the settings saved in place
    await page.goto(`${BASE}/m/${P}/settings`, { waitUntil: 'networkidle' });
    await page.selectOption('#settings-page_size', '100');
    await page.click('#settings-save');
    await page.waitForURL(/settings\?notice=saved/);
    ok(JSON.parse(sql(`SELECT settings FROM mailboxes WHERE id = ${P}`)).page_size === 100, 'settings saved from the form');
    await page.goto(`${BASE}/m/${P}/access-log`, { waitUntil: 'networkidle' });
    await choose(page, 'access-log-field-who', 1);
    await page.click('#access-log-apply');
    await page.waitForURL(/who=1/);
    ok(await page.locator('#access-log-list').count() === 1, 'the log filtered through the person picker');
  }
  // the Mail admin
  await page.goto(mint(1), { waitUntil: 'networkidle' });
  await visit(page, `/m/${P}/access`, 'mailbox-access', tag);
  await visit(page, `/m/${P}/access/new`, 'access-grant-new', tag);
  await visit(page, `/m/${P}/access-log`, 'mailbox-access-log', tag);
  await visit(page, `/m/${S}/settings`, 'mailbox-settings', tag);
  if (tag === 'phone') {
    await page.goto(`${BASE}/m/${P}/access/new`, { waitUntil: 'networkidle' });
    ok(await page.locator('#grant-break-glass-note').count() === 1 && await page.locator('#grant-field-department').count() === 0, 'a Mail admin on a person\'s mailbox gets the break-glass form');
    await choose(page, 'grant-field-person', 1);
    await page.check('#grant-level-view');
    await page.fill('#grant-reason', 'The signed contract is only in this mailbox');
    await page.click('#grant-save');
    await page.waitForURL(/\/access\?notice=break_glass/);
    ok(await page.locator('#access-break-glass .border-danger').count() === 1 && (await page.textContent('#access-break-glass')).includes('The signed contract'), 'emergency access made: the card is red and keeps the reason');
    await page.screenshot({ path: `${SHOTS}/break-glass-made-${tag}.png`, fullPage: true });
    ok((await overflow(page)) <= 0, 'no sideways scroll on the red card');
    dialogs.length = 0;
    await page.locator('#access-break-glass [id$="-revoke"]').first().click();
    await page.waitForFunction(() => !document.querySelector('#access-break-glass .border-danger'));
    ok(dialogs.length === 1 && sql(`SELECT count(*) FROM mailbox_permissions WHERE mailbox_id = ${P} AND break_glass`) === '0', 'the Mail admin takes it back through the confirm');
  }
  ok(errors.length === 0, `no page errors (${tag})`, errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
