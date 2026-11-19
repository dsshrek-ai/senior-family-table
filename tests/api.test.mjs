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
