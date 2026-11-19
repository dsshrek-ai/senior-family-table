// Browser walkthrough of family notes, tags, and "Send as PDF" (headless Edge or Chrome).
// Needs playwright-core and a FRESH test database (tests/README.md); the site served with its API.
//   BASE_URL=http://127.0.0.1:8100 node tests/ui-notes.mjs <output folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// A download can briefly open a hidden page; Playwright 1.48 reports its closing as an
// uncaught TargetClosedError. It is harmless for this test, so only that one error is ignored.
process.on('uncaughtException', e => { if (e && e.name === 'TargetClosedError') { return; } console.error(e); process.exit(1); });

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8100';
const OUT = process.argv[2];
const browser = await chromium.launch({ executablePath: process.env.BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const results = [];
const consoleErrors = [];

async function newPage(viewport, token) {
  const ctx = await browser.newContext({ viewport, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) { consoleErrors.push(`${page.url()}: ${m.text()}`); } });
  page.on('pageerror', e => consoleErrors.push(`${page.url()}: ${e.message}`));
  page.on('dialog', d => d.accept(d.type() === 'prompt' ? (page.nextPrompt || '') : undefined).catch(() => { /* page already moved on */ }));
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

const beans = (await apiGet('tok-owner', 'searchRecipes', { q: 'Refried Beans with Lard' })).recipes[0].id;
await api('tok-owner', 'addCookbookMember', { cookbookId: 1, username: 'elaine@test', role: 'viewer' });
const desk = await newPage({ width: 1280, height: 900 }, 'tok-owner');
const recipeUrl = `${BASE}/recipe.html?id=${beans}&cookbook=1`;

await step('add a shared note and a private note', async () => {
  await desk.goto(recipeUrl);
  await desk.locator('#note-text').waitFor();
  await desk.fill('#note-text', 'Grandma soaked the beans overnight.');
  await desk.click('#note-form button[type="submit"]');
  await desk.locator('.note', { hasText: 'Grandma soaked the beans overnight.' }).waitFor();
  await desk.fill('#note-text', 'I use half the lard.');
  await desk.check('#note-form input[value="private"]');
  await desk.click('#note-form button[type="submit"]');
  await desk.locator('.note.private', { hasText: 'Private — only you can see this' }).waitFor();
});

await step('another person sees the shared note but never the private one', async () => {
  const e = await newPage({ width: 1000, height: 800 }, 'tok-elaine');
  await e.goto(recipeUrl);
  await e.locator('.note', { hasText: 'Grandma soaked' }).waitFor();
  assert.equal(await e.locator('.note', { hasText: 'half the lard' }).count(), 0);
  assert.equal(await e.locator('.note [data-note-edit]').count(), 0, 'no Edit on someone else\'s note');
  await e.fill('#note-text', 'Elaine: great with tortillas.');
  await e.click('#note-form button[type="submit"]');
  await e.locator('.note', { hasText: 'great with tortillas' }).waitFor();
  await e.context().close();
});

await step('edit and delete your own note; a co-owner can remove a shared note', async () => {
  await desk.reload();
  const mine = desk.locator('.note', { hasText: 'Grandma soaked' });
  await mine.locator('[data-note-edit]').click();
  await mine.locator('textarea').fill('Grandma soaked the pinto beans overnight.');
  await mine.locator('button', { hasText: 'Save' }).click();
  await desk.locator('.note', { hasText: 'Grandma soaked the pinto beans overnight.' }).waitFor();
  const hers = desk.locator('.note', { hasText: 'great with tortillas' });
  assert.equal(await hers.locator('[data-note-del]').textContent(), 'Remove');
  await hers.locator('[data-note-del]').click();
  await desk.locator('.note', { hasText: 'great with tortillas' }).waitFor({ state: 'detached' });
});

await step('tags: built-in, a new cookbook tag, and a personal tag', async () => {
  await desk.click('#tags-btn');
  await desk.locator('#tags-box h3').waitFor();
  await desk.locator('#tags-box label', { hasText: 'Dessert' }).locator('input').check();
  const bookNew = desk.locator('#tags-box [data-find="b1"]');
  assert.ok(await desk.locator('#tags-box label').count() < 40, 'only tags on the recipe are listed, not all ~240');
  await bookNew.fill('christ');
  await desk.locator('#tags-box [data-matches="b1"] label', { hasText: 'Christmas' }).waitFor();
  await bookNew.fill('family reunion');
  await bookNew.press('Enter');
  await desk.locator('#tags-box label', { hasText: 'Family Reunion' }).locator('input:checked').waitFor();
  const mineNew = desk.locator('#tags-box [data-find="mine"]');
  await mineNew.fill('make again');
  await mineNew.press('Enter');
  await desk.locator('#tags-box label', { hasText: 'Make Again' }).locator('input:checked').waitFor();
  await desk.screenshot({ path: `${OUT}/n1-tags.png`, fullPage: true });
  await desk.click('#tags-done');
  await desk.locator('.recipe-head .pill', { hasText: 'Dessert' }).waitFor();
  await desk.locator('.recipe-head .pill', { hasText: 'Family Reunion' }).waitFor();
  await desk.locator('.recipe-head .pill.muted', { hasText: 'Make Again' }).waitFor();
});

await step('a viewer cannot change built-in tags', async () => {
  const e = await newPage({ width: 1000, height: 800 }, 'tok-elaine');
  await e.goto(recipeUrl);
  await e.click('#tags-btn');
  await e.locator('#tags-box h3').waitFor();
  assert.ok(await e.locator('#tags-box label', { hasText: 'Dessert' }).locator('input').isDisabled());
  assert.equal(await e.locator('#tags-box label', { hasText: 'Make Again' }).count(), 0, 'not someone else\'s personal tag');
  await e.context().close();
});

await step('cookbook: filter by several tags at once', async () => {
  await desk.goto(`${BASE}/cookbook.html?id=1`);
  await desk.locator('#tag-pick').waitFor();
  await desk.selectOption('#tag-pick', 'Family Reunion');
  await desk.locator('#list li').first().waitFor();
  assert.equal(await desk.locator('#list li').count(), 1);
  await desk.selectOption('#tag-pick', 'Main Dishes');
  assert.equal(await desk.locator('#list li').count(), 0, 'Family Reunion AND Main Dishes');
  await desk.click('[data-untag="Main Dishes"]');
  assert.equal(await desk.locator('#list li').count(), 1);
});

await step('cookbook: rename a cookbook tag', async () => {
  await desk.click('#tags-manage');
  await desk.locator('#tags-panel h3').waitFor();
  desk.nextPrompt = 'Holiday';
  await desk.locator('#tags-panel li', { hasText: 'Family Reunion' }).locator('[data-tag-rename]').click();
  await desk.locator('#tags-panel li', { hasText: 'Holiday' }).waitFor();
});

let pdfText = '';
await step('Send as PDF: a real PDF with the scaled recipe and no private notes', async () => {
  await desk.goto(recipeUrl);
  await desk.locator('#scale-input').waitFor();
  await desk.fill('#scale-input', '12');
  await desk.locator('#scale-input').press('Enter');
  await desk.getByText('1 tbsp chili powder').waitFor();
  const [download] = await Promise.all([desk.waitForEvent('download'), desk.click('#send-pdf')]);
  assert.equal(download.suggestedFilename(), 'Refried-Beans-with-Lard-Instant-Pot.pdf');
  const path = `${OUT}/recipe.pdf`;
  await download.saveAs(path);
  const bytes = readFileSync(path);
  assert.equal(bytes.subarray(0, 5).toString(), '%PDF-');
  pdfText = bytes.toString('latin1');
  for (const want of ['Refried Beans with Lard', '1 tbsp chili powder', '4 cups Pinto beans', 'Makes 12 servings', 'From The Senior Family Table']) {
    assert.ok(pdfText.includes(want), `PDF is missing "${want}"`);
  }
  assert.ok(!pdfText.includes('half the lard'), 'private note must not be in the PDF');
});

await step('phone: notes and tags fit the screen', async () => {
  const phone = await newPage({ width: 390, height: 844 }, 'tok-owner');
  await phone.goto(recipeUrl);
  await phone.locator('#note-text').waitFor();
  await phone.click('#tags-btn');
  await phone.locator('#tags-box h3').waitFor();
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `${overflow}px wider than the phone`);
  await phone.screenshot({ path: `${OUT}/n2-phone.png`, fullPage: true });
  await phone.context().close();
});

await browser.close();
console.log(results.join('\n'));
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length ? 1 : 0);
