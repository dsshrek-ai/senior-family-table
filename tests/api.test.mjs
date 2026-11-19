// Permission and foundation tests for api/api.php (docs/ARCHITECTURE.md §3, §10).
//
// Needs: a test database built from my-apps-hub/api/schema.sql, the Senior Family Cookbook
// schema and data, and api/schema.sql; the Senior Family import already run as owner@test;
// users owner@test (Hub admin), elaine@test, michelle@test (no app access yet), outsider@test,
// with session tokens tok-owner, tok-elaine, tok-michelle, tok-outsider.
//
//   API_URL=http://127.0.0.1:8099/api.php SQL="mariadb -h127.0.0.1 -P3307 -uroot -proot sft_test" node tests/api.test.mjs

import { execSync } from 'node:child_process';
import assert from 'node:assert/strict';

const API = process.env.API_URL;
const SQL = process.env.SQL;
if (!API || !SQL) { console.error('Set API_URL and SQL'); process.exit(2); }

function sql(statement) {
  return execSync(`${SQL} -N -B`, { input: statement, encoding: 'utf8' }).trim();
}

async function call(who, action, { params = {}, body = null } = {}) {
  const url = new URL(API);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) { url.searchParams.set(k, String(v)); }
  const headers = { 'Content-Type': 'application/json' };
  if (who) { headers.Authorization = `Bearer tok-${who}`; }
  const res = await fetch(url, { method: body ? 'POST' : 'GET', headers, body: body ? JSON.stringify(body) : undefined });
  return { status: res.status, data: await res.json() };
}

const results = [];
async function test(name, fn) {
  try {
    await fn();
    results.push(['PASS', name]);
  } catch (e) {
    results.push(['FAIL', name, e.message]);
  }
}

const userId = name => Number(sql(`SELECT id FROM users WHERE username = '${name}@test'`));
const ownerId = userId('owner');
const elaineId = userId('elaine');
const michelleId = userId('michelle');
const familyBook = Number(sql("SELECT id FROM sft_cookbooks WHERE title = 'Senior Family Cookbook' AND deleted_at IS NULL"));
const soup = Number(sql("SELECT id FROM sft_recipes WHERE title LIKE 'Refried Beans with Lard%'"));
let elaineBook = 0;

// ---- Sign-in and app access ----------------------------------------------

await test('no token → 401', async () => {
  assert.equal((await call(null, 'whoAmI')).status, 401);
});
await test('bad token → 401', async () => {
  const url = new URL(API); url.searchParams.set('action', 'whoAmI');
  const res = await fetch(url, { headers: { Authorization: 'Bearer nope' } });
  assert.equal(res.status, 401);
});
await test('signed in but no app access → 403', async () => {
  assert.equal((await call('michelle', 'whoAmI')).status, 403);
});
await test('owner whoAmI → admin', async () => {
  const r = await call('owner', 'whoAmI');
  assert.equal(r.status, 200);
  assert.equal(r.data.isAdmin, true);
});
await test('unknown action → 404', async () => {
  assert.equal((await call('owner', 'nope')).status, 404);
});
await test('POST-only action via GET → 405', async () => {
  assert.equal((await call('owner', 'createCookbook')).status, 405);
});

// ---- Imported cookbook -----------------------------------------------------

await test('owner sees the imported cookbook with 189 recipes', async () => {
  const r = await call('owner', 'listCookbooks');
  const book = r.data.cookbooks.find(c => c.id === familyBook);
  assert.ok(book, 'cookbook missing');
  assert.equal(book.recipeCount, 189);
  assert.equal(book.role, 'owner');
});
await test('imported recipe reads back with fixed quantities and tags', async () => {
  const r = await call('owner', 'getRecipe', { params: { id: soup } });
  assert.equal(r.status, 200);
  const chili = r.data.recipe.ingredients.find(i => i.name === 'chili powder');
  assert.equal(chili.quantity, 0.5);
  assert.equal(chili.unit, 'tbsp');
  assert.equal(r.data.recipe.canEdit, true);
  assert.ok(r.data.recipe.tags.some(t => t.name === 'Instant Pot'));
});

// ---- Outsider (has app access, shares nothing) ------------------------------

await test('outsider: cookbook, members and recipe all "not found"', async () => {
  assert.equal((await call('outsider', 'getCookbook', { params: { id: familyBook } })).status, 404);
  assert.equal((await call('outsider', 'cookbookMembers', { params: { id: familyBook } })).status, 404);
  assert.equal((await call('outsider', 'getRecipe', { params: { id: soup } })).status, 404);
});
await test('outsider: listCookbooks is empty', async () => {
  assert.deepEqual((await call('outsider', 'listCookbooks')).data.cookbooks, []);
});
await test('outsider cannot add themselves to a cookbook', async () => {
  const r = await call('outsider', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'outsider@test', role: 'owner' } });
  assert.equal(r.status, 404);
});

// ---- Sharing a cookbook -----------------------------------------------------

await test('invite unknown username → 404 with field', async () => {
  const r = await call('owner', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'ghost@test', role: 'viewer' } });
  assert.equal(r.status, 404);
  assert.equal(r.data.field, 'username');
});
await test('owner invites Elaine as viewer', async () => {
  const r = await call('owner', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'elaine@test', role: 'viewer' } });
  assert.equal(r.status, 200);
});
await test('inviting twice → 409', async () => {
  const r = await call('owner', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'elaine@test', role: 'viewer' } });
  assert.equal(r.status, 409);
});
await test('viewer Elaine can read the recipe but not edit it', async () => {
  const r = await call('elaine', 'getRecipe', { params: { id: soup } });
  assert.equal(r.status, 200);
  assert.equal(r.data.recipe.canEdit, false);
  assert.equal(r.data.recipe.myRole, null);
});
await test('viewer cannot rename, delete, or invite', async () => {
  assert.equal((await call('elaine', 'updateCookbook', { body: { id: familyBook, title: 'Mine' } })).status, 403);
  assert.equal((await call('elaine', 'deleteCookbook', { body: { id: familyBook } })).status, 403);
  assert.equal((await call('elaine', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'outsider@test', role: 'viewer' } })).status, 403);
});
await test('inviting Michelle grants her app access', async () => {
  const r = await call('owner', 'addCookbookMember', { body: { cookbookId: familyBook, username: 'michelle@test', role: 'viewer' } });
  assert.equal(r.status, 200);
  assert.equal((await call('michelle', 'whoAmI')).status, 200);
});

// ---- Bounded visibility (acceptance test 3) ---------------------------------

await test('Elaine creates a private cookbook the owner cannot see', async () => {
  const r = await call('elaine', 'createCookbook', { body: { title: 'Freezer Meals' } });
  assert.equal(r.status, 200);
  elaineBook = r.data.id;
  assert.equal((await call('owner', 'getCookbook', { params: { id: elaineBook } })).status, 404);
  assert.ok(!(await call('owner', 'listCookbooks')).data.cookbooks.some(c => c.id === elaineBook));
});
await test('a recipe in both cookbooks only lists the cookbooks you belong to', async () => {
  sql(`INSERT INTO sft_cookbook_recipes (cookbook_id, recipe_id, added_by) VALUES (${elaineBook}, ${soup}, ${elaineId})`);
  const owner = await call('owner', 'getRecipe', { params: { id: soup } });
  assert.deepEqual(owner.data.recipe.cookbooks.map(c => c.id), [familyBook]);
  const elaine = await call('elaine', 'getRecipe', { params: { id: soup } });
  assert.deepEqual(elaine.data.recipe.cookbooks.map(c => c.id).sort(), [familyBook, elaineBook].sort());
  const michelle = await call('michelle', 'getRecipe', { params: { id: soup } });
  assert.deepEqual(michelle.data.recipe.cookbooks.map(c => c.id), [familyBook]);
});
await test('private notes are only returned to their author — even to a co-owner', async () => {
  sql(`INSERT INTO sft_recipe_notes (recipe_id, user_id, visibility, note_text) VALUES
       (${soup}, ${ownerId}, 'private', 'owner secret'),
       (${soup}, ${elaineId}, 'private', 'elaine secret'),
       (${soup}, ${elaineId}, 'shared', 'elaine shared')`);
  const texts = async who => (await call(who, 'getRecipe', { params: { id: soup } })).data.recipe.notes.map(n => n.text).sort();
  assert.deepEqual(await texts('owner'), ['elaine shared', 'owner secret']);
  assert.deepEqual(await texts('elaine'), ['elaine secret', 'elaine shared']);
  assert.deepEqual(await texts('michelle'), ['elaine shared']);
});
await test('cookbook-scoped tags are hidden from non-members', async () => {
  sql(`INSERT INTO sft_categories (name, normalized_name, kind, scope, cookbook_id) VALUES ('Elaine Only', 'elaine only', 'descriptor', 'cookbook', ${elaineBook})`);
  sql(`INSERT INTO sft_recipe_categories (recipe_id, category_id, added_by) SELECT ${soup}, id, ${elaineId} FROM sft_categories WHERE normalized_name = 'elaine only'`);
  const tags = async who => (await call(who, 'getRecipe', { params: { id: soup } })).data.recipe.tags.map(t => t.name);
  assert.ok(!(await tags('owner')).includes('Elaine Only'));
  assert.ok((await tags('elaine')).includes('Elaine Only'));
});

// ---- Owners ----------------------------------------------------------------

await test('last owner cannot demote or remove themselves', async () => {
  assert.equal((await call('owner', 'setCookbookMemberRole', { body: { cookbookId: familyBook, userId: ownerId, role: 'viewer' } })).status, 409);
  assert.equal((await call('owner', 'removeCookbookMember', { body: { cookbookId: familyBook, userId: ownerId } })).status, 409);
});
await test('after promoting Elaine, the owner can step down', async () => {
  assert.equal((await call('owner', 'setCookbookMemberRole', { body: { cookbookId: familyBook, userId: elaineId, role: 'owner' } })).status, 200);
  assert.equal((await call('owner', 'setCookbookMemberRole', { body: { cookbookId: familyBook, userId: ownerId, role: 'editor' } })).status, 200);
  assert.equal((await call('elaine', 'setCookbookMemberRole', { body: { cookbookId: familyBook, userId: ownerId, role: 'owner' } })).status, 200);
});
await test('a member can leave; then loses access', async () => {
  assert.equal((await call('michelle', 'removeCookbookMember', { body: { cookbookId: familyBook, userId: michelleId } })).status, 200);
  assert.equal((await call('michelle', 'getRecipe', { params: { id: soup } })).status, 404);
});
await test('role changes and removals are audited', async () => {
  const n = Number(sql(`SELECT COUNT(*) FROM sft_audit_events WHERE resource_type = 'cookbook' AND resource_id = ${familyBook} AND action IN ('member_added','role_changed','member_left')`));
  assert.ok(n >= 6, `only ${n} audit events`);
});

// ---- Deletion --------------------------------------------------------------

await test('deleting a cookbook hides it but never deletes recipes', async () => {
  assert.equal((await call('elaine', 'deleteCookbook', { body: { id: elaineBook } })).status, 200);
  assert.equal((await call('elaine', 'getCookbook', { params: { id: elaineBook } })).status, 404);
  assert.equal((await call('elaine', 'getRecipe', { params: { id: soup } })).status, 200); // still via the family cookbook
  assert.equal(Number(sql(`SELECT COUNT(*) FROM sft_recipes WHERE id = ${soup} AND deleted_at IS NULL`)), 1);
});

// ---- Admin import ----------------------------------------------------------

await test('only a Hub admin can run the import', async () => {
  assert.equal((await call('elaine', 'adminImportSeniorFamily', { body: { dryRun: true } })).status, 403);
});
await test('admin import over the API is safe to repeat', async () => {
  const r = await call('owner', 'adminImportSeniorFamily', { body: { dryRun: true } });
  assert.equal(r.status, 200);
  assert.equal(r.data.report.imported, 0);
  assert.equal(r.data.report.alreadyImported, 189);
});

// ----------------------------------------------------------------------------

for (const [status, name, msg] of results) { console.log(`${status}  ${name}${msg ? ' — ' + msg : ''}`); }
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
