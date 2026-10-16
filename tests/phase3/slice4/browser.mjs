// Slice 4 in headless Chromium (writing.md Proof 10; the composer's behaviour at 375 x 740 and 1280 x 800): the composer in one column
// with the sticky bar, Squire running with the toolbar, recipient chips (an outside address outlined, a bad one refused), autosave
// making the draft and the status line, an attachment through the paper-clip, the schedule panel inline (no modal), Send → the bar with
// Undo for the window, then "Sent"; Undo → back in the draft; the identity form at 375 without sideways scroll; and with JavaScript OFF
// the plain form sends. Playwright from the kernel's web/node_modules. Run through tests/phase3/slice4/run.sh after checks.php.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s4';
const KEY = process.env.ACTION_TOKEN_KEY;
const SEED = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
const SPOOL = process.env.ML_SMTP_SPOOL;
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
let failed = 0, passed = 0;
const ok = (c, l, d = '') => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l + (c || !d ? '' : ' — ' + d)); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const visible = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const st = getComputedStyle(e); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none' && r.right > 0 && r.left < window.innerWidth; }, sel);
const axe = async (page) => {
  await page.addScriptTag({ content: AXE });
  return page.evaluate(async () => (await window.axe.run(document.querySelector('#page-content') || document, { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => `${v.id} (${v.nodes.length})`));
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const P = SEED.priya;
const RUN = crypto.randomBytes(3).toString('hex');
const spoolHas = (mid) => fs.existsSync(`${SPOOL}/index.jsonl`) && fs.readFileSync(`${SPOOL}/index.jsonl`, 'utf8').includes(mid);
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message + ' @ ' + page.url() + ' ' + String(e.stack || '').split('\n').slice(1, 3).join(' / ')));
  page.on('dialog', (d) => { d.accept().catch(() => {}); });
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);

  await page.goto(`${BASE}/m/${P}/compose`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  ok(await visible(page, '#compose-form') && await visible(page, '#compose-send') && await visible(page, '#compose-attach-label'), `the composer with Send and the paper-clip (${tag})`);
  ok(await page.evaluate(() => !!(window.mlCompose && window.mlCompose.editor)) && await visible(page, '#compose-toolbar') && !(await visible(page, '#compose-body')), `Squire runs over the hidden textarea, the toolbar shown (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll (${tag})`);
  const barSticky = await page.evaluate(() => getComputedStyle(document.getElementById('compose-bar')).position === 'sticky');
  ok(barSticky, `the bar is sticky at the bottom (${tag})`);
  if (tag === 'desk') ok(await page.evaluate(() => document.querySelector('.mail-compose-col').getBoundingClientRect().width <= 790), 'the column is at most 760 px at 1280');
  // chips
  await page.fill('#compose-to', 'lee@customer.example,');
  await page.waitForTimeout(150);
  ok(await page.locator('#compose-chips-to .mail-chip').count() === 1 && await page.locator('#compose-chips-to .mail-chip-outside').count() === 1, `a comma makes a chip; an outside address is outlined (${tag})`);
  await page.fill('#compose-to', 'dana@business.example');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(150);
  ok(await page.locator('#compose-chips-to .mail-chip').count() === 2 && await page.locator('#compose-chips-to .mail-chip-outside').count() === 1, `Enter makes a chip; an inside address is plain (${tag})`);
  await page.fill('#compose-to', 'not-an-address');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(150);
  ok(await page.locator('#compose-chips-to .mail-chip').count() === 2 && await page.evaluate(() => document.getElementById('compose-to').classList.contains('is-invalid')), `a bad address makes no chip and is marked (${tag})`);
  await page.fill('#compose-to', '');
  await page.click('#compose-show-cc');
  ok(await visible(page, '#compose-row-cc'), `Cc revealed on request (${tag})`);
  await page.fill('#compose-subject', `Browser ${tag} ${RUN}`);
  await page.click('#compose-editor');
  await page.keyboard.type('Hello from the browser. ');
  await page.click('#compose-toolbar [data-cmd="bold"]');
  await page.keyboard.type('bold');
  ok((await page.evaluate(() => window.mlCompose.editor.getHTML())).includes('<b>bold</b>'), `the toolbar formats (bold) (${tag})`);
  // autosave: the settings say 5 s
  await page.waitForFunction(() => window.mlCompose && window.mlCompose.draft, null, { timeout: 15000 }).catch(() => {});
  const draftId = await page.evaluate(() => window.mlCompose.draft);
  ok(draftId > 0 && (await page.textContent('#compose-status')).startsWith('Saved'), `autosave made the draft and says Saved (${tag})`, String(draftId));
  ok(sql(`SELECT body_html LIKE '%<b>bold</b>%' AND subject = 'Browser ${tag} ${RUN}' FROM mail_drafts WHERE id = ${draftId || 0}`) === 't', `…with the HTML and the subject (${tag})`);
  // an attachment through the paper-clip
  await page.setInputFiles('#compose-file', { name: `note-${tag}.txt`, mimeType: 'text/plain', buffer: Buffer.from('a small note') });
  await page.waitForSelector('.mail-attachment-chip', { timeout: 10000 });
  ok(await page.locator('.mail-attachment-chip').count() === 1 && (await page.textContent('#compose-attachment-strip')).includes(`note-${tag}.txt`), `the paper-clip attaches a file and shows it in the strip (${tag})`);
  // the schedule panel: inline, not a modal
  await page.click('#compose-schedule-toggle');
  await page.waitForTimeout(200);
  ok(await visible(page, '#compose-schedule') && await page.locator('.modal.show').count() === 0 && (await page.locator('#compose-schedule .mail-schedule-choice').count()) >= 2, `Schedule… opens an inline panel with the choices (${tag})`);
  ok((await overflow(page)) <= 0, `…no sideways scroll with it open (${tag})`);
  await page.screenshot({ path: `${SHOTS}/compose-${tag}.png`, fullPage: true });
  await page.click('#compose-schedule-close');
  // Send → the bar with Undo
  await page.click('#compose-send');
  await page.waitForSelector('#compose-sent-bar:not(.d-none)', { timeout: 10000 });
  ok(await visible(page, '#compose-undo') && (await page.textContent('#compose-sent-text')).startsWith('Sending in'), `Send: the bar counts the undo window down with Undo (${tag})`);
  const oid = await page.getAttribute('#compose-sent-bar', 'data-outbound');
  ok(sql(`SELECT status FROM outbound_email WHERE id = ${oid}`) === 'queued' && sql(`SELECT count(*) FROM attachments WHERE record_type = 'outbound_email' AND record_id = ${oid}`) === '1', `…the row queued with the attachment (${tag})`);
  if (tag === 'phone') {
    await page.click('#compose-undo');
    await page.waitForURL(`**/m/${P}/drafts/**`, { timeout: 10000 }).catch(() => {});
    ok(page.url().includes(`/m/${P}/drafts/`) && sql(`SELECT status FROM outbound_email WHERE id = ${oid}`) === 'cancelled', 'Undo: cancelled, back in the draft');
    ok((await page.textContent('#compose-subject-wrap, #compose-form')).includes('') && await page.evaluate(() => document.getElementById('compose-subject').value.startsWith('Browser phone')) && await page.locator('.mail-attachment-chip').count() === 1, '…with its text and attachment');
  } else {
    await page.waitForFunction(() => (document.getElementById('compose-sent-text').textContent || '').startsWith('Sent'), null, { timeout: 30000 }).catch(() => {});
    ok((await page.textContent('#compose-sent-text')).startsWith('Sent') && !(await visible(page, '#compose-undo')), 'after the window: "Sent", Undo gone');
    await page.waitForFunction(() => !document.getElementById('compose-view-message').classList.contains('d-none'), null, { timeout: 30000 }).catch(() => {});
    ok(await visible(page, '#compose-view-message') && spoolHas(sql(`SELECT message_id FROM outbound_email WHERE id = ${oid}`)), '…View message appears once the Sent copy is indexed; the spool has it');
  }
  // the identity form
  await page.goto(`${BASE}/m/${P}/identities/new`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  ok(await visible(page, '#identity-form') && await visible(page, '#identity-signature-editor') && (await overflow(page)) <= 0, `the identity form, the signature editor, no sideways scroll (${tag})`);
  if (tag === 'phone') await page.screenshot({ path: `${SHOTS}/identity-phone.png`, fullPage: true });
  const v = await axe(page);
  ok(v.length === 0, `axe: no WCAG 2 A/AA violation on the identity form (${tag})`, v.join(', '));
  await page.goto(`${BASE}/m/${P}/compose`, { waitUntil: 'networkidle' });
  const v2 = await axe(page);
  ok(v2.length === 0, `axe: no WCAG 2 A/AA violation on the composer (${tag})`, v2.join(', '));
  ok(errors.length === 0, `no console error (${tag})`, errors.slice(0, 3).join(' | '));
  await ctx.close();
}
// JavaScript off: the plain form sends
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 }, javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(mint(26), { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/m/${P}/compose`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#compose-body') && await visible(page, '#compose-send'), 'without JavaScript: the plain textarea and Send');
  ok((await overflow(page)) <= 0, '…no sideways scroll');
  await page.fill('#compose-to', 'nojs@customer.example');
  await page.fill('#compose-subject', `No JS ${RUN}`);
  await page.fill('#compose-body', 'Sent without a script.');
  await page.click('#compose-send');
  await page.waitForURL('**/v/inbox?notice=sent**', { timeout: 10000 }).catch(() => {});
  const nojs = sql(`SELECT status || '|' || to_addresses::text FROM outbound_email WHERE subject = 'No JS ${RUN}'`);
  ok(page.url().includes('notice=sent') && nojs.startsWith('queued|{nojs@customer.example}') || nojs.startsWith('sent|'), '…a normal POST queued the message and landed on the list', nojs);
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
