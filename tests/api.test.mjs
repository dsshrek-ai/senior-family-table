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
import { png } from './png.mjs';

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

await test('owner sees the imported cookbook with 190 recipes', async () => {
  const r = await call('owner', 'listCookbooks');
  const book = r.data.cookbooks.find(c => c.id === familyBook);
  assert.ok(book, 'cookbook missing');
  assert.equal(book.recipeCount, 190);
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

// ---- Direct sign-in (opening the app without the Hub) -----------------------
// The seed gives owner@test the password "pw".

await test('login: wrong password → 401, right password → working token, logout ends it', async () => {
  assert.equal((await call(null, 'login', { body: { username: 'owner@test', password: 'nope' } })).status, 401);
  const r = await call(null, 'login', { body: { username: 'owner@test', password: 'pw' } });
  assert.equal(r.status, 200);
  const url = new URL(API); url.searchParams.set('action', 'whoAmI');
  const who = await fetch(url, { headers: { Authorization: `Bearer ${r.data.token}` } });
  assert.equal(who.status, 200);
  await call(null, 'logout', { body: { token: r.data.token } });
  assert.equal((await fetch(url, { headers: { Authorization: `Bearer ${r.data.token}` } })).status, 401);
});

// ---- Recipe editor (create, save, conflicts, in-place rows) -----------------

// What the editor sends back for an existing recipe, unchanged.
function editorPayload(recipe) {
  return {
    ...recipe,
    ingredients: recipe.ingredients.map(i => ({ id: i.id, quantity: i.quantity, quantityMax: i.quantityMax,
      unit: i.unit, package: i.package, name: i.name, preparation: i.preparation, section: i.section, optional: i.optional })),
    methods: recipe.methods.map(m => ({ id: m.id, key: `m${m.id}`, name: m.name })),
    steps: recipe.steps.map(s => ({ id: s.id, methodKey: s.methodId ? `m${s.methodId}` : '', text: s.text })),
  };
}

let newRecipe = 0;
const draft = {
  title: 'Meatball Soup', servings: '6', yieldQuantity: '', yieldUnit: '',
  ingredients: [
    { quantity: '1 1/2', unit: 'cups', name: 'flour', preparation: 'sifted' },
    { quantity: '1', unit: 'can (15 oz)', name: 'black beans' },
    { quantity: '', unit: '', name: '', preparation: '' },            // blank row is ignored
    { quantity: '2', unit: '', name: 'onions', preparation: '', section: 'For serving', optional: true },
  ],
  methods: [{ key: 'n1', name: 'Instant Pot' }],
  steps: [{ text: 'Brown the meatballs.', methodKey: '' }, { text: 'Pressure cook 10 minutes.', methodKey: 'n1' }],
};

await test('create: validation errors point at the field', async () => {
  const noTitle = await call('elaine', 'createRecipe', { body: { cookbookId: familyBook, recipe: { ...draft, title: ' ' } } });
  assert.equal(noTitle.status, 400);
  assert.equal(noTitle.data.field, 'title');
  const badQty = await call('elaine', 'createRecipe', { body: { cookbookId: familyBook,
    recipe: { ...draft, ingredients: [{ quantity: 'lots', unit: 'cup', name: 'sugar' }] } } });
  assert.equal(badQty.status, 400);
  assert.equal(badQty.data.field, 'ingredients.0.quantity');
});
await test('create: someone outside the cookbook cannot add to it', async () => {
  assert.equal((await call('outsider', 'createRecipe', { body: { cookbookId: familyBook, recipe: draft } })).status, 404);
});
await test('create: Elaine creates a recipe in the family cookbook and co-owns it', async () => {
  const r = await call('elaine', 'createRecipe', { body: { cookbookId: familyBook, recipe: draft } });
  assert.equal(r.status, 200);
  newRecipe = r.data.id;
  const got = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.equal(got.myRole, 'co_owner');
  assert.equal(got.version, 1);
  assert.equal(got.ingredients.length, 3);
  assert.deepEqual([got.ingredients[0].quantity, got.ingredients[0].unit, got.ingredients[0].text], [1.5, 'cup', '1½ cups flour (sifted)']);
  assert.deepEqual([got.ingredients[1].unit, got.ingredients[1].package, got.ingredients[1].dimension], ['can', '15 oz', 'package']);
  assert.deepEqual([got.ingredients[2].dimension, got.ingredients[2].optional, got.ingredients[2].section], ['count', true, 'For serving']);
  assert.equal(got.methods[0].name, 'Instant Pot');
  assert.equal(got.steps.find(s => s.text.startsWith('Pressure')).methodId, got.methods[0].id);
  assert.equal(got.steps.find(s => s.text.startsWith('Brown')).methodId, null);
});
await test('save: a cookbook owner who is not on the recipe can read it but not save it', async () => {
  const got = (await call('owner', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.equal(got.canEdit, false);
  const r = await call('owner', 'saveRecipe', { body: { id: newRecipe, version: got.version, recipe: editorPayload(got) } });
  assert.equal(r.status, 403);
});
await test('save: keeps row ids, rewrites only changed lines, bumps the version', async () => {
  const before = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  const payload = editorPayload(before);
  payload.ingredients[0].quantity = '2';
  const r = await call('elaine', 'saveRecipe', { body: { id: newRecipe, version: before.version, recipe: payload } });
  assert.equal(r.status, 200);
  assert.equal(r.data.version, 2);
  const after = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.deepEqual(after.ingredients.map(i => i.id), before.ingredients.map(i => i.id));
  assert.deepEqual(after.steps.map(s => s.id), before.steps.map(s => s.id));
  assert.equal(after.ingredients[0].text, '2 cups flour (sifted)');
  assert.equal(after.ingredients[1].text, before.ingredients[1].text);
});
await test('save: a stale version is refused with who saved it — nothing is overwritten', async () => {
  const got = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  const payload = editorPayload(got);
  payload.title = 'Overwritten?';
  const r = await call('elaine', 'saveRecipe', { body: { id: newRecipe, version: 1, recipe: payload } });
  assert.equal(r.status, 409);
  assert.equal(r.data.conflict, true);
  assert.equal(r.data.updatedBy.name, 'Elaine');
  assert.equal((await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe.title, 'Meatball Soup');
});
await test('save: removing a line deletes only that line', async () => {
  const got = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  const payload = editorPayload(got);
  payload.ingredients.splice(1, 1);
  payload.methods = [];
  await call('elaine', 'saveRecipe', { body: { id: newRecipe, version: got.version, recipe: payload } });
  const after = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.deepEqual(after.ingredients.map(i => i.id), [got.ingredients[0].id, got.ingredients[2].id]);
  assert.equal(after.methods.length, 0);
  assert.ok(after.steps.every(s => s.methodId === null), 'steps of a removed method fall back to every method');
});
await test('save: an unchanged imported recipe keeps every original line exactly', async () => {
  const before = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  const r = await call('owner', 'saveRecipe', { body: { id: soup, version: before.version, recipe: editorPayload(before) } });
  assert.equal(r.status, 200);
  const after = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.deepEqual(after.ingredients.map(i => i.text), before.ingredients.map(i => i.text));
  assert.deepEqual(after.ingredients.map(i => i.id), before.ingredients.map(i => i.id));
});

// ---- History -----------------------------------------------------------------

await test('history: restoring version 1 saves it as a new version, keeping row ids', async () => {
  const revs = (await call('elaine', 'recipeRevisions', { params: { id: newRecipe } })).data.revisions;
  assert.equal(revs.length, 3);
  assert.equal(revs[revs.length - 1].note, 'Created');
  const v1 = revs.find(v => v.version === 1);
  const cur = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  const r = await call('elaine', 'restoreRevision', { body: { id: newRecipe, revisionId: v1.id, version: cur.version } });
  assert.equal(r.status, 200);
  const after = (await call('elaine', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.equal(after.version, 4);
  assert.equal(after.ingredients.length, 3);
  assert.equal(after.ingredients[0].quantity, 1.5);
  assert.equal(after.ingredients[0].id, cur.ingredients[0].id, 'surviving lines keep their id');
  assert.equal(after.methods[0].name, 'Instant Pot');
  const revs2 = (await call('elaine', 'recipeRevisions', { params: { id: newRecipe } })).data.revisions;
  assert.equal(revs2[0].note, 'Restored version 1');
});
await test('history: an imported recipe can be viewed at version 1', async () => {
  const revs = (await call('owner', 'recipeRevisions', { params: { id: soup } })).data.revisions;
  const v1 = revs.find(v => v.version === 1);
  assert.match(v1.note, /Imported from Senior Family Cookbook/);
  const snap = (await call('owner', 'getRevision', { params: { id: soup, revisionId: v1.id } })).data.snapshot;
  assert.ok(snap.ingredients.some(i => i.name === 'chili powder' && i.quantity === 0.5));
});
await test('history: outsiders cannot read it', async () => {
  assert.equal((await call('outsider', 'recipeRevisions', { params: { id: newRecipe } })).status, 404);
});

// ---- Recipe sharing ----------------------------------------------------------

await test('recipe sharing: co-owner makes the owner an editor, who can then save', async () => {
  assert.equal((await call('elaine', 'addRecipeMember', { body: { recipeId: newRecipe, username: 'owner@test', role: 'editor' } })).status, 200);
  const got = (await call('owner', 'getRecipe', { params: { id: newRecipe } })).data.recipe;
  assert.equal(got.canEdit, true);
  assert.equal(got.canManage, false);
  const payload = editorPayload(got);
  payload.description = 'Edited by the owner';
  assert.equal((await call('owner', 'saveRecipe', { body: { id: newRecipe, version: got.version, recipe: payload } })).status, 200);
});
await test('recipe sharing: an editor cannot share, change roles, or delete', async () => {
  assert.equal((await call('owner', 'addRecipeMember', { body: { recipeId: newRecipe, username: 'outsider@test', role: 'viewer' } })).status, 403);
  assert.equal((await call('owner', 'setRecipeMemberRole', { body: { recipeId: newRecipe, userId: ownerId, role: 'co_owner' } })).status, 403);
  assert.equal((await call('owner', 'deleteRecipe', { body: { id: newRecipe } })).status, 403);
});
await test('recipe sharing: the last co-owner cannot step down or leave', async () => {
  assert.equal((await call('elaine', 'setRecipeMemberRole', { body: { recipeId: newRecipe, userId: elaineId, role: 'editor' } })).status, 409);
  assert.equal((await call('elaine', 'removeRecipeMember', { body: { recipeId: newRecipe, userId: elaineId } })).status, 409);
});
await test('recipe sharing: a recipe viewer who is in no cookbook can read it', async () => {
  assert.equal((await call('elaine', 'addRecipeMember', { body: { recipeId: newRecipe, username: 'outsider@test', role: 'viewer' } })).status, 200);
  const got = await call('outsider', 'getRecipe', { params: { id: newRecipe } });
  assert.equal(got.status, 200);
  assert.deepEqual(got.data.recipe.cookbooks, [], 'cookbooks they are not in stay hidden');
  assert.equal((await call('elaine', 'removeRecipeMember', { body: { recipeId: newRecipe, userId: userId('outsider') } })).status, 200);
  assert.equal((await call('outsider', 'getRecipe', { params: { id: newRecipe } })).status, 404);
});

// ---- Cookbook linking (acceptance test 1) --------------------------------------

let soupsBook = 0;
await test('one recipe, two cookbooks: one recipe row, two links', async () => {
  soupsBook = (await call('owner', 'createCookbook', { body: { title: 'Soups & Stews' } })).data.id;
  assert.equal((await call('owner', 'linkRecipe', { body: { cookbookId: soupsBook, recipeId: soup } })).status, 200);
  assert.equal((await call('owner', 'linkRecipe', { body: { cookbookId: soupsBook, recipeId: soup } })).status, 200, 'linking twice is harmless');
  assert.equal(Number(sql(`SELECT COUNT(*) FROM sft_cookbook_recipes WHERE recipe_id = ${soup} AND cookbook_id IN (${familyBook}, ${soupsBook})`)), 2);
  const got = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.deepEqual(got.cookbooks.map(c => c.id).sort(), [familyBook, soupsBook].sort());
});
await test('linking needs edit rights on the cookbook and sight of the recipe', async () => {
  const outsiderBook = (await call('outsider', 'createCookbook', { body: { title: 'Mine' } })).data.id;
  assert.equal((await call('outsider', 'linkRecipe', { body: { cookbookId: outsiderBook, recipeId: soup } })).status, 404);
  assert.equal((await call('outsider', 'linkRecipe', { body: { cookbookId: soupsBook, recipeId: soup } })).status, 404);
});
await test('removing from one cookbook leaves the recipe and its other cookbook', async () => {
  assert.equal((await call('owner', 'unlinkRecipe', { body: { cookbookId: soupsBook, recipeId: soup } })).status, 200);
  const got = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.deepEqual(got.cookbooks.map(c => c.id), [familyBook]);
});

// ---- Search and favorites ----------------------------------------------------

await test('search finds by ingredient and by tag, and only what you can see', async () => {
  const byIngredient = (await call('owner', 'searchRecipes', { params: { q: 'chili powder' } })).data.recipes;
  assert.ok(byIngredient.some(r => r.id === soup));
  const byTag = (await call('owner', 'searchRecipes', { params: { q: 'instant pot' } })).data.recipes;
  assert.ok(byTag.length >= 30);
  const outsider = (await call('outsider', 'searchRecipes', { params: { q: '' } })).data.recipes;
  assert.equal(outsider.length, 0);
  assert.equal((await call('outsider', 'searchRecipes', { params: { q: '', cookbookId: familyBook } })).status, 404);
});
await test('search treats % and _ literally', async () => {
  assert.equal((await call('owner', 'searchRecipes', { params: { q: '%' } })).data.recipes.length, 0);
});
await test('favorites are personal', async () => {
  assert.equal((await call('owner', 'setFavorite', { body: { recipeId: soup, favorite: true } })).status, 200);
  assert.deepEqual((await call('owner', 'searchRecipes', { params: { favorites: '1' } })).data.recipes.map(r => r.id), [soup]);
  assert.deepEqual((await call('elaine', 'searchRecipes', { params: { favorites: '1' } })).data.recipes, []);
  assert.equal((await call('outsider', 'setFavorite', { body: { recipeId: soup, favorite: true } })).status, 404);
});

// ---- Delete and restore -------------------------------------------------------

await test('delete: hidden everywhere, restorable by a co-owner for 30 days', async () => {
  assert.equal((await call('elaine', 'deleteRecipe', { body: { id: newRecipe } })).status, 200);
  assert.equal((await call('owner', 'getRecipe', { params: { id: newRecipe } })).status, 404);
  assert.ok(!(await call('owner', 'getCookbook', { params: { id: familyBook } })).data.recipes.some(r => r.id === newRecipe));
  assert.ok((await call('elaine', 'deletedRecipes')).data.recipes.some(r => r.id === newRecipe));
  assert.equal((await call('owner', 'restoreRecipe', { body: { id: newRecipe } })).status, 404, 'an editor cannot restore');
  assert.equal((await call('elaine', 'restoreRecipe', { body: { id: newRecipe } })).status, 200);
  assert.equal((await call('owner', 'getRecipe', { params: { id: newRecipe } })).status, 200);
});

// ---- Scaling (personal saved scale) ---------------------------------------------

await test('saved scale is personal and never changes the master', async () => {
  assert.equal((await call('owner', 'setSavedScale', { body: { recipeId: soup, mode: 'servings', value: 12 } })).status, 200);
  const mine = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.deepEqual([mine.me.savedScaleMode, mine.me.savedScale], ['servings', 12]);
  assert.equal(mine.servings, 6);
  const hers = (await call('elaine', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.equal(hers.me.savedScale, null);
});

// ---- Shopping lists ------------------------------------------------------------

// Two recipes with known amounts, so every combining rule can be checked exactly.
const soupA = (await call('owner', 'createRecipe', { body: { cookbookId: familyBook, recipe: {
  title: 'List Soup A', servings: '6', ingredients: [
    { quantity: '1', unit: 'cup', name: 'milk' },
    { quantity: '2', unit: 'tbsp', name: 'butter' },
    { quantity: '1', unit: '', name: 'onion', preparation: 'chopped' },
    { quantity: '1', unit: 'lb', name: 'ground beef' },
    { quantity: '', unit: 'to taste', name: 'salt' },
    { quantity: '1', unit: 'can (15 oz)', name: 'black beans' },
    { quantity: '1', unit: 'tsp', name: 'red pepper flakes', optional: true },
  ], steps: [{ text: 'Cook.' }] } } })).data.id;
const soupB = (await call('owner', 'createRecipe', { body: { cookbookId: familyBook, recipe: {
  title: 'List Soup B', servings: '4', ingredients: [
    { quantity: '2', unit: 'cups', name: 'milk' },
    { quantity: '3', unit: '', name: 'onions' },
    { quantity: '1', unit: 'bunch', name: 'green onions' },
    { quantity: '8', unit: 'oz', name: 'ground beef' },
    { quantity: '1', unit: 'tsp', name: 'salt' },
    { quantity: '1', unit: 'can (15 oz)', name: 'black beans' },
    { quantity: '1', unit: 'can (28 oz)', name: 'crushed tomatoes' },
  ], steps: [{ text: 'Cook.' }] } } })).data.id;
const lasagna = (await call('owner', 'createRecipe', { body: { cookbookId: familyBook, recipe: {
  title: 'List Lasagna', servings: '12', yieldQuantity: '1', yieldUnit: 'pan',
  ingredients: [{ quantity: '1', unit: 'lb', name: 'lasagna noodles' }], steps: [{ text: 'Bake.' }] } } })).data.id;

let listId = 0;
let lrA = 0;
let lrB = 0;
const items = async (who = 'owner') => (await call(who, 'getList', { params: { id: listId } })).data.items;
const find = (list, label) => list.find(i => i.label.toLowerCase() === label);

await test('lists: create a purpose list', async () => {
  const r = await call('owner', 'createList', { body: { title: 'Christmas Social' } });
  assert.equal(r.status, 200);
  listId = r.data.id;
  const got = (await call('owner', 'getList', { params: { id: listId } })).data;
  assert.equal(got.list.role, 'owner');
  assert.deepEqual(got.items, []);
  assert.ok(got.departments.includes('Produce'));
  assert.ok(got.stores.some(s => s.name === 'Costco'));
});
await test('serving scale: 6-serving soup doubled to 12 doubles shopping amounts (acceptance 6)', async () => {
  const r = await call('owner', 'addRecipeToList', { body: { listId, recipeId: soupA, scaleMode: 'servings', scaleValue: 12 } });
  assert.equal(r.status, 200);
  lrA = r.data.listRecipeId;
  const it = await items();
  assert.deepEqual([find(it, 'milk').quantity, find(it, 'milk').unit], [2, 'cup']);
  assert.deepEqual([find(it, 'butter').quantity, find(it, 'butter').unit], [4, 'tbsp']);
  assert.equal(find(it, 'onion').quantity, 2);
  assert.deepEqual([find(it, 'ground beef').quantity, find(it, 'ground beef').unit], [2, 'lb']);
  assert.equal(find(it, 'salt').quantity, null);
  assert.deepEqual([find(it, 'black beans').quantity, find(it, 'black beans').unit, find(it, 'black beans').notes], [2, 'can', '15 oz']);
  assert.equal(find(it, 'red pepper flakes'), undefined, 'optional ingredients are left off by default');
  const master = (await call('owner', 'getRecipe', { params: { id: soupA } })).data.recipe;
  assert.equal(master.ingredients[0].quantity, 1, 'the master recipe is untouched');
});
await test('the same recipe twice on one list → 409 pointing at the existing entry', async () => {
  const r = await call('owner', 'addRecipeToList', { body: { listId, recipeId: soupA } });
  assert.equal(r.status, 409);
  assert.equal(r.data.listRecipeId, lrA);
});
await test('consolidation: only same ingredient + compatible unit combine', async () => {
  const r = await call('owner', 'addRecipeToList', { body: { listId, recipeId: soupB } });
  lrB = r.data.listRecipeId;
  const it = await items();
  assert.deepEqual([find(it, 'milk').quantity, find(it, 'milk').unit], [1, 'quart'], '2 cups + 2 cups = 1 quart');
  assert.equal(find(it, 'onion').quantity, 5, '2 onions + 3 onions');
  assert.ok(find(it, 'green onions'), 'green onions stay separate from onions');
  assert.deepEqual([find(it, 'ground beef').quantity, find(it, 'ground beef').unit], [2.5, 'lb'], '2 lb + 8 oz');
  assert.deepEqual([find(it, 'salt').quantity, find(it, 'salt').unit], [1, 'tsp'], '"to taste" joins the measured salt');
  assert.equal(find(it, 'black beans').quantity, 3, 'same-size cans add up');
  assert.ok(find(it, 'crushed tomatoes'));
  assert.deepEqual(find(it, 'milk').sources.sort(), ['List Soup A', 'List Soup B']);
  assert.equal(it.filter(i => i.label.toLowerCase().includes('onion')).length, 2);
});
await test('departments are guessed like the Senior Family Cookbook', async () => {
  const it = await items();
  assert.equal(find(it, 'milk').department, 'Dairy & Eggs');
  assert.equal(find(it, 'ground beef').department, 'Meat & Seafood');
  assert.equal(find(it, 'onion').department, 'Produce');
});
await test('item edit: compare-and-set refuses a stale value, accepts a current one', async () => {
  const milk = find(await items(), 'milk');
  const stale = await call('owner', 'updateItem', { body: { id: milk.id, changes: { quantity: 5 }, base: { quantity: 3 } } });
  assert.equal(stale.status, 409);
  assert.equal(stale.data.item.quantity, 1);
  const ok = await call('owner', 'updateItem', { body: { id: milk.id, changes: { quantity: '1 1/2', unit: 'gallons' }, base: { quantity: 1, unit: 'quart' } } });
  assert.equal(ok.status, 200);
  assert.deepEqual([ok.data.item.quantity, ok.data.item.unit, ok.data.item.edited], [1.5, 'gallon', true]);
});
await test('item edit: two people editing different fields both win', async () => {
  const beef = find(await items(), 'ground beef');
  assert.equal((await call('owner', 'updateItem', { body: { id: beef.id, changes: { notes: '80/20' }, base: { notes: null } } })).status, 200);
  const r = await call('owner', 'updateItem', { body: { id: beef.id, changes: { quantity: 3 }, base: { quantity: 2.5 } } });
  assert.equal(r.status, 200);
  assert.deepEqual([r.data.item.notes, r.data.item.quantity], ['80/20', 3]);
});
await test('a hand-picked department sticks when the item is renamed', async () => {
  const onion = find(await items(), 'onion');
  await call('owner', 'updateItem', { body: { id: onion.id, changes: { department: 'Frozen' }, base: { department: 'Produce' } } });
  const r = await call('owner', 'updateItem', { body: { id: onion.id, changes: { label: 'yellow onion' }, base: { label: 'onion' } } });
  assert.deepEqual([r.data.item.department, r.data.item.departmentManual], ['Frozen', true]);
  const tomato = find(await items(), 'crushed tomatoes');
  const t = await call('owner', 'updateItem', { body: { id: tomato.id, changes: { label: 'frozen peas' }, base: { label: 'crushed tomatoes' } } });
  assert.equal(t.data.item.department, 'Frozen', 'not hand-picked → re-guessed from the new name');
});
await test('removing a recipe takes its share back out; hand-edited items are flagged instead', async () => {
  assert.equal((await call('owner', 'removeListRecipe', { body: { id: lrB } })).status, 200);
  const it = await items();
  assert.equal(find(it, 'green onions'), undefined, 'items only Soup B needed are gone');
  assert.equal(find(it, 'black beans').quantity, 2);
  assert.equal(find(it, 'salt').quantity, null);
  const milk = find(it, 'milk');
  assert.deepEqual([milk.quantity, milk.unit, milk.needsReview], [1.5, 'gallon', true]);
});
await test('manual items, zero amounts, stores, and assignments', async () => {
  const r = await call('owner', 'addItem', { body: { listId, label: 'paper plates', quantity: '2', unit: 'packages' } });
  assert.equal(r.status, 200);
  assert.deepEqual([r.data.item.manual, r.data.item.department, r.data.item.unit], [true, 'Other', 'package']);
  const plates = r.data.item.id;
  const z = await call('owner', 'updateItem', { body: { id: plates, changes: { quantity: '0' }, base: { quantity: 2 } } });
  assert.equal(z.data.item.quantity, 0, '0 = "don\'t need it", kept on the list');
  const costco = (await call('owner', 'getList', { params: { id: listId } })).data.stores.find(s => s.name === 'Costco');
  assert.equal((await call('owner', 'updateItem', { body: { id: plates, changes: { storeId: costco.id }, base: { storeId: null } } })).data.item.storeId, costco.id);
  const smiths = (await call('owner', 'addStore', { body: { name: "Smith's" } })).data.store;
  const beans = find(await items(), 'black beans');
  assert.equal((await call('owner', 'updateItem', { body: { id: beans.id, changes: { storeId: smiths.id }, base: { storeId: null } } })).status, 200);
  assert.equal((await call('owner', 'updateItem', { body: { id: beans.id, changes: { storeId: 99999 }, base: { storeId: smiths.id } } })).status, 400);
  assert.equal((await call('owner', 'updateItem', { body: { id: beans.id, changes: { assignedUserId: userId('outsider') }, base: { assignedUserId: null } } })).status, 400);
});
await test('rebuild from recipes keeps hand-added items and resets recipe items', async () => {
  assert.equal((await call('owner', 'rebuildList', { body: { listId } })).status, 200);
  const it = await items();
  assert.ok(find(it, 'paper plates'), 'manual item kept');
  const milk = find(it, 'milk');
  assert.deepEqual([milk.quantity, milk.unit, milk.edited, milk.needsReview], [2, 'cup', false, false]);
  assert.equal(find(it, 'yellow onion'), undefined, 'renamed recipe item is rebuilt from the recipe');
  assert.ok(find(it, 'onion'));
});
await test('batch scale: two pans doubles the one-pan amounts (acceptance 7)', async () => {
  const r = await call('owner', 'addRecipeToList', { body: { listId, recipeId: lasagna, scaleMode: 'batches', scaleValue: 2 } });
  assert.equal(r.status, 200);
  assert.deepEqual([find(await items(), 'lasagna noodles').quantity, find(await items(), 'lasagna noodles').unit], [2, 'lb']);
  const list = (await call('owner', 'getList', { params: { id: listId } })).data;
  const lr = list.recipes.find(x => x.recipeId === lasagna);
  assert.deepEqual([lr.scaleMode, lr.scaleValue], ['batches', 2]);
  assert.equal((await call('owner', 'updateListRecipe', { body: { id: lr.id, scaleMode: 'batches', scaleValue: 3 } })).status, 200);
  assert.equal(find(await items(), 'lasagna noodles').quantity, 3);
});
await test('"have it": unticked ingredients stay off the list', async () => {
  const list = (await call('owner', 'getList', { params: { id: listId } })).data;
  const lr = list.recipes.find(x => x.recipeId === soupA);
  const master = (await call('owner', 'getRecipe', { params: { id: soupA } })).data.recipe;
  const butter = master.ingredients.find(i => i.name === 'butter').id;
  await call('owner', 'updateListRecipe', { body: { id: lr.id, scaleMode: 'servings', scaleValue: 12, excludedIngredientIds: [butter] } });
  assert.equal(find(await items(), 'butter'), undefined);
  assert.ok(find(await items(), 'red pepper flakes'), 'explicit choices replace the optional-off default');
});

// ---- Sharing a list and live updates ------------------------------------------

let seq = 0;
await test('list sharing: editor can check items; outsider sees nothing (acceptance 11)', async () => {
  assert.equal((await call('owner', 'addListMember', { body: { listId, username: 'elaine@test', role: 'editor' } })).status, 200);
  assert.equal((await call('outsider', 'getList', { params: { id: listId } })).status, 404);
  assert.equal((await call('outsider', 'listChanges', { params: { id: listId, since: 0 } })).status, 404);
  assert.ok(!(await call('outsider', 'listLists')).data.lists.some(l => l.id === listId));
  seq = (await call('elaine', 'getList', { params: { id: listId } })).data.list.changeSeq;
});
await test('live updates: a check on one device shows up on the other (acceptance 10)', async () => {
  const unchanged = await call('elaine', 'listChanges', { params: { id: listId, since: seq } });
  assert.equal(unchanged.data.unchanged, true);
  const milk = find(await items(), 'milk');
  assert.equal((await call('owner', 'setItemChecked', { body: { id: milk.id, checked: true } })).status, 200);
  const ch = await call('elaine', 'listChanges', { params: { id: listId, since: seq } });
  assert.ok(ch.data.changeSeq > seq);
  assert.deepEqual(ch.data.items.map(i => [i.id, i.checked]), [[milk.id, true]]);
  seq = ch.data.changeSeq;
});
await test('check is idempotent: both tapping "checked" leaves it checked, with no extra change', async () => {
  const milk = find(await items(), 'milk');
  await call('elaine', 'setItemChecked', { body: { id: milk.id, checked: true } });
  assert.equal((await call('elaine', 'listChanges', { params: { id: listId, since: seq } })).data.unchanged, true);
  assert.equal(find(await items('elaine'), 'milk').checked, true);
});
await test('deletions reach other devices', async () => {
  const plates = find(await items(), 'paper plates');
  await call('elaine', 'deleteItem', { body: { id: plates.id } });
  const ch = await call('owner', 'listChanges', { params: { id: listId, since: seq } });
  assert.deepEqual(ch.data.deleted, [plates.id]);
  seq = ch.data.changeSeq;
});
await test('a viewer can read but not change the list', async () => {
  await call('owner', 'addListMember', { body: { listId, username: 'michelle@test', role: 'viewer' } });
  const milk = find(await items('michelle'), 'milk');
  assert.equal((await call('michelle', 'setItemChecked', { body: { id: milk.id, checked: false } })).status, 403);
  assert.equal((await call('michelle', 'addItem', { body: { listId, label: 'cake' } })).status, 403);
  assert.equal((await call('michelle', 'addListMember', { body: { listId, username: 'outsider@test', role: 'owner' } })).status, 403);
});
await test('assigned-to must be someone on the list', async () => {
  const beans = find(await items(), 'black beans');
  const r = await call('owner', 'updateItem', { body: { id: beans.id, changes: { assignedUserId: elaineId }, base: { assignedUserId: null } } });
  assert.equal(r.data.item.assignedUserId, elaineId);
});
await test('last owner of a list cannot leave', async () => {
  assert.equal((await call('owner', 'removeListMember', { body: { listId, userId: ownerId } })).status, 409);
});

// ---- Completed lists, copies, deletion ------------------------------------------

await test('complete, then copy to a fresh unchecked list', async () => {
  assert.equal((await call('elaine', 'updateList', { body: { id: listId, status: 'completed' } })).status, 200);
  assert.equal((await call('elaine', 'updateList', { body: { id: listId, title: 'Hijacked' } })).status, 403, 'only the owner renames');
  const mine = (await call('owner', 'listLists')).data.lists.find(l => l.id === listId);
  assert.equal(mine.status, 'completed');
  const copy = await call('elaine', 'copyList', { body: { id: listId, title: 'Christmas Social 2027' } });
  assert.equal(copy.status, 200);
  const got = (await call('elaine', 'getList', { params: { id: copy.data.id } })).data;
  assert.equal(got.list.role, 'owner');
  assert.equal(got.items.filter(i => i.checked).length, 0);
  assert.equal(got.items.length, (await items()).length);
  assert.equal(got.recipes.length, 2, 'Soup A and the lasagna (Soup B was removed)');
  assert.equal((await call('owner', 'getList', { params: { id: copy.data.id } })).status, 404, 'the copy is Elaine\'s alone');
  const lr = got.recipes.find(x => x.recipeId === lasagna);
  assert.equal((await call('elaine', 'removeListRecipe', { body: { id: lr.id } })).status, 200);
  assert.equal(find((await call('elaine', 'getList', { params: { id: copy.data.id } })).data.items, 'lasagna noodles'), undefined);
  assert.ok(find(await items(), 'lasagna noodles'), 'the original list is untouched');
});
await test('only the owner deletes a list', async () => {
  assert.equal((await call('elaine', 'deleteList', { body: { id: listId } })).status, 403);
  assert.equal((await call('owner', 'deleteList', { body: { id: listId } })).status, 200);
  assert.equal((await call('elaine', 'getList', { params: { id: listId } })).status, 404);
});
await test('no meal planner anywhere in the API (acceptance 12)', async () => {
  for (const action of ['mealPlan', 'getMealPlan', 'scheduleMeal', 'planMeals']) {
    assert.equal((await call('owner', action)).status, 404);
  }
});

// ---- Family notes ----------------------------------------------------------------

let sharedNote = 0;
let privateNote = 0;
await test('notes: anyone who can see a recipe can add shared and private notes', async () => {
  const s = await call('elaine', 'addNote', { body: { recipeId: soup, visibility: 'shared', text: 'Grandma added extra cumin.' } });
  assert.equal(s.status, 200);
  sharedNote = s.data.note.id;
  const p = await call('elaine', 'addNote', { body: { recipeId: soup, visibility: 'private', text: 'My secret zzpepita trick.' } });
  privateNote = p.data.note.id;
  assert.equal(p.data.note.mine, true);
  assert.equal((await call('outsider', 'addNote', { body: { recipeId: soup, visibility: 'shared', text: 'hi' } })).status, 404);
  assert.equal((await call('elaine', 'addNote', { body: { recipeId: soup, visibility: 'public', text: 'x' } })).data.field, 'visibility');
});
await test('notes: a private note never reaches anyone else — not even the co-owner', async () => {
  const texts = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.notes.map(n => n.text);
  assert.ok(texts.includes('Grandma added extra cumin.'));
  assert.ok(!texts.includes('My secret zzpepita trick.'));
  assert.equal((await call('owner', 'updateNote', { body: { id: privateNote, text: 'x' } })).status, 404);
  assert.equal((await call('owner', 'deleteNote', { body: { id: privateNote } })).status, 404);
});
await test('notes: search honors note visibility', async () => {
  assert.ok((await call('elaine', 'searchRecipes', { params: { q: 'zzpepita' } })).data.recipes.some(r => r.id === soup));
  assert.equal((await call('owner', 'searchRecipes', { params: { q: 'zzpepita' } })).data.recipes.length, 0);
  assert.ok((await call('owner', 'searchRecipes', { params: { q: 'extra cumin' } })).data.recipes.some(r => r.id === soup));
});
await test('notes: only the author edits; author can make it private', async () => {
  assert.equal((await call('owner', 'updateNote', { body: { id: sharedNote, text: 'changed' } })).status, 403);
  const r = await call('elaine', 'updateNote', { body: { id: sharedNote, text: 'Grandma added extra cumin!', visibility: 'private' } });
  assert.equal(r.status, 200);
  assert.equal(r.data.note.visibility, 'private');
  assert.ok(!(await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.notes.some(n => n.id === sharedNote));
  await call('elaine', 'updateNote', { body: { id: sharedNote, text: 'Grandma added extra cumin!', visibility: 'shared' } });
});
await test('notes: a co-owner may remove someone else\'s shared note (audited)', async () => {
  assert.equal((await call('owner', 'deleteNote', { body: { id: sharedNote } })).status, 200);
  assert.ok(!(await call('elaine', 'getRecipe', { params: { id: soup } })).data.recipe.notes.some(n => n.id === sharedNote));
  assert.equal(Number(sql(`SELECT COUNT(*) FROM sft_audit_events WHERE resource_type = 'note' AND resource_id = ${sharedNote}`)), 1);
  assert.equal((await call('elaine', 'deleteNote', { body: { id: privateNote } })).status, 200);
});

// ---- Tags ------------------------------------------------------------------------

let christmas = 0;
await test('tags: built-in tags need edit rights on the recipe', async () => {
  const choices = (await call('owner', 'tagChoices', { params: { recipeId: soup } })).data;
  assert.equal(choices.canEditBuiltIn, true);
  const dessert = choices.builtIn.find(t => t.name === 'Dessert');
  assert.equal((await call('owner', 'setRecipeTag', { body: { recipeId: soup, tagId: dessert.id, on: true } })).status, 200);
  assert.ok((await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.tags.some(t => t.name === 'Dessert'));
  assert.equal((await call('elaine', 'tagChoices', { params: { recipeId: soup } })).data.canEditBuiltIn, false);
  assert.equal((await call('elaine', 'setRecipeTag', { body: { recipeId: soup, tagId: dessert.id, on: false } })).status, 403);
  await call('owner', 'setRecipeTag', { body: { recipeId: soup, tagId: dessert.id, on: false } });
});
await test('tags: cookbook tags — tidy names, no duplicates, used by cookbook editors', async () => {
  const r = await call('owner', 'createTag', { body: { scope: 'cookbook', cookbookId: familyBook, name: '  christmas   dinner ' } });
  assert.equal(r.data.tag.name, 'Christmas Dinner');
  christmas = r.data.tag.id;
  const again = await call('owner', 'createTag', { body: { scope: 'cookbook', cookbookId: familyBook, name: 'CHRISTMAS DINNER' } });
  assert.deepEqual([again.data.tag.id, again.data.existed], [christmas, true]);
  assert.equal((await call('elaine', 'setRecipeTag', { body: { recipeId: soup, tagId: christmas, on: true } })).status, 200, 'Elaine owns the cookbook');
  assert.equal((await call('outsider', 'setRecipeTag', { body: { recipeId: soup, tagId: christmas, on: true } })).status, 404);
  assert.equal((await call('outsider', 'createTag', { body: { scope: 'cookbook', cookbookId: familyBook, name: 'Spam' } })).status, 404);
});
await test('tags: personal tags are seen only by their owner', async () => {
  const mine = (await call('elaine', 'createTag', { body: { name: 'elaine makes this' } })).data.tag;
  assert.equal((await call('elaine', 'setRecipeTag', { body: { recipeId: soup, tagId: mine.id, on: true } })).status, 200);
  assert.ok((await call('elaine', 'getRecipe', { params: { id: soup } })).data.recipe.tags.some(t => t.name === 'Elaine Makes This'));
  assert.ok(!(await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.tags.some(t => t.name === 'Elaine Makes This'));
  assert.equal((await call('owner', 'setRecipeTag', { body: { recipeId: soup, tagId: mine.id, on: false } })).status, 404);
  assert.equal((await call('owner', 'renameTag', { body: { id: mine.id, name: 'mine now' } })).status, 404);
});
await test('tags: filter list for a cookbook, rename, clash, delete', async () => {
  const tags = (await call('owner', 'cookbookTags', { params: { id: familyBook } })).data;
  assert.equal(tags.canManage, true);
  assert.ok(tags.tags.some(t => t.id === christmas && t.count === 1));
  assert.ok(!tags.tags.some(t => t.name === 'Elaine Makes This'), 'someone else\'s personal tag never shows');
  const builtIn = tags.tags.find(t => t.scope === 'system');
  assert.equal((await call('owner', 'renameTag', { body: { id: builtIn.id, name: 'Mine' } })).status, 403);
  const other = (await call('owner', 'createTag', { body: { scope: 'cookbook', cookbookId: familyBook, name: 'Easter' } })).data.tag;
  assert.equal((await call('owner', 'renameTag', { body: { id: other.id, name: 'christmas dinner' } })).status, 409);
  assert.equal((await call('elaine', 'renameTag', { body: { id: christmas, name: 'Holiday Dinner' } })).status, 200);
  assert.ok((await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.tags.some(t => t.name === 'Holiday Dinner'));
  assert.equal((await call('owner', 'deleteTag', { body: { id: christmas } })).status, 200);
  assert.ok(!(await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.tags.some(t => t.name === 'Holiday Dinner'));
});

// ---- Paste-a-recipe import ------------------------------------------------------------

const PASTE = 'Zucchini Bread\nMakes 2 loaves\n\nIngredients\n3 cups flour\n2 cups grated zucchini\n\nDirections\n1. Mix.\n2. Bake.';
let pasted = 0;
await test('import: "you may already have this" by title and by ingredients — only recipes you can see', async () => {
  const same = (await call('owner', 'similarRecipes', { body: { title: 'Refried Beans with Lard (Instant Pot)', ingredients: [], cookbookId: familyBook } })).data.matches;
  assert.equal(same[0].id, soup);
  assert.deepEqual([same[0].reasons, same[0].inThisCookbook], [['Same title'], true]);
  const similar = (await call('owner', 'similarRecipes', { body: { title: 'Lard Refried Beans', ingredients: [] } })).data.matches;
  assert.ok(similar.some(m => m.id === soup && m.reasons.includes('Similar title')));
  const names = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.ingredients.map(i => i.name);
  const byIng = (await call('owner', 'similarRecipes', { body: { title: 'Totally Different Name', ingredients: names } })).data.matches;
  assert.ok(byIng.some(m => m.id === soup && m.reasons.some(x => /ingredients match/.test(x))));
  assert.deepEqual((await call('outsider', 'similarRecipes', { body: { title: 'Refried Beans with Lard (Instant Pot)', ingredients: names } })).data.matches, []);
});
await test('import: saves the recipe with the pasted text, reader output and flags', async () => {
  assert.equal((await call('elaine', 'importRecipe', { body: { cookbookId: familyBook, recipe: { title: 'X' }, rawText: '  ' } })).data.field, 'rawText');
  const r = await call('elaine', 'importRecipe', { body: { cookbookId: familyBook, rawText: PASTE, parserVersion: 'paste-1',
    parsed: { title: 'Zucchini Bread' }, flags: ['Servings not found'],
    recipe: { title: 'Zucchini Bread', yieldQuantity: '2', yieldUnit: 'loaves',
      ingredients: [{ quantity: '3', unit: 'cups', name: 'flour' }, { quantity: '2', unit: 'cups', name: 'grated zucchini' }],
      steps: [{ text: 'Mix.' }, { text: 'Bake.' }] } } });
  assert.equal(r.status, 200);
  pasted = r.data.id;
  const got = (await call('elaine', 'getRecipe', { params: { id: pasted } })).data.recipe;
  assert.deepEqual([got.myRole, got.ingredients.length, got.steps.length], ['co_owner', 2, 2]);
  const revs = (await call('elaine', 'recipeRevisions', { params: { id: pasted } })).data.revisions;
  assert.equal(revs[0].note, 'Imported from pasted text');
  const imp = (await call('owner', 'recipeImport', { params: { id: pasted } })).data.import;
  assert.equal(imp.text, PASTE);
  assert.equal(imp.by.name, 'Elaine');
  const row = sql(`SELECT CONCAT(outcome, '|', parser_version, '|', needs_review IS NOT NULL, '|', corrections IS NOT NULL) FROM sft_recipe_imports WHERE recipe_id = ${pasted}`);
  assert.equal(row, 'created|paste-1|1|1');
});
await test('import: the original text is only for people who can see the recipe', async () => {
  assert.equal((await call('outsider', 'recipeImport', { params: { id: pasted } })).status, 404);
  assert.equal((await call('owner', 'recipeImport', { params: { id: soup } })).data.import, null, 'Senior Family imports are not "pasted text"');
});
await test('import: "add the one you already have instead" links it, makes no copy, and records it', async () => {
  const book = (await call('owner', 'createCookbook', { body: { title: 'From Pastes' } })).data.id;
  const before = Number(sql('SELECT COUNT(*) FROM sft_recipes'));
  const r = await call('owner', 'importLinkExisting', { body: { cookbookId: book, recipeId: soup, rawText: 'Refried Beans with Lard (Instant Pot)\n…', parserVersion: 'paste-1' } });
  assert.equal(r.status, 200);
  assert.equal(Number(sql('SELECT COUNT(*) FROM sft_recipes')), before);
  assert.ok((await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe.cookbooks.some(c => c.id === book));
  assert.equal(sql(`SELECT outcome FROM sft_recipe_imports WHERE recipe_id = ${soup} ORDER BY id DESC LIMIT 1`), 'linked_existing');
  assert.equal((await call('outsider', 'importLinkExisting', { body: { cookbookId: book, recipeId: soup, rawText: 'x' } })).status, 404);
});

// ---- Photos (ARCHITECTURE.md §8, acceptance test 5) ----------------------------

// A JPEG marked "rotate 90° clockwise to display", the way phones save portrait photos.
function withExifOrientation6(jpeg) {
  const tiff = Buffer.from([0x4d, 0x4d, 0, 0x2a, 0, 0, 0, 8, 0, 1, 0x01, 0x12, 0, 3, 0, 0, 0, 1, 0, 6, 0, 0, 0, 0, 0, 0]);
  const payload = Buffer.concat([Buffer.from('Exif\0\0', 'binary'), tiff]);
  const len = Buffer.alloc(2); len.writeUInt16BE(payload.length + 2);
  return Buffer.concat([jpeg.subarray(0, 2), Buffer.from([0xff, 0xe1]), len, payload, jpeg.subarray(2)]);
}

async function upload(who, action, params, bytes, name = 'photo.png', type = 'image/png', caption = '') {
  const url = new URL(API);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) { url.searchParams.set(k, String(v)); }
  const form = new FormData();
  form.append('file', new Blob([bytes], { type }), name);
  if (caption) { form.append('caption', caption); }
  const res = await fetch(url, { method: 'POST', headers: who ? { Authorization: `Bearer tok-${who}` } : {}, body: form });
  return { status: res.status, data: await res.json() };
}
const photoUrl = q => new URL(q, API).toString();

let photoRecipe = 0;
let firstPhoto = null;
await test('photos: an editor adds a cover and a second photo; it is shrunk and stored as JPEG', async () => {
  photoRecipe = (await call('elaine', 'createRecipe', { body: { cookbookId: familyBook, recipe: { title: 'Photo Test Pie' } } })).data.id;
  const a = await upload('elaine', 'uploadRecipePhoto', { recipeId: photoRecipe }, png(2400, 1200), 'pie.png', 'image/png', 'Fresh from the oven');
  assert.equal(a.status, 200, JSON.stringify(a.data));
  const b = await upload('elaine', 'uploadRecipePhoto', { recipeId: photoRecipe }, png(300, 200));
  assert.equal(b.status, 200);
  const photos = b.data.photos;
  assert.equal(photos.length, 2);
  assert.deepEqual(photos.map(p => p.isCover), [true, false]);
  assert.equal(photos[0].caption, 'Fresh from the oven');
  assert.deepEqual([photos[0].width, photos[0].height], [1600, 800]);
  firstPhoto = photos[0];
  const res = await fetch(photoUrl(photos[0].thumb));
  assert.equal(res.status, 200);
  assert.equal(res.headers.get('content-type'), 'image/jpeg');
  const bytes = Buffer.from(await res.arrayBuffer());
  assert.deepEqual([...bytes.subarray(0, 2)], [0xff, 0xd8]);
});
await test('photos: a cookbook member can see them; the cookbook list shows the cover', async () => {
  const r = await call('owner', 'getRecipe', { params: { id: photoRecipe } });
  assert.equal(r.data.recipe.photos.length, 2);
  const book = await call('owner', 'getCookbook', { params: { id: familyBook } });
  assert.ok(book.data.recipes.find(x => x.id === photoRecipe).cover);
  assert.equal(book.data.recipes.find(x => x.id === soup).cover, null);
});
await test('photos: an unrelated person gets "not found" for the recipe and cannot upload', async () => {
  assert.equal((await call('outsider', 'getRecipe', { params: { id: photoRecipe } })).status, 404);
  assert.equal((await upload('outsider', 'uploadRecipePhoto', { recipeId: photoRecipe }, png(10, 10))).status, 404);
  assert.equal((await call('outsider', 'deletePhoto', { body: { id: firstPhoto.id } })).status, 404);
});
await test('photos: a viewer can see them but not add, move, caption or remove them', async () => {
  // The owner is a cookbook owner but not on the recipe: view only.
  assert.equal((await upload('owner', 'uploadRecipePhoto', { recipeId: photoRecipe }, png(10, 10))).status, 403);
  assert.equal((await call('owner', 'movePhoto', { body: { id: firstPhoto.id, direction: 1 } })).status, 403);
  assert.equal((await call('owner', 'setPhotoCaption', { body: { id: firstPhoto.id, caption: 'x' } })).status, 403);
  assert.equal((await call('owner', 'deletePhoto', { body: { id: firstPhoto.id } })).status, 403);
});
await test('photos: links are signed — a changed id, size or expiry is refused, and no sign-in is needed', async () => {
  const good = new URL(photoUrl(firstPhoto.full));
  assert.equal((await fetch(good)).status, 200);
  for (const [k, v] of [['id', String(Number(good.searchParams.get('id')) + 1)], ['s', 'thumb'], ['e', String(Number(good.searchParams.get('e')) + 3600)], ['g', 'x'.repeat(40)]]) {
    const bad = new URL(good); bad.searchParams.set(k, v);
    assert.equal((await fetch(bad)).status, 404, `changed ${k}`);
  }
});
await test('photos: not a real image (renamed text) is refused', async () => {
  const r = await upload('elaine', 'uploadRecipePhoto', { recipeId: photoRecipe }, Buffer.from('hello, not a photo'), 'fake.jpg', 'image/jpeg');
  assert.equal(r.status, 400);
  assert.equal(r.data.field, 'file');
});
await test('photos: a sideways phone JPEG is turned upright', async () => {
  const jpeg = Buffer.from(await (await fetch(photoUrl(firstPhoto.thumb))).arrayBuffer());   // 480 × 240
  const r = await upload('elaine', 'uploadRecipePhoto', { recipeId: photoRecipe }, withExifOrientation6(jpeg), 'phone.jpg', 'image/jpeg');
  assert.equal(r.status, 200, JSON.stringify(r.data));
  const p = r.data.photos[2];
  assert.deepEqual([p.width, p.height], [240, 480]);
});
await test('photos: move, make cover, caption, and remove (the next photo becomes the cover)', async () => {
  const ids = (await call('elaine', 'getRecipe', { params: { id: photoRecipe } })).data.recipe.photos.map(p => p.id);
  let r = await call('elaine', 'movePhoto', { body: { id: ids[2], first: true } });
  assert.deepEqual(r.data.photos.map(p => p.id), [ids[2], ids[0], ids[1]]);
  assert.equal(r.data.photos[0].isCover, true);
  r = await call('elaine', 'movePhoto', { body: { id: ids[0], direction: 1 } });
  assert.deepEqual(r.data.photos.map(p => p.id), [ids[2], ids[1], ids[0]]);
  r = await call('elaine', 'setPhotoCaption', { body: { id: ids[1], caption: '  Sliced  ' } });
  assert.equal(r.data.photos[1].caption, 'Sliced');
  const removedLink = r.data.photos[0].full;
  r = await call('elaine', 'deletePhoto', { body: { id: ids[2] } });
  assert.deepEqual(r.data.photos.map(p => [p.id, p.isCover]), [[ids[1], true], [ids[0], false]]);
  assert.equal((await fetch(photoUrl(removedLink))).status, 404);
  assert.equal(sql(`SELECT COUNT(*) FROM sft_audit_events WHERE resource_type = 'photo' AND action = 'deleted' AND resource_id = ${ids[2]}`), '1');
});
await test('photos: a deleted recipe\'s photo links stop working', async () => {
  const link = (await call('elaine', 'getRecipe', { params: { id: photoRecipe } })).data.recipe.photos[0].thumb;
  sql(`UPDATE sft_recipes SET deleted_at = NOW() WHERE id = ${photoRecipe}`);
  assert.equal((await fetch(photoUrl(link))).status, 404);
  sql(`UPDATE sft_recipes SET deleted_at = NULL WHERE id = ${photoRecipe}`);
});
await test('note photos: only the author adds or removes them; a private note\'s photos never reach anyone else', async () => {
  const shared = (await call('elaine', 'addNote', { body: { recipeId: photoRecipe, text: 'Mine came out like this', visibility: 'shared' } })).data.note;
  const priv = (await call('elaine', 'addNote', { body: { recipeId: photoRecipe, text: 'Burnt edges — oops', visibility: 'private' } })).data.note;
  let r = await upload('elaine', 'uploadNotePhoto', { noteId: shared.id }, png(200, 200));
  assert.equal(r.status, 200);
  assert.equal(r.data.note.photos.length, 1);
  assert.equal((await upload('elaine', 'uploadNotePhoto', { noteId: priv.id }, png(200, 200))).status, 200);
  assert.equal((await upload('owner', 'uploadNotePhoto', { noteId: shared.id }, png(10, 10))).status, 403);
  assert.equal((await upload('owner', 'uploadNotePhoto', { noteId: priv.id }, png(10, 10))).status, 404);
  const ownerView = (await call('owner', 'getRecipe', { params: { id: photoRecipe } })).data.recipe.notes;
  assert.deepEqual(ownerView.map(n => [n.text, n.photos.length]), [['Mine came out like this', 1]]);
  const photoId = r.data.note.photos[0].id;
  assert.equal((await call('owner', 'deleteNotePhoto', { body: { id: photoId } })).status, 403);
  for (let i = 0; i < 3; i++) { await upload('elaine', 'uploadNotePhoto', { noteId: shared.id }, png(20, 20)); }
  assert.equal((await upload('elaine', 'uploadNotePhoto', { noteId: shared.id }, png(20, 20))).status, 400, 'fifth photo');
  const link = r.data.note.photos[0].full;
  r = await call('elaine', 'deleteNotePhoto', { body: { id: photoId } });
  assert.equal(r.data.note.photos.length, 3);
  assert.equal((await fetch(photoUrl(link))).status, 404);
});

// ---- Copy a recipe to another cookbook (a separate recipe) ----------------

let soupCopy = 0;
const copyBook = (await call('elaine', 'createCookbook', { body: { title: 'Elaine kitchen' } })).data.id;
await test('copy: an exact, separate copy — same wording, steps and methods; the copier is its only co-owner', async () => {
  const r = await call('elaine', 'copyRecipe', { body: { recipeId: soup, cookbookId: copyBook } });
  assert.equal(r.status, 200, JSON.stringify(r.data));
  soupCopy = r.data.id;
  assert.notEqual(soupCopy, soup);
  const cols = 'position, section, original_text, quantity, unit, item_name, ingredient_id, preparation, is_optional';
  assert.equal(sql(`SELECT ${cols} FROM sft_recipe_ingredients WHERE recipe_id = ${soupCopy} ORDER BY position`),
    sql(`SELECT ${cols} FROM sft_recipe_ingredients WHERE recipe_id = ${soup} ORDER BY position`));
  const steps = id => sql(`SELECT s.step_number, m.method_name, s.instruction FROM sft_recipe_steps s LEFT JOIN sft_recipe_methods m ON m.id = s.method_id
                           WHERE s.recipe_id = ${id} ORDER BY s.id`);
  assert.ok(steps(soup).length > 0);
  assert.equal(steps(soupCopy), steps(soup));
  const copy = (await call('elaine', 'getRecipe', { params: { id: soupCopy } })).data.recipe;
  const orig = (await call('elaine', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.equal(copy.title, orig.title);
  assert.deepEqual([copy.servings, copy.source, copy.generalNotes], [orig.servings, orig.source, orig.generalNotes]);
  assert.deepEqual(copy.cookbooks.map(c => c.id), [copyBook]);
  assert.equal(copy.myRole, 'co_owner');
  assert.equal(copy.version, 1);
  assert.equal(sql(`SELECT GROUP_CONCAT(user_id) FROM sft_recipe_members WHERE recipe_id = ${soupCopy}`), String(elaineId));
  assert.equal(sql(`SELECT change_note FROM sft_recipe_revisions WHERE recipe_id = ${soupCopy}`), `Copied from "${orig.title}"`);
  assert.equal(sql(`SELECT COUNT(*) FROM sft_audit_events WHERE resource_type = 'recipe' AND resource_id = ${soupCopy} AND action = 'copied'`), '1');
});
await test('copy: changing the copy never changes the original (and the other way round)', async () => {
  const copy = (await call('elaine', 'getRecipe', { params: { id: soupCopy } })).data.recipe;
  const input = { ...copy, title: 'Elaine\'s Beans', ingredients: copy.ingredients.slice(1) };
  const r = await call('elaine', 'saveRecipe', { body: { id: soupCopy, version: copy.version, recipe: input } });
  assert.equal(r.status, 200, JSON.stringify(r.data));
  const orig = (await call('owner', 'getRecipe', { params: { id: soup } })).data.recipe;
  assert.ok(orig.title.startsWith('Refried Beans with Lard'));
  assert.equal(orig.ingredients.length, copy.ingredients.length);
});
await test('copy: into a cookbook that already has that title, it is called "… (copy)"; a new name can be given', async () => {
  const r = await call('owner', 'copyRecipe', { body: { recipeId: soup, cookbookId: familyBook } });
  const t = (await call('owner', 'getRecipe', { params: { id: r.data.id } })).data.recipe.title;
  assert.match(t, /^Refried Beans with Lard.* \(copy\)$/);
  const named = await call('owner', 'copyRecipe', { body: { recipeId: soup, cookbookId: familyBook, title: '  Beans, my way ' } });
  assert.equal(named.data.title, 'Beans, my way');
});
await test('copy: built-in and my own tags come along; someone else\'s personal tags and other cookbooks\' tags do not', async () => {
  const builtIn = Number(sql("SELECT id FROM sft_categories WHERE scope = 'system' ORDER BY id LIMIT 1"));
  const ownerTag = (await call('owner', 'createTag', { body: { scope: 'user', name: 'Owner only' } })).data.tag.id;
  const elaineTag = (await call('elaine', 'createTag', { body: { scope: 'user', name: 'Elaine faves' } })).data.tag.id;
  const bookTag = (await call('owner', 'createTag', { body: { scope: 'cookbook', cookbookId: familyBook, name: 'Family book tag' } })).data.tag.id;
  for (const [who, tagId] of [['elaine', builtIn], ['owner', ownerTag], ['elaine', elaineTag], ['owner', bookTag]]) {
    assert.equal((await call(who, 'setRecipeTag', { body: { recipeId: photoRecipe, tagId, on: true } })).status, 200, `tag ${tagId}`);
  }
  const r = await call('elaine', 'copyRecipe', { body: { recipeId: photoRecipe, cookbookId: copyBook } });
  const tags = sql(`SELECT category_id FROM sft_recipe_categories WHERE recipe_id = ${r.data.id} ORDER BY category_id`).split('\n').map(Number);
  assert.deepEqual(tags, [builtIn, elaineTag].sort((a, b) => a - b));
});
await test('copy: photos come along with their own files — removing one from the copy leaves the original\'s', async () => {
  const orig = (await call('elaine', 'getRecipe', { params: { id: photoRecipe } })).data.recipe;
  const r = await call('elaine', 'copyRecipe', { body: { recipeId: photoRecipe, cookbookId: copyBook, title: 'Pie copy' } });
  const copy = (await call('elaine', 'getRecipe', { params: { id: r.data.id } })).data.recipe;
  assert.equal(copy.photos.length, orig.photos.length);
  assert.ok(copy.photos.length >= 2);
  assert.deepEqual(copy.photos.map(p => [p.caption, p.isCover, p.width]), orig.photos.map(p => [p.caption, p.isCover, p.width]));
  assert.equal(sql(`SELECT COUNT(*) FROM sft_recipe_photos a JOIN sft_recipe_photos b ON a.storage_key = b.storage_key
                    WHERE a.recipe_id = ${photoRecipe} AND b.recipe_id = ${r.data.id}`), '0', 'no shared files');
  assert.equal((await fetch(photoUrl(copy.photos[0].full))).status, 200);
  assert.equal((await call('elaine', 'deletePhoto', { body: { id: copy.photos[0].id } })).status, 200);
  assert.equal((await fetch(photoUrl(orig.photos[0].full))).status, 200, 'original photo still served');
  assert.equal(copy.notes.length, 0, 'family notes stay with the original');
  assert.equal(copy.me.favorite, false);
});
await test('copy: only from a recipe you can see, only into a cookbook you can edit', async () => {
  assert.equal((await call('outsider', 'copyRecipe', { body: { recipeId: soup, cookbookId: familyBook } })).status, 404);
  const outsiderBook = (await call('outsider', 'createCookbook', { body: { title: 'Outsider book' } })).data.id;
  assert.equal((await call('outsider', 'copyRecipe', { body: { recipeId: soup, cookbookId: outsiderBook } })).status, 404, 'cannot see the recipe');
  assert.equal((await call('elaine', 'copyRecipe', { body: { recipeId: soup, cookbookId: outsiderBook } })).status, 404, 'not her cookbook');
  await call('outsider', 'addCookbookMember', { body: { cookbookId: outsiderBook, username: 'elaine@test', role: 'viewer' } });
  assert.equal((await call('elaine', 'copyRecipe', { body: { recipeId: soup, cookbookId: outsiderBook } })).status, 403, 'viewer only');
});

// ---- Admin import ----------------------------------------------------------

await test('only a Hub admin can run the import', async () => {
  assert.equal((await call('elaine', 'adminImportSeniorFamily', { body: { dryRun: true } })).status, 403);
});
await test('admin import over the API is safe to repeat', async () => {
  const r = await call('owner', 'adminImportSeniorFamily', { body: { dryRun: true } });
  assert.equal(r.status, 200);
  assert.equal(r.data.report.imported, 0);
  assert.equal(r.data.report.alreadyImported, 190);
});

// ----------------------------------------------------------------------------

for (const [status, name, msg] of results) { console.log(`${status}  ${name}${msg ? ' — ' + msg : ''}`); }
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
