// Browser walkthrough of recipe and note photos (headless Edge or Chrome).
// Needs playwright-core and a FRESH test database (tests/README.md); the site served with its API
// (PHP with GD, and PHOTO_DIR / PHOTO_URL_KEY in the test config).
//   BASE_URL=http://127.0.0.1:8100 node tests/ui-photos.mjs <output folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { png } from './png.mjs';

// See ui-notes.mjs: a download's hidden page closing is reported as TargetClosedError.
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
  page.on('dialog', d => d.accept().catch(() => { /* page already moved on */ }));
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

// Every <img> in the selector has actually loaded.
async function imagesLoaded(page, selector) {
  await page.locator(selector).first().waitFor();
  await page.waitForFunction(sel => [...document.querySelectorAll(sel)].every(i => i.complete), selector);
  const sizes = await page.locator(selector).evaluateAll(imgs => imgs.map(i => i.naturalWidth));
  assert.ok(sizes.length > 0, `no images for ${selector}`);
  assert.ok(sizes.every(w => w > 0), `an image didn't load: ${selector}`);
}
async function choose(page, trigger, files) {
  const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click(trigger)]);
  await chooser.setFiles(files);
}
const file = (name, w, h) => ({ name, mimeType: 'image/png', buffer: png(w, h) });

const beans = (await apiGet('tok-owner', 'searchRecipes', { q: 'Refried Beans with Lard' })).recipes[0].id;
await api('tok-owner', 'addCookbookMember', { cookbookId: 1, username: 'elaine@test', role: 'viewer' });
const desk = await newPage({ width: 1280, height: 900 }, 'tok-owner');
const recipeUrl = `${BASE}/recipe.html?id=${beans}&cookbook=1`;

await step('add two photos: the first becomes the cover, both are shrunk before upload', async () => {
  await desk.goto(recipeUrl);
  await choose(desk, '#add-photos', [file('beans.png', 3000, 2000), file('bowl.png', 900, 1200)]);
  await desk.locator('.photo-strip button').nth(1).waitFor();
  await imagesLoaded(desk, '#hero img, .photo-strip img');
  const photos = (await apiGet('tok-owner', 'getRecipe', { id: beans })).recipe.photos;
  assert.equal(photos.length, 2);
  assert.equal(photos[0].width, 1600);
  assert.ok(Math.abs(photos[0].height - 1067) <= 1, `height ${photos[0].height}`);
  assert.deepEqual([photos[1].width, photos[1].height], [900, 1200]);
  await desk.screenshot({ path: `${OUT}/p1-recipe.png` });
});

await step('the viewer opens full size, steps through, and closes with Esc', async () => {
  await desk.click('#hero button');
  await desk.locator('.viewer figcaption', { hasText: '1 / 2' }).waitFor();
  await desk.keyboard.press('ArrowRight');
  await desk.locator('.viewer figcaption', { hasText: '2 / 2' }).waitFor();
  await imagesLoaded(desk, '.viewer img');
  await desk.screenshot({ path: `${OUT}/p2-viewer.png` });
  await desk.keyboard.press('Escape');
  assert.equal(await desk.locator('.viewer').count(), 0);
});

await step('arrange: caption, make the second photo the cover, remove one', async () => {
  await desk.click('#arrange');
  const cards = desk.locator('.photo-card');
  await cards.nth(1).locator('[data-cap]').fill('Ready to serve');
  await cards.nth(1).locator('[data-cap]').press('Tab');
  await desk.getByText('Caption saved').waitFor();
  await cards.nth(1).locator('[data-first]').click();
  await desk.getByText('Cover changed').waitFor();
  assert.equal(await cards.nth(0).locator('[data-cap]').inputValue(), 'Ready to serve');
  await desk.screenshot({ path: `${OUT}/p3-arrange.png`, fullPage: true });
  await cards.nth(1).locator('[data-del]').click();
  await desk.getByText('Photo removed').waitFor();
  assert.equal(await cards.count(), 1);
  await desk.click('#arrange');
  assert.equal(await desk.locator('#hero img').getAttribute('alt'), 'Ready to serve');
  assert.equal(await desk.locator('.photo-strip').count(), 0);
});

await step('the cookbook list shows the cover beside the recipe', async () => {
  await desk.goto(`${BASE}/cookbook.html?id=1`);
  const row = desk.locator('.recipe-list li', { has: desk.locator(`a[href^="recipe.html?id=${beans}&"]`) });
  await imagesLoaded(desk, `.recipe-list li:has(a[href^="recipe.html?id=${beans}&"]) img.thumb`);
  assert.equal(await row.locator('img.thumb').count(), 1);
  assert.ok(await desk.locator('.recipe-list .thumb.none').count() > 100, 'recipes without photos keep a placeholder');
});

await step('a note with a photo; editing it removes the photo', async () => {
  await desk.goto(recipeUrl);
  await desk.fill('#note-text', 'Here is how mine turned out');
  await choose(desk, '#note-add-photo', [file('mine.png', 800, 600)]);
  await desk.locator('#note-pending img').waitFor();
  await desk.click('#note-form button[type="submit"]');
  await desk.getByText('Note added').waitFor();
  const note = desk.locator('.note', { hasText: 'Here is how mine turned out' });
  await imagesLoaded(desk, '.note .note-photos img');
  assert.equal(await desk.locator('#note-pending img').count(), 0, 'chosen photos are cleared');
  await note.locator('[data-np]').click();
  await desk.locator('.viewer img').waitFor();
  await desk.keyboard.press('Escape');
  await note.locator('[data-note-edit]').click();
  await desk.locator('.edit-photos [data-rm]').click();
  await desk.locator('.edit-photos img').waitFor({ state: 'detached' });
  await desk.locator('[data-save]').click();
  await desk.locator('[data-save]').waitFor({ state: 'detached' });
  assert.equal(await desk.locator('.note .note-photos').count(), 0, 'no photos after saving');
});

await step('a viewer sees the photos but has no photo buttons', async () => {
  const ela = await newPage({ width: 1280, height: 900 }, 'tok-elaine');
  await ela.goto(recipeUrl);
  await imagesLoaded(ela, '#hero img');
  assert.equal(await ela.locator('#add-photos').count(), 0);
  assert.equal(await ela.locator('#arrange').count(), 0);
  await ela.context().close();
});

await step('Send as PDF puts the cover photo at the top', async () => {
  await desk.goto(recipeUrl);
  await desk.locator('#hero img').waitFor();
  const [download] = await Promise.all([desk.waitForEvent('download'), desk.click('#send-pdf')]);
  const path = `${OUT}/photo-recipe.pdf`;
  await download.saveAs(path);
  const text = readFileSync(path).toString('latin1');
  assert.ok(text.startsWith('%PDF-'));
  assert.ok(/\/Subtype\s*\/Image/.test(text), 'no image in the PDF');
});

await step('phone: the photo fits, and the viewer fills the screen', async () => {
  const phone = await newPage({ width: 390, height: 844 }, 'tok-owner');
  await phone.goto(recipeUrl);
  await imagesLoaded(phone, '#hero img');
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `${overflow}px wider than the phone`);
  await phone.screenshot({ path: `${OUT}/p4-phone.png` });
  await phone.click('#hero button');
  await imagesLoaded(phone, '.viewer img');
  const box = await phone.locator('.viewer img').boundingBox();
  assert.ok(box.width > 350, 'photo should fill the phone width');
  await phone.screenshot({ path: `${OUT}/p5-phone-viewer.png` });
  await phone.context().close();
});

await browser.close();
console.log(results.join('\n'));
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length ? 1 : 0);
