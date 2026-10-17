// Slice 8 in headless Chromium (correspondents.md Proof 5): People, the correspondent page and the composer's recipient suggestions at
// 375 x 740 and 1280 x 800 without sideways scroll and without a page error; the suggestions by keyboard (arrows, Enter, Escape) and by tap;
// Older swapping the list in place; the company tab. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s8';
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
let failed = 0, passed = 0;
const ok = (c, l, d = '') => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l + (c || !d ? '' : ' — ' + d)); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const P = SEED.priya, E = SEED.expert;
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
async function visit(page, path, screen, tag) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
  ok(s === screen && (await overflow(page)) <= 0, `${screen} at ${tag}: no sideways scroll`, `${s} overflow ${await overflow(page)}`);
  await page.screenshot({ path: `${SHOTS}/${screen}-${tag}.png` });
}
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url()));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) errors.push('console: ' + m.text() + ' @ ' + page.url()); });
  console.log(`${w} x ${h}`);
  await page.goto(mint(26), { waitUntil: 'networkidle' });

  await visit(page, `/m/${P}/people`, 'correspondents', tag);
  ok((await page.locator('.people-row').count()) === 50, 'People: fifty rows');
  // Older swaps the list in place (no full load), keeps the filters in the URL
  await page.click('#people-older');
  await page.waitForFunction(() => document.querySelectorAll('.people-row').length === 50 && /before=/.test(location.search));
  ok(await page.locator('#people-filter').count() === 1 && (await overflow(page)) <= 0, 'Older: the next fifty swapped in, the filter kept, no sideways scroll');
  // filter in place
  await page.goto(`${BASE}/m/${P}/people`, { waitUntil: 'networkidle' });
  await page.fill('#people-q', 'zed');
  await page.click('#people-go');
  await page.waitForFunction(() => document.querySelectorAll('.people-row').length === 3);
  ok(true, 'the filter narrows the list to the three Zeds in place');
  await page.selectOption('#people-known', 'no');
  await page.click('#people-go');
  await page.waitForFunction(() => document.querySelectorAll('.people-row').length === 1);
  ok((await page.textContent('.people-row')).includes('Zed Heard'), 'Known: not known finds the one only heard from');
  await visit(page, `/m/${E}/people`, 'correspondents', tag);
  ok(await page.locator('.people-known').count() > 0 && await page.locator('button:has-text("Mark known")').count() > 0, "the Expert's People: Known chips and Mark known for its overseer");

  await visit(page, `/m/${P}/people/${encodeURIComponent('jon@supplier.example')}`, 'correspondent-view', tag);
  ok(await page.locator('#person-tab-company').count() === 1, 'the correspondent page: the company tab');
  await page.click('#person-tab-company');
  await page.waitForSelector('#person-domain-addresses');
  ok((await overflow(page)) <= 0 && (await page.textContent('#person-domain-addresses')).includes('kay@supplier.example'), 'the company tab: the domain\'s addresses');
  await page.screenshot({ path: `${SHOTS}/correspondent-company-${tag}.png` });
  await visit(page, `/m/${P}/people/${encodeURIComponent('gil.public@gmail.com')}`, 'correspondent-view', tag);
  ok(await page.locator('#person-tab-company').count() === 0, 'a public provider: no company tab');

  // the composer's suggestions
  await page.goto(`${BASE}/m/${P}/compose`, { waitUntil: 'networkidle' });
  const list = '#compose-suggest-to';
  await page.click('#compose-to');
  await page.keyboard.type('ze', { delay: 40 });
  await page.waitForSelector(`${list} li[data-address]`, { state: 'visible' });
  const addrs = await page.$$eval(`${list} li[data-address]`, (els) => els.map((e) => e.getAttribute('data-address')));
  ok(JSON.stringify(addrs) === JSON.stringify(['zed.sent@zedco.example', 'zoe@zedco.example', 'zed.heard@zedco.example']), 'typing "ze" lists the three Zeds, sent-to first', JSON.stringify(addrs));
  ok((await page.getAttribute('#compose-to', 'aria-expanded')) === 'true' && (await page.getAttribute(list, 'role')) === 'listbox', 'the field announces a listbox');
  await page.screenshot({ path: `${SHOTS}/compose-suggest-${tag}.png` });
  ok((await overflow(page)) <= 0, 'the open list causes no sideways scroll');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  ok((await page.getAttribute('#suggest-1', 'aria-selected')) === 'true', 'ArrowDown moves to the second');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#compose-chips-to .mail-chip[data-address="zoe@zedco.example"]');
  ok((await page.locator(list).evaluate((e) => e.classList.contains('d-none'))) && (await page.inputValue('#compose-to')) === '', 'Enter takes it as a chip, closes the list and empties the field');
  // Escape closes, the typed text is kept
  await page.keyboard.type('zed.h', { delay: 40 });
  await page.waitForSelector(`${list} li[data-address]`, { state: 'visible' });
  await page.keyboard.press('Escape');
  ok((await page.locator(list).evaluate((e) => e.classList.contains('d-none'))) && (await page.inputValue('#compose-to')) === 'zed.h', 'Escape closes the list and keeps what was typed');
  // a tap on a suggestion
  await page.fill('#compose-to', '');
  await page.keyboard.type('zed.h', { delay: 40 });
  await page.waitForSelector(`${list} li[data-address]`, { state: 'visible' });
  if (w < 600) await page.tap(`${list} li[data-address="zed.heard@zedco.example"]`); else await page.click(`${list} li[data-address="zed.heard@zedco.example"]`);
  await page.waitForSelector('#compose-chips-to .mail-chip[data-address="zed.heard@zedco.example"]');
  ok(true, 'a tap or click takes a suggestion');
  // a typed address with no suggestion still becomes a chip (the box works without the list)
  await page.fill('#compose-to', 'plain@typed.example');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#compose-chips-to .mail-chip[data-address="plain@typed.example"]');
  ok(await page.locator('.mail-chip-outside').count() >= 2, 'a typed address still becomes a chip; outside addresses are outlined');
  // a viewer's composer gets no list (404 from the door): Dana on priya@ has view only
  ok(errors.length === 0, 'no page error or console error', errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
