// Browser walkthrough of "Paste a recipe" (headless Edge or Chrome).
// Needs playwright-core and a FRESH test database (tests/README.md); the site served with its API.
//   BASE_URL=http://127.0.0.1:8100 node tests/ui-import.mjs <screenshot folder>
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
  page.on('dialog', d => d.accept().catch(() => { /* page already moved on */ }));
  if (token) { await page.goto(`${BASE}/index.html?token=${token}`); }
  return page;
}
async function step(name, fn) {
  try { await fn(); results.push(`PASS  ${name}`); } catch (e) { results.push(`FAIL  ${name} — ${e.message.split('\n')[0]}`); }
}

const SOUP = `Grandma's Test Meatball Soup
A hearty family favorite.
Serves 6
Prep Time: 20 minutes

Ingredients
1 lb frozen meatballs
1 (28 oz) can crushed tomatoes
2-3 carrots, sliced
Juice of 1 lemon
Salt and pepper to taste

For serving:
Grated Parmesan cheese

Instant Pot Instructions
1. Saute the onion for 3 minutes.
2. Pressure cook on High for 10 minutes.

Slow Cooker Instructions
1. Cook on Low for 6-8 hours.

Notes
Freezes well.`;

const desk = await newPage({ width: 1280, height: 900 }, 'tok-owner');
let newId = 0;

await step('cookbook → Paste a recipe; the paste survives a reload', async () => {
  await desk.goto(`${BASE}/cookbook.html?id=1`);
  await desk.click('text=📋 Paste a recipe');
  await desk.locator('#text').waitFor();
  assert.equal(await desk.inputValue('#book'), '1');
  await desk.fill('#text', SOUP);
  await desk.reload();
  await desk.locator('#text').waitFor();
  assert.equal(await desk.inputValue('#text'), SOUP);
});

await step('Read recipe → the editor is filled in, with flags and the original text beside it', async () => {
  await desk.click('#read');
  await desk.getByRole('heading', { name: 'Check the pasted recipe' }).waitFor();
  assert.equal(await desk.inputValue('#title'), "Grandma's Test Meatball Soup");
  assert.equal(await desk.inputValue('#servings'), '6');
  assert.equal(await desk.inputValue('#pm'), '20');
  assert.equal(await desk.locator('#ings .ing-row').count(), 6);
  assert.equal(await desk.locator('#methods [data-k="name"]').count(), 2);
  await desk.locator('[data-flag]', { hasText: 'Juice of 1 lemon' }).waitFor();
  await desk.locator('#original pre', { hasText: 'Freezes well.' }).waitFor();
  await desk.screenshot({ path: `${SHOTS}/i1-review.png`, fullPage: true });
});

await step('fixing a flagged line clears its "Check this" mark', async () => {
  // Find the row by position: its mark disappears as soon as it is edited.
  const idx = await desk.locator('#ings .ing-row').evaluateAll(rows => rows.findIndex(r => r.querySelector('[data-flag]')));
  const row = desk.locator('#ings .ing-row').nth(idx);
  await row.locator('[data-k="quantity"]').fill('1');
  await row.locator('[data-k="name"]').fill('lemon');
  await row.locator('[data-k="preparation"]').fill('juiced');
  assert.equal(await desk.locator('[data-flag]').count(), 0);
});

await step('save → the recipe exists, with both methods and the original text in History', async () => {
  await desk.click('#save');
  await desk.getByRole('heading', { name: "Grandma's Test Meatball Soup" }).waitFor();
  newId = Number(new URL(desk.url()).searchParams.get('id'));
  await desk.getByText('1 can (28 oz) crushed tomatoes').waitFor();
  await desk.getByText('1 lemon (juiced)').waitFor();
  await desk.getByRole('heading', { name: 'Instant Pot' }).waitFor();
  await desk.getByRole('heading', { name: 'Slow Cooker' }).waitFor();
  await desk.goto(`${BASE}/history.html?id=${newId}`);
  await desk.getByText('Imported from pasted text').waitFor();
  await desk.locator('#pasted summary').click();
  await desk.locator('#pasted pre', { hasText: 'Juice of 1 lemon' }).waitFor();
});

await step('after saving, the paste box is empty again', async () => {
  await desk.goto(`${BASE}/import.html?cookbook=1`);
  await desk.locator('#text').waitFor();
  assert.equal(await desk.inputValue('#text'), '');
});

await step('a recipe you already have is offered instead — and adding it makes no copy', async () => {
  await desk.goto(`${BASE}/index.html`);
  await desk.click('#new-btn');
  await desk.fill('#nb-title', 'Paste Test');
  await desk.click('#new-form button[type="submit"]');
  await desk.getByRole('heading', { name: 'Paste Test' }).waitFor();
  const bookId = new URL(desk.url()).searchParams.get('id');
  await desk.click('text=📋 Paste a recipe');
  await desk.locator('#text').waitFor();
  await desk.fill('#text', 'Refried Beans with Lard (Instant Pot)\nServes 6\n\nIngredients\n2 cups pinto beans\n\nDirections\n1. Cook.');
  await desk.click('#read');
  await desk.locator('#similar').waitFor();
  await desk.screenshot({ path: `${SHOTS}/i2-similar.png` });
  await desk.locator('#similar li', { hasText: 'Same title' }).locator('[data-use-existing]').click();
  await desk.getByRole('heading', { name: 'Refried Beans with Lard (Instant Pot)' }).waitFor();
  await desk.goto(`${BASE}/cookbook.html?id=${bookId}`);
  await desk.locator('#list li').first().waitFor();
  assert.equal(await desk.locator('#list li').count(), 1);
});

await step('"Type it in myself" keeps the pasted text beside an empty editor', async () => {
  await desk.goto(`${BASE}/import.html?cookbook=1`);
  await desk.locator('#text').waitFor();
  await desk.fill('#text', 'some notes from a card: flour, sugar, bake till done');
  await desk.click('#manual');
  await desk.getByText('Type the recipe in below.').waitFor();
  assert.equal(await desk.inputValue('#title'), '');
  await desk.locator('#original pre', { hasText: 'bake till done' }).waitFor();
});

await step('phone: the review screen fits', async () => {
  const phone = await newPage({ width: 390, height: 844 }, 'tok-owner');
  await phone.goto(`${BASE}/import.html?cookbook=1`);
  await phone.locator('#text').waitFor();
  await phone.fill('#text', SOUP);
  await phone.click('#read');
  await phone.getByRole('heading', { name: 'Check the pasted recipe' }).waitFor();
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `${overflow}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/i3-phone.png` });
  await phone.context().close();
});

await browser.close();
console.log(results.join('\n'));
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length ? 1 : 0);
