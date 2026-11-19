// Tests for the paste-a-recipe reader (js/parser.js). No server needed:
//   node tests/parser.test.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { parseRecipeText, parseIngredientLine } = require('../js/parser.js');

const results = [];
function test(name, fn) {
  try { fn(); results.push(['PASS', name]); } catch (e) { results.push(['FAIL', name, e.message]); }
}
const pick = (o, keys) => Object.fromEntries(keys.map(k => [k, o[k]]));

// ---- A full recipe with headings, sections, two cooking methods and notes ----------

const SOUP = `Meatball Soup
A hearty family favorite from Grandma Senior.
Serves 6
Prep Time: 20 minutes
Cook Time: 1 hour 10 minutes

Ingredients
1 lb frozen meatballs
1 (28 oz) can crushed tomatoes
2 cans (15 oz) cannellini beans, drained and rinsed
1 large onion, diced
3 cloves garlic, minced
4 cups chicken broth
1 ½ tsp Italian seasoning
2-3 carrots, sliced
Salt and pepper to taste
1/2 cup small pasta (optional)

For serving:
Grated Parmesan cheese
Fresh basil, chopped

Instant Pot Instructions
1. Set the Instant Pot to Sauté and cook the onion and garlic for 3 minutes.
2. Add everything except the pasta. Pressure cook on High for 10 minutes.
3. Quick release and stir in the pasta.

Slow Cooker Instructions
1. Add everything except the pasta to the slow cooker.
2. Cook on Low for 6-8 hours,
stirring once halfway through.
3. Add pasta for the last 20 minutes.

Notes
Leftovers keep 4 days in the fridge.
Freezes well without the pasta.`;

const soup = parseRecipeText(SOUP);

test('soup: title, description, servings, times', () => {
  assert.deepEqual(pick(soup, ['title', 'description', 'servings', 'prepMinutes', 'cookMinutes']),
    { title: 'Meatball Soup', description: 'A hearty family favorite from Grandma Senior.', servings: '6', prepMinutes: '20', cookMinutes: '70' });
});
test('soup: every ingredient line, including the "For serving" section', () => {
  assert.equal(soup.ingredients.length, 12);
  assert.deepEqual(soup.ingredients.slice(10).map(i => [i.name, i.section, i.flag]),
    [['Grated Parmesan cheese', 'For serving', null], ['Fresh basil', 'For serving', null]]);
});
test('soup: package sizes', () => {
  assert.deepEqual(pick(soup.ingredients[1], ['quantity', 'unit', 'name']), { quantity: '1', unit: 'can (28 oz)', name: 'crushed tomatoes' });
  assert.deepEqual(pick(soup.ingredients[2], ['quantity', 'unit', 'name', 'preparation']),
    { quantity: '2', unit: 'cans (15 oz)', name: 'cannellini beans', preparation: 'drained and rinsed' });
});
test('soup: size words, fractions, ranges, to taste, optional', () => {
  assert.deepEqual(pick(soup.ingredients[3], ['quantity', 'unit', 'name', 'preparation']), { quantity: '1', unit: '', name: 'onion', preparation: 'large, diced' });
  assert.deepEqual(pick(soup.ingredients[6], ['quantity', 'unit', 'name']), { quantity: '1 1/2', unit: 'tsp', name: 'Italian seasoning' });
  assert.deepEqual(pick(soup.ingredients[7], ['quantity', 'quantityMax', 'name']), { quantity: '2', quantityMax: '3', name: 'carrots' });
  assert.deepEqual(pick(soup.ingredients[8], ['quantity', 'name', 'preparation', 'flag']), { quantity: '', name: 'Salt and pepper', preparation: 'to taste', flag: null });
  assert.deepEqual(pick(soup.ingredients[9], ['quantity', 'unit', 'name', 'optional']), { quantity: '1/2', unit: 'cup', name: 'small pasta', optional: true });
});
test('soup: steps split by cooking method; wrapped line joined', () => {
  assert.deepEqual(soup.methods.map(m => m.name), ['Instant Pot', 'Slow Cooker']);
  const ip = soup.steps.filter(s => s.methodKey === soup.methods[0].key);
  const sc = soup.steps.filter(s => s.methodKey === soup.methods[1].key);
  assert.equal(ip.length, 3);
  assert.equal(sc.length, 3);
  assert.equal(sc[1].text, 'Cook on Low for 6-8 hours, stirring once halfway through.');
  assert.ok(soup.flags.some(f => f.includes('split by cooking method')));
});
test('soup: notes kept', () => {
  assert.equal(soup.generalNotes, 'Notes:\nLeftovers keep 4 days in the fridge.\nFreezes well without the pasta.');
});

// ---- No headings at all ---------------------------------------------------------------

const BREAD = `Grandma's Banana Bread
Makes 1 loaf

3 ripe bananas, mashed
1/3 cup melted butter
3/4 cup sugar
1 egg, beaten
1 tsp vanilla
1 tsp baking soda
Pinch of salt
1 1/2 cups all-purpose flour

1. Preheat the oven to 350°F and butter a 4x8-inch loaf pan.
2. Mix the butter into the mashed bananas, then stir in the sugar, egg and vanilla.
3. Sprinkle the baking soda and salt over the mixture and mix in, then add the flour.
4. Bake 55-60 minutes, until a tester comes out clean.`;
const bread = parseRecipeText(BREAD);

test('bread: no headings — yield, ingredients and steps still found', () => {
  assert.equal(bread.title, "Grandma's Banana Bread");
  assert.deepEqual([bread.yieldQuantity, bread.yieldUnit, bread.servings], ['1', 'loaf', '']);
  assert.equal(bread.ingredients.length, 8);
  assert.equal(bread.steps.length, 4);
  assert.ok(bread.steps[0].text.startsWith('Preheat the oven'));
  assert.equal(bread.methods.length, 0, '"Preheat the oven…" is a step, not an Oven heading');
});
test('bread: "Pinch of salt" is 1 pinch', () => {
  assert.deepEqual(pick(bread.ingredients[6], ['quantity', 'unit', 'name', 'flag']), { quantity: '1', unit: 'pinch', name: 'salt', flag: null });
});

// ---- Copied from a recipe website --------------------------------------------------------

const WEB = `Easy Chicken Enchiladas
These cheesy enchiladas are a weeknight favorite.
Prep Time 15 mins Cook Time 25 mins Total Time 40 mins
Servings 8 servings
Calories 410kcal

Ingredients
▢ 2 cups cooked chicken, shredded
▢ 1 can (10 oz) red enchilada sauce
▢ 8 flour tortillas
▢ 2 cups shredded cheddar cheese, divided
▢ ¼ cup chopped cilantro

Instructions
Preheat oven to 375°F.
Mix chicken with half the cheese and half the sauce.
Roll the mixture in tortillas and place seam-side down in a 9x13 baking dish.
Top with remaining sauce and cheese. Bake 25 minutes.

Notes
Use rotisserie chicken to save time.`;
const web = parseRecipeText(WEB);

test('website copy: several times on one line, servings, calories ignored', () => {
  assert.deepEqual(pick(web, ['prepMinutes', 'cookMinutes', 'totalMinutes', 'servings']),
    { prepMinutes: '15', cookMinutes: '25', totalMinutes: '40', servings: '8' });
  assert.ok(!web.description.includes('Calories'));
});
test('website copy: check-box bullets, "1 can (10 oz)", ¼', () => {
  assert.equal(web.ingredients.length, 5);
  assert.deepEqual(pick(web.ingredients[1], ['quantity', 'unit', 'name']), { quantity: '1', unit: 'can (10 oz)', name: 'red enchilada sauce' });
  assert.deepEqual(pick(web.ingredients[4], ['quantity', 'unit', 'name']), { quantity: '1/4', unit: 'cup', name: 'chopped cilantro' });
});
test('website copy: unnumbered steps, one per line', () => {
  assert.equal(web.steps.length, 4);
  assert.equal(web.methods.length, 0);
  assert.equal(web.generalNotes, 'Notes:\nUse rotisserie chicken to save time.');
});

// ---- Problems are flagged, never guessed ------------------------------------------------

test('empty paste: says what is missing', () => {
  const r = parseRecipeText('   \n  ');
  assert.ok(r.flags.includes('No title found'));
  assert.ok(r.flags.some(f => f.startsWith('No ingredients')));
  assert.ok(r.flags.some(f => f.startsWith('No steps')));
});
test('servings range: lower number used, and flagged', () => {
  const r = parseRecipeText('Chili\nServes 4-6\n\nIngredients\n1 lb beef\n\nDirections\nCook it all.');
  assert.equal(r.servings, '4');
  assert.ok(r.flags.some(f => f.includes('4–6')));
});
test('a title that names a cooking method is still the title', () => {
  const r = parseRecipeText('Chili Verde (Crockpot)\nServes 8\n\nIngredients\n3 lb pork shoulder\n\nDirections\n1. Cook on low 8 hours.');
  assert.equal(r.title, 'Chili Verde (Crockpot)');
  assert.equal(r.methods.length, 0);
});
test('"Step 1: Heat the Milk" + a paragraph after a blank line is one step', () => {
  const r = parseRecipeText('Yogurt\nServes 6\n\nIngredients\n1 quart milk\n\nInstructions\nStep 1: Heat the Milk\n\nHeat the milk to 180°F.\n\nStep 2: Cool\n\nCool to 110°F.');
  assert.deepEqual(r.steps.map(s => s.text), ['Heat the Milk — Heat the milk to 180°F.', 'Cool — Cool to 110°F.']);
});
test('single lines: units and amounts', () => {
  const cases = [
    ['2 T. butter', { quantity: '2', unit: 'T', name: 'butter' }],
    ['1 t salt', { quantity: '1', unit: 't', name: 'salt' }],
    ['1 fl oz vanilla', { quantity: '1', unit: 'fl oz', name: 'vanilla' }],
    ['3-4 lbs chuck roast', { quantity: '3', quantityMax: '4', unit: 'lbs', name: 'chuck roast' }],
    ['1 medium onion, chopped', { quantity: '1', unit: '', name: 'onion', preparation: 'medium, chopped' }],
    ['a pinch of nutmeg', { quantity: '1', unit: 'pinch', name: 'nutmeg' }],
    ['2 (15-ounce) cans black beans', { quantity: '2', unit: 'cans (15 ounce)', name: 'black beans' }],
    ['• 1/2 cup sour cream (or Greek yogurt)', { quantity: '1/2', unit: 'cup', name: 'sour cream', preparation: 'or Greek yogurt' }],
    ['Optional: 1 tsp red pepper flakes', { quantity: '1', unit: 'tsp', name: 'red pepper flakes', optional: true }],
  ];
  for (const [line, want] of cases) {
    const got = parseIngredientLine(line);
    assert.deepEqual(pick(got, Object.keys(want)), want, line);
  }
});
test('single lines: a missing amount is flagged, not invented', () => {
  const r = parseIngredientLine('Juice of 1 lemon');
  assert.equal(r.quantity, '');
  assert.match(r.flag, /No amount/);
  assert.equal(parseIngredientLine('Butter for greasing').flag, null);
});

for (const [status, name, msg] of results) { console.log(`${status}  ${name}${msg ? ' — ' + msg : ''}`); }
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
