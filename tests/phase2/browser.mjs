// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md "Proof" 8), ported from Restaurant Inventory's:
//   at 375 x 740 — the notice gate met and passed by a click, the tab bar Inbox · Search · Compose · Me on the bottom edge with the command bar
//   above it, every tab at least 44 px, the rail as a drawer from the menu button, a mailbox opened from it by HTMX (data-screen and data-mailbox
//   follow), no sideways scroll on the shell's screens; at 1280 x 800 — the rail as the sidebar with the current mailbox's views, no tab bar,
//   the header's search; with JavaScript off — the notice acknowledged by a plain form post. No console errors anywhere.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = `http://127.0.0.1:${process.env.P_APP || '9201'}`;
const SHOTS = process.env.SHOTS || '/tmp/ml-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const seed = JSON.parse(fs.readFileSync(process.env.ML_P2_SEED, 'utf8'));
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.malumail.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (text) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-d', process.env.DB_NAME, '-Atc', text], { encoding: 'utf8' }).trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
  return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const tabsOf = async (page) => (await page.locator('#shell-tabbar .app-tab').allInnerTexts()).map((t) => t.trim()).join(',');
const small = (page, s) => page.evaluate((sel) => [...document.querySelectorAll(sel)].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); })
  .map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), s);

// a new version of the notice: everyone meets the gate again
sql("UPDATE ml_settings SET notice_text = notice_text || ' Third version.'");
const version = Number(sql('SELECT notice_version FROM ml_settings WHERE id = 1'));

// ---- phone: Dana ----
{
  const { ctx, page, errors } = await session(30, { width: 375, height: 740 });
  console.log('375 x 740 — Dana');
  ok(page.url().startsWith(BASE + '/notice?next='), `a new notice version: signing on lands on the notice (${page.url().replace(BASE, '')})`);
  let o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the notice (scrollWidth ${o.sw} = ${o.iw})`);
  ok((await tabsOf(page)) === 'Notice,Me' && !(await shown(page, '#assistant-bar')), 'before acknowledging: the tabs are Notice and Me, and the command bar is not offered');
  await page.screenshot({ path: `${SHOTS}/phone-notice.png` });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#notice-acknowledge-btn')]);
  ok(page.url() === `${BASE}/m/${seed.dana}/v/inbox`, `acknowledging lands on her Inbox (${page.url().replace(BASE, '')})`);
  ok(Number(sql(`SELECT count(*) FROM ml_notice_acknowledgements WHERE member_id = 30 AND notice_version = ${version}`)) === 1, '…and is recorded');
  o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the Inbox (${o.sw} = ${o.iw})`);
  ok(await shown(page, '#shell-tabbar') && !(await shown(page, '#shell-sidebar')), 'a phone: the tab bar is shown, the rail is not');
  ok((await tabsOf(page)) === 'Inbox,Search,Compose,Me', `the four tabs: ${await tabsOf(page)}`);
  const sm = await small(page, '#shell-tabbar .app-tab');
  ok(sm.length === 0, 'every tab is at least 44 x 44 px' + (sm.length ? ': ' + sm.join(', ') : ''));
  const tab = await page.locator('#shell-tabbar').boundingBox();
  ok(Math.abs(tab.y + tab.height - 740) < 1, `the tab bar sits on the bottom edge (${Math.round(tab.y + tab.height)})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= tab.y + 1, `the command bar sits just above the tab bar (${Math.round(bar.y + bar.height)} <= ${Math.round(tab.y)})`);
  await page.screenshot({ path: `${SHOTS}/phone-inbox.png` });
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, `#nav-mailbox-${seed.dana}`) && await shown(page, `#nav-mailbox-${seed.sales}`) && (await page.locator(`#nav-mailbox-${seed.expert}`).count()) === 0,
     'the menu button opens the rail as a drawer: Dana\'s mailbox and sales@, not the agent\'s');
  await page.screenshot({ path: `${SHOTS}/phone-rail.png` });
  await page.locator(`#nav-mailbox-${seed.sales} a`).click();
  await page.waitForFunction((id) => document.getElementById('page-content').dataset.mailbox === String(id), seed.sales);
  ok((await page.locator('#page-content').getAttribute('data-screen')) === 'mailbox-view' && page.url() === `${BASE}/m/${seed.sales}/v/inbox`,
     'opening sales@ from the rail swaps #page-content by HTMX: data-screen mailbox-view, data-mailbox follows, the URL pushed');
  await page.waitForTimeout(300);
  ok(!(await shown(page, '#shell-sidebar')), '…and the drawer closes');
  for (const p of ['/tokens', '/my/trail', '/notice', `/m/${seed.dana}/settings`]) {
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    const x = await overflow(page);
    ok(x.sw === x.iw, `no sideways scroll on ${p} (${x.sw} = ${x.iw})`);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- desktop: Priya ----
{
  sql(`INSERT INTO ml_notice_acknowledgements (member_id, notice_version) VALUES (26, ${version}) ON CONFLICT DO NOTHING`);
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800 — Priya');
  ok(page.url() === `${BASE}/m/${seed.priya}/v/inbox`, `signed on and landed on her Inbox (${page.url().replace(BASE, '')})`);
  ok(await shown(page, '#shell-sidebar') && !(await shown(page, '#shell-tabbar')), 'a desktop: the rail is the sidebar; no tab bar');
  ok(await shown(page, `#rail-views-${seed.priya}`) && await shown(page, '#nav-view-sent') && await shown(page, '#nav-oversight'), 'the current mailbox\'s views and Oversight are in the rail');
  ok(await shown(page, '#header-search') && (await page.locator('#header-search').getAttribute('action')) === `/m/${seed.priya}/search`, 'the header\'s search searches the current mailbox');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (${o.sw} = ${o.iw})`);
  await page.locator('#nav-view-sent a').click();
  await page.waitForFunction(() => document.getElementById('page-content').dataset.screen === 'mailbox-view' && location.pathname.endsWith('/v/sent'));
  ok(await shown(page, '#mail-list') && (await page.locator('#mail-list').getAttribute('data-view')) === 'sent', 'Sent opens its list (slice 2 built it) by HTMX from the rail');
  await page.screenshot({ path: `${SHOTS}/desktop-inbox.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the notice still works ----
{
  const { ctx, page } = await session(1, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off — the owner');
  ok(page.url().startsWith(BASE + '/notice'), 'the notice gate holds without JavaScript');
  await Promise.all([page.waitForNavigation(), page.click('#notice-acknowledge-btn')]);
  ok(page.url() === `${BASE}/m/${seed.expert}/v/inbox`, `the plain form acknowledges and lands on the Mail admin's mailbox (${page.url().replace(BASE, '')})`);
  await ctx.close();
}

await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
