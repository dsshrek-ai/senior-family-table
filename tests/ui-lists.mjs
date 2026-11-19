// Browser walkthrough of scaling and shopping lists (headless Edge or Chrome).
// Needs playwright-core and a FRESH test database (tests/README.md); the site served with its API.
//   BASE_URL=http://127.0.0.1:8100 node tests/ui-lists.mjs <screenshot folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8100';
const SHOTS = process.argv[2];
const browser = await chromium.launch({ executablePath: process.env.BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const results = [];
const consoleErrors = [];

async function newPage(viewport, token) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) { consoleErrors.push(`${page.url()}: ${m.text()}`); } });
  page.on('pageerror', e => consoleErrors.push(`${page.url()}: ${e.message}`));
  page.on('dialog', d => d.accept(d.type() === 'prompt' ? (page.nextPrompt || '') : undefined));
  if (token) { await page.goto(`${BASE}/index.html?token=${token}`); }
  return page;
}
async function step(name, fn) {
  try { await fn(); results.push(`PASS  ${name}`); } catch (e) { results.push(`FAIL  ${name} — ${e.message.split('\n')[0]}`); }
}
const api = async (token, action, body) => (await fetch(`${BASE}/api/api.php`, { method: 'POST',
  headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'text/plain' }, body: JSON.stringify({ action, ...body }) })).json();
const apiGet = async (token, action, params) => (await fetch(`${BASE}/api/api.php?${new URLSearchParams({ action, ...params })}`,
  { headers: { Authorization: `Bearer ${token}` } })).json();

const desk = await newPage({ width: 1280, height: 900 }, 'tok-owner');
const beans = (await apiGet('tok-owner', 'searchRecipes', { q: 'Refried Beans with Lard' })).recipes[0].id;
let listUrl = '';

await step('recipe scales by servings and warns about cooking times', async () => {
  await desk.goto(`${BASE}/recipe.html?id=${beans}`);
  await desk.locator('#scale-input').waitFor();
  assert.equal(await desk.inputValue('#scale-input'), '6');
  await desk.fill('#scale-input', '12');
  await desk.locator('#scale-input').press('Enter');
  await desk.getByText('4 cups Pinto beans (dry)').waitFor();
  await desk.getByText('1 tbsp chili powder').waitFor();
  await desk.locator('#scale-note').getByText('cooking times, pan sizes').waitFor();
  await desk.click('[data-step="1"]');
  assert.equal(await desk.inputValue('#scale-input'), '13');
  await desk.fill('#scale-input', '12');
  await desk.locator('#scale-input').press('Enter');
  await desk.screenshot({ path: `${SHOTS}/l1-scaled.png`, fullPage: true });
});

await step('"Remember this amount" is used next time', async () => {
  await desk.click('#scale-save');
  await desk.getByText('Remembered: 12 servings').waitFor();
  await desk.reload();
  await desk.locator('#scale-input').waitFor();
  assert.equal(await desk.inputValue('#scale-input'), '12');
});

await step('add to a new list, unticking what you already have', async () => {
  await desk.click('#to-list');
  await desk.locator('#atl-list').waitFor();
  await desk.selectOption('#atl-list', 'new');
  await desk.fill('#atl-new', 'Weekly Groceries');
  await desk.locator('#atl-box label', { hasText: 'Kosher salt' }).locator('input').uncheck();
  await desk.screenshot({ path: `${SHOTS}/l2-add-sheet.png` });
  await desk.click('#atl-add');
  await desk.locator('#atl-done a', { hasText: 'Open the list' }).click();
  await desk.getByRole('heading', { name: 'Weekly Groceries' }).waitFor();
  listUrl = desk.url();
  await desk.locator('.shop-item-row').first().waitFor();
  assert.equal(await desk.locator('[data-f="label"][value="Kosher salt"]').count(), 0, 'unticked ingredient left off');
  assert.equal(await desk.inputValue('[data-id] [data-f="label"] >> nth=0').then(() => 'ok'), 'ok');
  const pinto = desk.locator('.shop-item-row', { has: desk.locator('[data-f="label"][value="Pinto beans"]') });
  // 4 cups → 1 quart: the Senior Family Cookbook shows volumes in the friendliest unit.
  assert.equal(await pinto.locator('[data-f="quantity"]').inputValue(), '1');
  assert.equal(await pinto.locator('[data-f="unit"]').inputValue(), 'quart');
});

await step('add several recipes at once from a cookbook (uses the list just used)', async () => {
  await desk.goto(`${BASE}/cookbook.html?id=1`);
  await desk.click('#pick-btn');
  await desk.fill('#filter', 'meatball');
  const picks = desk.locator('[data-pick]');
  assert.ok(await picks.count() >= 1);
  await picks.nth(0).check();
  await desk.click('#pick-add');
  await desk.locator('#atl-list').waitFor();
  assert.equal(await desk.locator('#atl-list option:checked').textContent(), 'Weekly Groceries');
  await desk.click('#atl-add');
  await desk.locator('#atl-done').getByText('Added to').waitFor();
});

await step('edit mode: stepper, rename, department, store, notes, add item', async () => {
  await desk.goto(listUrl);
  await desk.locator('.shop-item-row').first().waitFor();
  const row = desk.locator('.shop-item-row', { has: desk.locator('[data-f="label"][value="Pinto beans"]') });
  await row.locator('[data-act="inc"]').click();
  await desk.waitForFunction(() => [...document.querySelectorAll('[data-f="label"]')].some(i => i.value === 'Pinto beans'
    && i.closest('[data-id]').querySelector('[data-f="quantity"]').value === '2'));
  await row.locator('[data-f="department"]').selectOption('Canned & Jarred');
  await desk.locator('.shop-dept-header', { hasText: 'Canned & Jarred' }).waitFor();
  const moved = desk.locator('.shop-item-row', { has: desk.locator('[data-f="label"][value="Pinto beans"]') });
  await moved.locator('[data-act="more"]').click();
  await moved.locator('[data-f="storeId"]').selectOption({ label: 'Costco' });
  await moved.locator('[data-f="notes"]').fill('25 lb bag');
  await moved.locator('[data-f="notes"]').press('Tab');
  await desk.fill('#add-label', 'paper towels');
  await desk.fill('#add-qty', '1');
  await desk.click('#add-form button[type="submit"]');
  await desk.locator('[data-f="label"][value="paper towels"]').waitFor();
  await desk.screenshot({ path: `${SHOTS}/l3-edit-mode.png`, fullPage: true });
  const list = await apiGet('tok-owner', 'getList', { id: new URL(listUrl).searchParams.get('id') });
  const pinto = list.items.find(i => i.label === 'Pinto beans');
  assert.deepEqual([pinto.quantity, pinto.department, pinto.notes, pinto.edited], [2, 'Canned & Jarred', '25 lb bag', true]);
});

await step('a zero amount means "not needed" — kept, but hidden while shopping', async () => {
  const row = desk.locator('.shop-item-row', { has: desk.locator('[data-f="label"][value="paper towels"]') });
  await row.locator('[data-act="dec"]').click();
  await desk.waitForFunction(() => [...document.querySelectorAll('[data-f="label"]')].some(i => i.value === 'paper towels'
    && i.closest('[data-id]').querySelector('[data-f="quantity"]').value === '0'));
  await desk.click('#mode-btn');
  await desk.locator('.shop-mode-item').first().waitFor();
  assert.equal(await desk.locator('.shop-mode-item', { hasText: 'paper towels' }).count(), 0);
});

await step('shopping mode: tap to check; it drops to "Checked Off"', async () => {
  const pinto = desk.locator('.shop-mode-item', { hasText: 'Pinto beans' });
  await pinto.click();
  await desk.locator('.shop-dept-header.done').waitFor();
  assert.equal(await desk.locator('.shop-dept-header.done ~ .shop-mode-item.checked', { hasText: 'Pinto beans' }).count(), 1);
  await desk.screenshot({ path: `${SHOTS}/l4-shopping-mode.png`, fullPage: true });
});

await step('group by store shows Costco and Unassigned', async () => {
  await desk.click('[data-group="store"]');
  await desk.locator('.shop-dept-header', { hasText: 'Unassigned' }).waitFor();
  await desk.click('[data-group="dept"]');
});

await step('share the list; the other phone sees a check within the polling interval (acceptance 10)', async () => {
  const id = new URL(listUrl).searchParams.get('id');
  await desk.goto(`${BASE}/share.html?type=list&id=${id}`);
  await desk.fill('#who', 'elaine@test');
  await desk.selectOption('#as', 'editor');
  await desk.click('#invite button[type="submit"]');
  await desk.locator('.people', { hasText: 'Elaine' }).waitFor();

  const phone = await newPage({ width: 390, height: 844 }, 'tok-elaine');
  await phone.goto(listUrl);
  await phone.click('#mode-btn');
  await phone.locator('.shop-mode-item').first().waitFor();
  const target = (await phone.locator('.shop-mode-item:not(.checked) .txt').first().textContent()).trim();
  const label = target.replace(/^[\d½¼¾⅓⅔⅛\s.]+(cups?|tbsp|tsp|lb|oz|cans?)?\s*/, '').split(/\s{2,}/)[0];
  // Owner checks it from the API (another device); Elaine's phone must show it without reloading.
  const items = (await apiGet('tok-owner', 'getList', { id })).items;
  const item = items.find(i => target.includes(i.label));
  assert.ok(item, `no item for "${target}" (${label})`);
  const started = Date.now();
  await api('tok-owner', 'setItemChecked', { id: item.id, checked: true });
  await phone.locator(`.shop-mode-item.checked[data-check="${item.id}"]`).waitFor({ timeout: 10000 });
  const took = Date.now() - started;
  assert.ok(took < 6000, `took ${took} ms`);
  await phone.screenshot({ path: `${SHOTS}/l5-phone-shopping.png`, fullPage: true });
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `shopping mode is ${overflow}px wider than the phone`);
  await phone.click('#mode-btn');
  await phone.locator('.shop-item-row').first().waitFor();
  const overflow2 = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow2 <= 0, `edit mode is ${overflow2}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/l6-phone-edit.png` });
  await phone.context().close();
});

await step('an edit on another device does not clobber the field being typed in', async () => {
  await desk.goto(listUrl);
  const row = desk.locator('.shop-item-row', { has: desk.locator('[data-f="label"][value="Pinto beans"]') });
  const id = Number(await row.getAttribute('data-id'));
  const notes = row.locator('[data-f="label"]');
  await notes.click();
  await notes.fill('Pinto beans, dried');            // typing, not yet saved
  const cur = (await apiGet('tok-elaine', 'getList', { id: new URL(listUrl).searchParams.get('id') })).items.find(i => i.id === id);
  await api('tok-elaine', 'updateItem', { id, changes: { quantity: 6 }, base: { quantity: cur.quantity } });
  await desk.waitForTimeout(16500);                   // > one edit-mode poll
  assert.equal(await notes.inputValue(), 'Pinto beans, dried', 'what I was typing survived the update');
  await notes.press('Tab');                     // saves the name; focus is still in this row
  await desk.locator('h1').click();                // moving on: leave the row
  await desk.waitForFunction(id => {
    const r = document.querySelector(`[data-id="${id}"]`);
    return r && r.querySelector('[data-f="quantity"]').value === '6' && r.querySelector('[data-f="label"]').value === 'Pinto beans, dried';
  }, id, { timeout: 8000 });
});

await step('rebuild keeps hand-added items', async () => {
  await desk.locator('#recipes-panel summary').click();
  await desk.click('#rebuild');
  await desk.getByText('List rebuilt').waitFor();
  await desk.locator('[data-f="label"][value="paper towels"]').waitFor();
  await desk.locator('[data-f="label"][value="Pinto beans"]').waitFor();
});

await step('mark as done; it moves to completed lists', async () => {
  await desk.locator('details summary', { hasText: 'More' }).click();
  await desk.click('#complete');
  await desk.getByText('This list is marked done').waitFor();
  await desk.goto(`${BASE}/lists.html`);
  await desk.click('#done-toggle');
  await desk.locator('.tile', { hasText: 'Weekly Groceries' }).waitFor();
  await desk.screenshot({ path: `${SHOTS}/l7-lists.png` });
});

await browser.close();
console.log(results.join('\n'));
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length ? 1 : 0);
