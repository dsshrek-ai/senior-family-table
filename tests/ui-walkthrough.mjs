// Browser walkthrough of the recipe-library screens (headless Edge or Chrome).
// Needs playwright-core, the test data from tests/README.md, and the site served with its API.
//   BASE_URL=http://127.0.0.1:8100 node tests/ui-walkthrough.mjs <screenshot folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8100';
const SHOTS = process.argv[2];
const browser = await chromium.launch({ executablePath: process.env.BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const results = [];
const notFound = [];
const consoleErrors = [];

async function newPage(viewport, token) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) { consoleErrors.push(`${page.url()}: ${m.text()}`); } });
  page.on('pageerror', e => consoleErrors.push(`${page.url()}: ${e.message}`));
  page.on('response', r => { if (r.status() === 404 && !r.url().includes('/api/')) { notFound.push(r.url()); } });
  page.on('dialog', d => d.accept().catch(() => { /* page already moved on */ }));
  if (token) {
    await page.goto(`${BASE}/index.html?token=${token}`);
  }
  return page;
}

async function step(name, fn) {
  try { await fn(); results.push(`PASS  ${name}`); } catch (e) { results.push(`FAIL  ${name} — ${e.message.split('\n')[0]}`); }
}

const desk = await newPage({ width: 1280, height: 900 }, 'tok-owner');
const phone = await newPage({ width: 390, height: 844 }, 'tok-owner');

await step('sign-in gate shows without a token', async () => {
  const p = await newPage({ width: 1000, height: 800 });
  await p.goto(`${BASE}/index.html`);
  await p.getByRole('heading', { name: 'Sign in' }).waitFor();
  await p.fill('#g-email', 'owner@test');
  await p.fill('#g-pw', 'pw');
  await p.click('#g-btn');
  await p.getByRole('heading', { name: 'My Cookbooks' }).waitFor();
  await p.context().close();
});

await step('Hub token is taken out of the address bar', async () => {
  assert.ok(!desk.url().includes('token='));
});

await step('My Cookbooks shows the imported cookbook', async () => {
  await desk.getByText('190 recipes').waitFor();
  await desk.screenshot({ path: `${SHOTS}/1-cookbooks.png`, fullPage: true });
});

await step('cookbook page lists and filters recipes', async () => {
  await desk.click('text=Senior Family Cookbook');
  await desk.getByRole('heading', { name: 'Senior Family Cookbook' }).waitFor();
  assert.equal(await desk.locator('#list li').count(), 190);
  await desk.fill('#filter', 'soup');
  const n = await desk.locator('#list li').count();
  assert.ok(n > 5 && n < 60, `filter left ${n}`);
  await desk.screenshot({ path: `${SHOTS}/2-cookbook.png` });
});

await step('favorite star toggles', async () => {
  const star = desk.locator('[data-fav]').first();
  await star.click();
  await desk.waitForFunction(() => document.querySelector('[data-fav]').textContent === '★');
});

await step('recipe page shows ingredients, steps and facts', async () => {
  await desk.fill('#filter', 'Refried Beans with Lard');
  await desk.click('#list a');
  await desk.getByRole('heading', { name: 'Ingredients' }).waitFor();
  await desk.getByText('½ Tbsp chili powder').waitFor();
  assert.ok(await desk.locator('.steps li').count() > 3);
  await desk.screenshot({ path: `${SHOTS}/3-recipe.png`, fullPage: true });
});

await step('editor saves a change and the recipe shows version 2', async () => {
  await desk.locator('a.btn', { hasText: /^Edit$/ }).click();
  await desk.locator('#title').waitFor();
  await desk.fill('#servings', '10');
  await desk.click('#add-ing');
  const rows = desk.locator('#ings .ing-row');
  const last = rows.last();
  await last.locator('[data-k="quantity"]').fill('2');
  await last.locator('[data-k="unit"]').fill('cups');
  await last.locator('[data-k="name"]').fill('shredded cheese');
  await desk.screenshot({ path: `${SHOTS}/4-editor.png`, fullPage: true });
  await desk.click('#save');
  await desk.getByText('2 cups shredded cheese').waitFor();
  await desk.getByText(/Version 2/).waitFor();
  await desk.getByText('Serves 10').waitFor();
});

await step('editor shows a field error beside a bad amount', async () => {
  await desk.locator('a.btn', { hasText: /^Edit$/ }).click();
  await desk.locator('#title').waitFor();
  await desk.locator('#ings .ing-row').first().locator('[data-k="quantity"]').fill('lots');
  await desk.click('#save');
  await desk.locator('.field-error[data-for="ingredients.0.quantity"]').filter({ hasText: 'number' }).waitFor();
});

await step('editor refuses to overwrite someone else\'s save', async () => {
  // Fix the bad amount, then let Elaine-as-co-owner save underneath us via the API.
  const url = desk.url();
  const id = new URL(url).searchParams.get('id');
  await desk.locator('#ings .ing-row').first().locator('[data-k="quantity"]').fill('1');
  const api = async (token, action, body) => (await fetch(`${BASE}/api/api.php`, { method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'text/plain' }, body: JSON.stringify({ action, ...body }) })).json();
  // owner shares the recipe with elaine as editor, elaine needs app access (seeded) — then elaine saves.
  await api('tok-owner', 'addRecipeMember', { recipeId: Number(id), username: 'elaine@test', role: 'editor' });
  const cur = await (await fetch(`${BASE}/api/api.php?action=getRecipe&id=${id}`, { headers: { Authorization: 'Bearer tok-elaine' } })).json();
  const r = cur.recipe;
  const res = await api('tok-elaine', 'saveRecipe', { id: Number(id), version: r.version, recipe: { ...r, description: 'Elaine was here',
    methods: r.methods.map(m => ({ id: m.id, key: `m${m.id}`, name: m.name })),
    steps: r.steps.map(s => ({ id: s.id, methodKey: s.methodId ? `m${s.methodId}` : '', text: s.text })) } });
  assert.equal(res.ok, true, JSON.stringify(res));
  await desk.click('#save');
  await desk.locator('#conflict').filter({ hasText: 'Elaine saved this recipe' }).waitFor();
  await desk.screenshot({ path: `${SHOTS}/5-conflict.png` });
  await desk.click('#reload-latest');
  await desk.waitForFunction(() => document.getElementById('description')?.value === 'Elaine was here');
});

await step('history lists versions and restores one', async () => {
  const id = new URL(desk.url()).searchParams.get('id');
  await desk.goto(`${BASE}/history.html?id=${id}`);
  await desk.getByText('Version 1').waitFor();
  await desk.locator('[data-view]').last().click();
  await desk.getByText('½ Tbsp chili powder').waitFor();
  await desk.locator('[data-restore]').last().click();
  await desk.locator('.people').getByText('Restored version 1').waitFor();
  await desk.screenshot({ path: `${SHOTS}/6-history.png`, fullPage: true });
});

await step('new recipe from a cookbook, with a cooking method', async () => {
  await desk.goto(`${BASE}/cookbook.html?id=1`);
  await desk.click('text=+ New recipe');
  await desk.locator('#title').waitFor();
  await desk.fill('#title', 'Grandma\'s Test Rolls');
  const rows = desk.locator('#ings .ing-row');
  await rows.nth(0).locator('[data-k="quantity"]').fill('3');
  await rows.nth(0).locator('[data-k="unit"]').fill('cups');
  await rows.nth(0).locator('[data-k="name"]').fill('flour');
  await desk.click('#add-method');
  await desk.locator('#methods [data-k="name"]').fill('Bread machine');
  await desk.locator('#steps textarea').first().fill('Mix everything.');
  await desk.locator('#steps select').nth(1).selectOption({ label: 'Bread machine only' });
  await desk.locator('#steps textarea').nth(1).fill('Use the dough cycle.');
  await desk.click('#save');
  await desk.getByRole('heading', { name: 'Grandma\'s Test Rolls' }).waitFor();
  await desk.getByRole('heading', { name: 'Bread machine' }).waitFor();
});

await step('add the recipe to a second cookbook from the recipe page', async () => {
  const id = new URL(desk.url()).searchParams.get('id');
  await desk.goto(`${BASE}/index.html`);
  await desk.click('#new-btn');
  await desk.fill('#nb-title', 'Breads');
  await desk.click('#new-form button[type="submit"]');
  await desk.getByRole('heading', { name: 'Breads' }).waitFor();
  await desk.goto(`${BASE}/recipe.html?id=${id}`);
  await desk.selectOption('#add-book', { label: 'Breads' });
  await desk.click('#add-book-btn');
  await desk.locator('a', { hasText: 'Breads' }).first().waitFor();
});

await step('share a cookbook by Hub email', async () => {
  await desk.goto(`${BASE}/share.html?type=cookbook&id=1`);
  await desk.fill('#who', 'nobody@test');
  await desk.click('#invite button[type="submit"]');
  await desk.locator('.field-error[data-for="username"]').filter({ hasText: 'sign up' }).waitFor();
  await desk.fill('#who', 'michelle@test');
  await desk.click('#invite button[type="submit"]');
  await desk.getByText('Michelle').waitFor();
  await desk.screenshot({ path: `${SHOTS}/7-share.png`, fullPage: true });
});

await step('Michelle (viewer) sees the cookbook but no Edit button', async () => {
  const m = await newPage({ width: 1000, height: 800 }, 'tok-michelle');
  await m.getByRole('heading', { name: 'Senior Family Cookbook' }).waitFor();
  await m.goto(`${BASE}/recipe.html?id=1`);
  await m.getByRole('heading', { name: 'Ingredients' }).waitFor();
  assert.equal(await m.locator('a.btn', { hasText: 'Edit' }).count(), 0);
  await m.context().close();
});

await step('search across all cookbooks', async () => {
  await desk.goto(`${BASE}/search.html`);
  await desk.fill('#q', 'chili powder');
  await desk.getByText('Refried Beans with Lard (Instant Pot)').waitFor();
});

await step('phone: bottom nav, cookbook and recipe fit the screen', async () => {
  await phone.goto(`${BASE}/cookbook.html?id=1`);
  await phone.locator('.bottom-nav').waitFor();
  await phone.locator('#list li').first().waitFor();
  await phone.screenshot({ path: `${SHOTS}/8-phone-cookbook.png` });
  await phone.goto(`${BASE}/recipe.html?id=1`);
  await phone.getByRole('heading', { name: 'Ingredients' }).waitFor();
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `page is ${overflow}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/9-phone-recipe.png`, fullPage: true });
  await phone.goto(`${BASE}/recipe-edit.html?id=1`);
  await phone.locator('#title').waitFor();
  const overflow2 = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow2 <= 0, `editor is ${overflow2}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/10-phone-editor.png` });
});

await browser.close();
console.log(results.join('\n'));
console.log(notFound.length ? `\nMissing files: ${notFound.join(', ')}` : '\nNo missing files.');
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length || notFound.length ? 1 : 0);
