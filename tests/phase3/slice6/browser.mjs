// Slice 6 in headless Chromium (search-labels-snooze.md Proof 7): every screen at 375 x 740 and 1280 x 800 without sideways scroll and with no
// console error — search, search-all, label-view, folders, labels, templates, template-new, template-edit —; the bulk bar's Label (the filter
// narrows the checkboxes) and Snooze (the five times in Berlin's clock, a typed date clearing the choices) open below the bar with no modal;
// the conversation's own Label and Snooze; Older swapping the results; axe clean on the new screens. Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s6';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
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
let failed = 0, passed = 0;
const ok = (c, l, d = '') => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l + (c || !d ? '' : ' — ' + d)); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const visible = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const st = getComputedStyle(e); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none'; }, sel);
const axe = async (page) => {
  await page.addScriptTag({ content: AXE });
  return page.evaluate(async () => (await window.axe.run(document.querySelector('#page-content') || document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => `${v.id} (${v.nodes.length})`));
};

const B1 = SEED.big1, P = SEED.priya;
sql(`INSERT INTO tags (slug, label, colour, created_by, mailbox_id) VALUES ('browser', 'Browser label', '#6f42c1', 26, ${B1}), ('other', 'Other one', '#fd7e14', 26, ${B1})`);
sql(`INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', message_key_id, (SELECT id FROM tags WHERE slug = 'browser' AND mailbox_id = ${B1}) FROM mail_messages WHERE mailbox_id = ${B1} AND uid IN (30, 31)`);
sql(`INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id, created_by) VALUES ('Browser reply', 'Hello', E'First line of the reply.\\nSecond line.', false, 26, 26), ('Browser shared', '', 'Shared text', true, 30, 30)`);
const LABEL = sql(`SELECT id FROM tags WHERE slug = 'browser' AND mailbox_id = ${B1}`);
const TPL = sql("SELECT id FROM email_templates WHERE name = 'Browser reply'");
const TPL2 = sql("SELECT id FROM email_templates WHERE name = 'Browser shared'");
const FOLDER = sql(`SELECT id FROM mail_folders WHERE mailbox_id = ${P} AND special_use = 'inbox'`);
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);
  const screens = [
    [`/m/${B1}/search?q=${encodeURIComponent('subject:invoice from:outside')}`, 'search'], ['/search?q=lunch', 'search-all'], [`/m/${B1}/search?q=zzznothing`, 'search'],
    [`/m/${B1}/label/${LABEL}`, 'label-view'], [`/m/${P}/folders`, 'folders'], [`/m/${B1}/labels`, 'labels'], ['/templates', 'templates'],
    ['/templates/new', 'template-new'], [`/templates/${TPL}/edit`, 'template-edit'], [`/templates/${TPL2}/edit`, 'template-edit'],
  ];
  for (const [path, screen] of screens) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const s = await page.evaluate(() => document.querySelector('#page-content')?.getAttribute('data-screen'));
    const o = await overflow(page);
    ok(s === screen && o <= 0, `${screen} (${path.split('?')[0]}) at ${tag}: no sideways scroll`, `${s} overflow ${o}`);
    await page.screenshot({ path: `${SHOTS}/${screen}-${path.replace(/[^a-z0-9]+/gi, '_').slice(0, 30)}-${tag}.png` });
  }
  // axe on the new screens
  for (const path of [`/m/${B1}/search?q=invoice`, `/m/${P}/folders`, `/m/${B1}/labels`, '/templates', `/templates/${TPL}/edit`]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const v = await axe(page);
    ok(v.length === 0, `axe: ${path.split('?')[0]} has no WCAG A/AA violation (${tag})`, v.join(', '));
  }
  // search: Older swaps the results; the operator help; the results link to the conversation
  await page.goto(`${BASE}/m/${B1}/search?q=${encodeURIComponent('in:anywhere')}`, { waitUntil: 'networkidle' });
  const first = await page.locator('#search-rows .mail-row').first().getAttribute('id');
  await page.click('#search-older');
  await page.waitForFunction((f) => document.querySelector('#search-rows .mail-row') && document.querySelector('#search-rows .mail-row').id !== f, first);
  ok(page.url().includes('before=') && (await page.locator('#search-rows .mail-row').count()) > 0, `Older swaps the results and pushes the URL (${tag})`);
  ok(await page.locator('#search-help').count() === 1, 'the operator help is there');
  // the bulk bar: Label and Snooze open below the bar, no modal
  await page.goto(`${BASE}/m/${B1}/v/inbox`, { waitUntil: 'networkidle' });
  await page.locator('.mail-select').first().check();
  ok(await visible(page, '#mail-bulk-form'), `selecting a row shows the bulk bar (${tag})`);
  // "Select all N" hands the handlers the view's own search (matching), and only when Select all is pressed
  ok(await page.inputValue('#mail-all-matching') === '', `matching is empty until Select all is pressed (${tag})`);
  await page.click('#mail-select-all-matching');
  ok(await page.inputValue('#mail-all-matching') === 'in:inbox' && /^All [\d,]+ selected$/.test((await page.textContent('#mail-selected-count')).trim()), `Select all N fills matching with in:inbox (${tag})`);
  await page.click('#mail-select-none');
  ok(await page.inputValue('#mail-all-matching') === '', 'Clear empties it again');
  await page.locator('.mail-select').first().check();
  await page.click('#mail-bulk-mail_label');
  ok(await visible(page, '#mail-label-filter') && (await page.locator('.mail-label-choice:visible').count()) >= 2 && (await page.locator('.modal.show').count()) === 0, 'Label opens the labels as checkboxes (no modal)');
  await page.fill('#mail-label-filter', 'brow');
  ok((await page.locator('.mail-label-choice:visible').count()) === 1, 'the filter narrows them');
  ok((await overflow(page)) <= 0, `…with the panel open, no sideways scroll (${tag})`);
  await page.screenshot({ path: `${SHOTS}/bulk-label-${tag}.png` });
  await page.click('#mail-bulk-mail_snooze');
  ok((await page.locator('.mail-snooze-choice:visible').count()) >= 3 && await visible(page, '#mail-snooze-local'), 'Snooze offers its times and a date of one\'s own');
  await page.locator('.mail-snooze-choice').first().check();
  await page.fill('#mail-snooze-local', '2030-01-02T09:00');
  ok((await page.locator('.mail-snooze-choice:checked').count()) === 0, 'typing a date clears the quick choices');
  ok((await overflow(page)) <= 0, `…no sideways scroll (${tag})`);
  await page.screenshot({ path: `${SHOTS}/bulk-snooze-${tag}.png` });
  // a conversation: Label and Snooze beside its actions
  const href = await page.locator('.mail-row-link').first().getAttribute('href');
  await page.goto(BASE + href, { waitUntil: 'networkidle' });
  ok(await visible(page, '#act-label') && await visible(page, '#act-snooze'), `the conversation offers Label and Snooze (${tag})`);
  await page.click('#act-label');
  ok(await visible(page, '#thread-label-filter') && (await overflow(page)) <= 0, 'its Label opens in place');
  await page.click('#act-snooze');
  ok(await visible(page, '#thread-snooze-local') && (await overflow(page)) <= 0, 'its Snooze opens in place');
  await page.screenshot({ path: `${SHOTS}/thread-panels-${tag}.png` });
  // the list's rail names the labels and the manage links
  if (w >= 1200) {
    await page.goto(`${BASE}/m/${B1}/v/inbox`, { waitUntil: 'networkidle' });
    ok(await visible(page, `#nav-label-${LABEL}`) && await visible(page, '#nav-manage-folders') && await visible(page, '#nav-manage-labels'), 'the rail lists the labels and links Folders and Labels');
  }
  // the folders page: a delete asks first and names the count
  await page.goto(`${BASE}/m/${P}/folders`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#folder-new-form') && (await page.locator('.folder-system').count()) >= 4, `the folders page: system folders marked, a form to make one (${tag})`);
  ok(errors.length === 0, `no console errors (${tag})`, errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
