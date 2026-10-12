// Slice 1 in headless Chromium (docs/build-specs/domains-mailboxes.md "Proof" 10): at 375 x 740 and 1280 x 800 — the mailbox cards, the
// new-mailbox form whose kind shows only its own fields (an agent's owner and overseers, a person's owner), a shared mailbox created through
// the form landing on its page, the forwarding cards with the Outside chip, the domains' cards; no sideways scroll; no console errors.
// Run through tests/phase3/slice1/run.sh after admin.php (which served the domain and made the mailboxes). Playwright from web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots-s1';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.malumail.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const visible = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); return !e.closest('[hidden]') && r.width > 0 && r.height > 0; }, sel);

const browser = await chromium.launch();
for (const [w, h, tag] of [[375, 740, 'phone'], [1280, 800, 'desk']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(1), { waitUntil: 'networkidle' });
  console.log(`${w} x ${h}`);

  await page.goto(BASE + '/admin/mailboxes', { waitUntil: 'networkidle' });
  ok((await page.locator('[id^="mailbox-card-"][id$="-link"]').count()) === (tag === 'phone' ? 4 : 5), `the mailboxes are cards (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll on the mailboxes (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-mailboxes.png`, fullPage: true });

  await page.goto(BASE + '/admin/mailboxes/new', { waitUntil: 'networkidle' });
  await page.check('#mailbox-new-kind-agent');
  ok((await visible(page, '#mailbox-new-field-owner-agent-open')) && (await visible(page, '#mailbox-new-field-primary-overseer-open')) && !(await visible(page, '#mailbox-new-field-owner-person-open')),
     `an agent's form shows its agent owner and its overseers, not a person owner (${tag})`);
  await page.check('#mailbox-new-kind-person');
  ok((await visible(page, '#mailbox-new-field-owner-person-open')) && !(await visible(page, '#mailbox-new-field-primary-overseer-open')), `a person's form shows a person owner and no overseers (${tag})`);
  await page.check('#mailbox-new-kind-shared');
  ok(!(await visible(page, '#mailbox-new-field-owner-person-open')) && !(await visible(page, '#mailbox-new-field-owner-agent-open')), `a shared mailbox has no owner field (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll on the new-mailbox form (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-new.png`, fullPage: true });
  const local = tag === 'phone' ? 'support' : 'billing';
  await page.fill('#mailbox-new-field-local-part', local);
  await page.fill('#mailbox-new-field-display-name', local === 'support' ? 'Support' : 'Billing');
  await Promise.all([page.waitForURL(/\/admin\/mailboxes\/\d+/, { timeout: 15000 }), page.click('#mailbox-new-create-btn')]);
  await page.waitForLoadState('networkidle');
  ok((await page.textContent('#admin-mailbox-view-header')).includes(`${local}@business.example`), `a shared mailbox made through the form lands on its page (${tag})`);
  ok(!(await page.locator('#mailbox-revealed').count()), `…and a shared mailbox shows no password (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll on a mailbox's page (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-mailbox.png`, fullPage: true });

  await page.goto(BASE + '/admin/aliases', { waitUntil: 'networkidle' });
  ok((await page.locator('[id^="alias-card-"][id$="-link"]').count()) >= 1, `the forwarding addresses are cards (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll on forwarding (${tag})`);
  await page.goto(BASE + '/admin/domains', { waitUntil: 'networkidle' });
  ok((await page.locator('#domain-business-example .badge', { hasText: 'served' }).count()) === 1, `the served domain's card says so (${tag})`);
  ok((await overflow(page)) <= 0, `no sideways scroll on domains (${tag})`);
  await page.screenshot({ path: `${SHOTS}/${tag}-domains.png`, fullPage: true });
  ok(errors.length === 0, `no console errors (${tag})` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
