// Paste-a-recipe reader (docs/ARCHITECTURE.md §7.1). Deterministic rules, no AI service.
// Turns pasted text into the editor's fields and marks anything it isn't sure about.
// It never invents an amount: "salt to taste" stays without one.
//
// Runs in the browser (recipe import) and in Node (tests/parser.test.mjs).

const PARSER_VERSION = 'paste-1';

// ---- Vocabulary ----------------------------------------------------------------

const P_UNITS = new Set([
  'tsp', 'tsps', 'teaspoon', 'teaspoons', 'tbsp', 'tbsps', 'tbs', 'tbl', 'tablespoon', 'tablespoons',
  'cup', 'cups', 'c', 'pint', 'pints', 'pt', 'quart', 'quarts', 'qt', 'qts', 'gallon', 'gallons', 'gal',
  'oz', 'ounce', 'ounces', 'lb', 'lbs', 'pound', 'pounds', 'g', 'gram', 'grams', 'kg', 'kilogram', 'kilograms',
  'ml', 'milliliter', 'milliliters', 'l', 'liter', 'liters', 'litre', 'litres',
  'can', 'cans', 'pkg', 'pkgs', 'package', 'packages', 'bottle', 'bottles', 'box', 'boxes', 'bag', 'bags',
  'jar', 'jars', 'envelope', 'envelopes', 'tub', 'tubs', 'container', 'containers', 'packet', 'packets',
  'clove', 'cloves', 'slice', 'slices', 'stalk', 'stalks', 'rib', 'ribs', 'head', 'heads', 'bunch', 'bunches',
  'sprig', 'sprigs', 'sheet', 'sheets', 'stick', 'sticks', 'strip', 'strips', 'scoop', 'scoops',
  'handful', 'handfuls', 'pinch', 'pinches', 'dash', 'dashes', 'drop', 'drops', 'piece', 'pieces',
]);
const P_TWO_WORD_UNITS = new Set(['fl oz', 'fluid ounce', 'fluid ounces', 'fluid oz']);
const P_EXACT_CASE_UNITS = { T: 'T', t: 't' };
const P_SIZES = new Set(['small', 'medium', 'large', 'extra-large', 'jumbo']);

const P_FRACTIONS = { '½': '1/2', '⅓': '1/3', '⅔': '2/3', '¼': '1/4', '¾': '3/4', '⅛': '1/8', '⅜': '3/8', '⅝': '5/8', '⅞': '7/8', '⅕': '1/5', '⅙': '1/6' };

// Cooking methods a recipe may give separate steps for.
const P_METHODS = [
  [/\binstant\s*pot\b|\bpressure\s*cooker\b/i, 'Instant Pot'],
  [/\bslow\s*cooker\b|\bcrock\s*-?\s*pot\b/i, 'Slow Cooker'],
  [/\bair\s*fryer\b/i, 'Air Fryer'],
  [/\bstove\s*-?\s*top\b/i, 'Stovetop'],
  [/\boven\b/i, 'Oven'],
  [/\bgrill\b/i, 'Grill'],
];

const P_HEAD_INGREDIENTS = /^(ingredients?|ingredient list|you will need|you'll need|what you need|shopping list)$/i;
const P_HEAD_STEPS = /^(directions|instructions|method|steps|preparation|how to make( it)?|to make)$/i;
const P_HEAD_NOTES = /^(notes?|cook'?s notes?|recipe notes?|tips?|storage|to store|storing|make ahead|freezing|to freeze|variations?|substitutions?|nutrition( facts| information)?|serving suggestions?)$/i;
const P_STOP = new Set(['and', 'or', 'the', 'a', 'an', 'of', 'with', 'for', 'to', 'in', 'recipe']);

// ---- Small helpers -------------------------------------------------------------

function pNormalize(text) {
  return String(text ?? '')
    .replace(/\r\n?/g, '\n')
    .replace(/ /g, ' ')
    .replace(/(\d)\s*([½⅓⅔¼¾⅛⅜⅝⅞⅕⅙])/g, (m, d, f) => `${d} ${P_FRACTIONS[f]}`)
    .replace(/[½⅓⅔¼¾⅛⅜⅝⅞⅕⅙]/g, f => P_FRACTIONS[f])
    .replace(/(\d)⁄(\d)/g, '$1/$2')           // fraction slash
    .replace(/[–—]/g, '-')
    .replace(/[‘’]/g, "'")
    .replace(/[ \t]+/g, ' ');
}

// Bullets, check boxes and numbering at the start of a line.
function pStripBullet(line) {
  return line.replace(/^\s*(?:[-*•·▪▫◦‣⁃–—]|▢|☐|□|\[\s?\])\s*/, '').trim();
}
function pStepNumber(line) {
  const m = line.match(/^\s*(?:step\s*)?(\d{1,2})\s*[.):-]\s+(.*)$/i);
  return m ? { n: Number(m[1]), text: m[2].trim() } : null;
}

function pHeading(line) {
  let t = line.trim().replace(/^#+\s*/, '').replace(/\*\*/g, '').trim();
  const colon = /:$/.test(t);
  t = t.replace(/:$/, '').trim();
  if (!t || t.length > 60) { return null; }
  if (P_HEAD_INGREDIENTS.test(t)) { return { type: 'ingredients' }; }
  if (P_HEAD_NOTES.test(t)) { return { type: 'notes', label: t }; }
  // "Instant Pot Instructions", "Slow Cooker Method", "For the Instant Pot:"
  const method = P_METHODS.find(([re]) => re.test(t));
  if (method && (colon || /\b(instructions|directions|method|version|steps)\b/i.test(t) || t.split(' ').length <= 3)
      && !/\d/.test(t) && !/[.!?]$/.test(t)) {
    return { type: 'steps', method: method[1] };
  }
  if (P_HEAD_STEPS.test(t)) { return { type: 'steps' }; }
  // "For the sauce:", "For serving:", "Topping:" — a section of the ingredient list.
  if (colon && !/\d/.test(t) && t.split(' ').length <= 6) { return { type: 'section', label: t }; }
  return null;
}

function pMinutes(text) {
  const t = String(text).toLowerCase();
  let total = 0;
  let found = false;
  const h = t.match(/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/);
  if (h) { total += Number(h[1]) * 60; found = true; }
  const m = t.match(/(\d+)\s*(?:minutes?|mins?|m)\b/);
  if (m) { total += Number(m[1]); found = true; }
  return found ? Math.round(total) : null;
}

// Prep/Cook/Total time — possibly several on one line ("Prep Time 15 mins Cook Time 30 mins").
function pTimes(line) {
  const out = {};
  const re = /\b(prep(?:aration)?|cook(?:ing)?|bake|baking|total|active|inactive)\s*(?:time)?\s*:?\s*((?:\d+(?:\.\d+)?\s*(?:hours?|hrs?|h|minutes?|mins?|m)\b[\s,and]*)+)/gi;
  let m;
  while ((m = re.exec(line)) !== null) {
    const kind = m[1].toLowerCase();
    const mins = pMinutes(m[2]);
    if (mins === null) { continue; }
    if (kind.startsWith('prep') || kind === 'active') { out.prepMinutes = mins; }
    else if (kind.startsWith('total')) { out.totalMinutes = mins; }
    else if (kind !== 'inactive') { out.cookMinutes = mins; }
  }
  return Object.keys(out).length ? out : null;
}

// "Serves 6", "Servings: 4-6", "Yield: 2 loaves", "Makes 24 cookies", "Makes 1 9x13 pan".
function pServings(line) {
  const m = line.match(/^\s*(serves|servings?|yields?|makes|portions?)\s*:?\s*(?:about\s+)?(\d+(?:\.\d+)?|\d+\/\d+)(?:\s*(?:-|to)\s*(\d+))?\s*(.*)$/i);
  if (!m) { return null; }
  const n = m[2].includes('/') ? Number(m[2].split('/')[0]) / Number(m[2].split('/')[1]) : Number(m[2]);
  const hi = m[3] ? Number(m[3]) : null;
  const rest = m[4].replace(/[().]/g, '').trim();
  const isServings = /^(serves|servings?|portions?)$/i.test(m[1]) || !rest || /^(servings?|people|persons|portions?)\b/i.test(rest);
  if (isServings) { return { servings: n, servingsMax: hi }; }
  return { yieldQuantity: n, yieldUnit: rest.slice(0, 50) };
}

function pIsMeta(line) {
  return /^\s*(serves|servings?|yields?|makes|portions?|prep|cook|total|bake|active)\b.*\d/i.test(line)
    || /^\s*(source|from|adapted from|recipe by|by|author)\s*:/i.test(line)
    || /^\s*(calories|course|cuisine|keyword|equipment)\s*:?/i.test(line);
}

// Looks like an ingredient: starts with an amount, or is short with no sentence punctuation.
function pLooksLikeIngredient(line) {
  const t = pStripBullet(line);
  if (/^(\d|a\s+(pinch|dash|handful|few)|an?\s+\d|one|two|three|half)\b/i.test(t)) { return true; }
  return t.length <= 50 && !/[.!?]$/.test(t) && t.split(' ').length <= 7;
}

// ---- One ingredient line -----------------------------------------------------------

const P_QTY = String.raw`(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)`;

/**
 * "1 (28 oz) can crushed tomatoes, drained" → { quantity:'1', unit:'can (28 oz)', name:'crushed tomatoes', preparation:'drained' }
 * Returns editor fields plus `flag` (a reason to check it) or null.
 */
function parseIngredientLine(raw) {
  const line = pStripBullet(raw).replace(/\s+/g, ' ').trim();
  const out = { quantity: '', quantityMax: '', unit: '', name: '', preparation: '', optional: false, flag: null, line };
  let t = line;

  // Optional, in any of the usual spellings.
  if (/\(optional\)|,\s*optional\b|^optional:?\s+|\s+optional$/i.test(t)) {
    out.optional = true;
    t = t.replace(/\s*\(optional\)\s*/i, ' ').replace(/,\s*optional\b/i, '').replace(/^optional:?\s+/i, '').replace(/\s+optional$/i, '').trim();
  }

  // Amount, or a range ("2-3", "2 to 3").
  let m = t.match(new RegExp(`^${P_QTY}(?:\\s*(?:-|to)\\s*${P_QTY})?\\s*(.*)$`, 'i'));
  if (m) {
    out.quantity = m[1];
    if (m[2]) { out.quantityMax = m[2]; }
    t = m[3];
  } else if ((m = t.match(/^(a|an|one)\s+(pinch|dash|handful|few|splash|sprig|clove|can|package|bunch|head|stick)\b\s*(.*)$/i))
      || (m = t.match(/^()(pinch|dash|splash|handful)\b\s*(.*)$/i))) {
    out.quantity = '1';             // "a pinch" / "Pinch of salt" — the amount is stated
    t = `${m[2]} ${m[3]}`;
  }

  // Package size: "1 (28 oz) can", "2 (15-ounce) cans".
  m = t.match(/^\(\s*([\d./]+\s*-?\s*(?:oz|ounce|ounces|lb|lbs|pound|g|gram|grams|ml|fl\.? oz)\.?)\s*\)\s*(\S+)\s*(.*)$/i);
  if (m && P_UNITS.has(m[2].toLowerCase().replace(/\.$/, ''))) {
    out.unit = `${m[2].toLowerCase().replace(/\.$/, '')} (${m[1].replace(/-/g, ' ').replace(/\s+/g, ' ').trim()})`;
    t = m[3];
  } else {
    // Unit: two-word ("fl oz") first, then one word ("cups", "Tbsp.", "T").
    const two = t.split(' ').slice(0, 2).join(' ').toLowerCase().replace(/\./g, '');
    const first = (t.split(' ')[0] || '').replace(/\.$/, '');
    if (P_TWO_WORD_UNITS.has(two)) {
      out.unit = 'fl oz';
      t = t.split(' ').slice(2).join(' ');
    } else if (P_EXACT_CASE_UNITS[first]) {
      out.unit = P_EXACT_CASE_UNITS[first];
      t = t.split(' ').slice(1).join(' ');
    } else if (out.quantity && P_UNITS.has(first.toLowerCase())) {
      out.unit = first.toLowerCase();
      t = t.split(' ').slice(1).join(' ');
      // "1 can (15 oz) beans"
      const pkg = t.match(/^\(\s*([\d./]+\s*-?\s*(?:oz|ounce|ounces|lb|lbs|pound|g|grams|ml)\.?)\s*\)\s*(.*)$/i);
      if (pkg) { out.unit = `${out.unit} (${pkg[1].replace(/-/g, ' ').trim()})`; t = pkg[2]; }
    }
  }
  t = t.replace(/^of\s+/i, '');

  // Size words ("1 large onion") become part of the preparation.
  const prep = [];
  // Only without a unit: "1 large onion" → onion (large), but "1/2 cup small pasta" keeps its name.
  const sizeWord = (t.split(' ')[0] || '').toLowerCase();
  if (!out.unit && P_SIZES.has(sizeWord)) {
    prep.push(sizeWord);
    t = t.split(' ').slice(1).join(' ');
  }

  // "to taste", "as needed", "for serving" — no amount, and that's fine.
  const loose = t.match(/,?\s*\b(to taste|as needed|as desired|for (?:serving|garnish|topping|dusting|frying|greasing)|or more to taste)\b\.?\s*$/i);
  if (loose) {
    prep.push(loose[1].toLowerCase());
    t = t.slice(0, loose.index).trim();
  }
  // Parenthetical notes: "(about 2 cups)" → preparation.
  t = t.replace(/\s*\(([^)]*)\)\s*/g, (all, inner) => { prep.unshift(inner.trim()); return ' '; }).trim();
  // "onion, diced" → name "onion", preparation "diced".
  const comma = t.indexOf(',');
  if (comma > 0) {
    prep.push(t.slice(comma + 1).trim());
    t = t.slice(0, comma).trim();
  }
  out.name = t.replace(/[.;]+$/, '').trim();
  out.preparation = prep.filter(Boolean).join(', ');

  if (!out.name) {
    out.flag = 'No ingredient name found';
  } else if (!out.quantity && !loose && !out.optional) {
    out.flag = 'No amount found — add one, or leave it blank for "to taste"';
  } else if (line.length > 90) {
    out.flag = 'Long line — check it was split correctly';
  }
  return out;
}

// ---- The whole recipe --------------------------------------------------------------

/**
 * Returns { version, title, description, servings, yieldQuantity, yieldUnit, prepMinutes,
 *   cookMinutes, totalMinutes, source, generalNotes, ingredients[], methods[], steps[], flags[] }.
 * Field values are strings in the form the recipe editor takes. `flags` are recipe-wide reasons
 * to check; each ingredient carries its own `flag`.
 */
function parseRecipeText(text) {
  const r = {
    version: PARSER_VERSION, title: '', description: '', servings: '', yieldQuantity: '', yieldUnit: '',
    prepMinutes: '', cookMinutes: '', totalMinutes: '', source: '', generalNotes: '',
    ingredients: [], methods: [], steps: [], flags: [],
  };
  const lines = pNormalize(text).split('\n').map(l => l.trim());
  const description = [];
  const notes = [];
  let mode = 'start';          // start | ingredients | steps | notes
  let section = '';
  let methodKey = '';
  let numbered = false;        // steps written as "1. …"
  let blankBefore = true;
  const methodKeyFor = name => {
    let m = r.methods.find(x => x.name === name);
    if (!m) { m = { key: `n${r.methods.length + 1}`, name }; r.methods.push(m); }
    return m.key;
  };
  const addStep = textLine => {
    const s = textLine.trim();
    if (s) { r.steps.push({ methodKey, text: s }); }
  };

  for (const raw of lines) {
    if (!raw) { blankBefore = true; continue; }
    const line = raw;

    // Title: the first real line.
    if (!r.title) {
      const h = pHeading(line);
      // A title may name a method — "Chili Verde (Crockpot)" is a title, not a Slow Cooker heading.
      if (!h || h.type === 'section' || (h.type === 'steps' && h.method)) {
        r.title = line.replace(/^#+\s*/, '').replace(/^recipe\s*:\s*/i, '').replace(/\*\*/g, '').trim().slice(0, 200);
        blankBefore = false;
        continue;
      }
    }

    // Servings, yield, times and source — wherever they appear before the steps.
    if (mode !== 'steps' || pIsMeta(line)) {
      const sv = pServings(line);
      const tm = pTimes(line);
      if (sv || tm) {
        if (sv && sv.servings !== undefined && !r.servings) {
          r.servings = String(sv.servings);
          if (sv.servingsMax) { r.flags.push(`Servings were given as ${sv.servings}–${sv.servingsMax}; ${sv.servings} was used`); }
        }
        if (sv && sv.yieldQuantity !== undefined && !r.yieldQuantity) {
          r.yieldQuantity = String(sv.yieldQuantity);
          r.yieldUnit = sv.yieldUnit;
        }
        if (tm) {
          for (const [k, v] of Object.entries(tm)) { if (!r[k]) { r[k] = String(v); } }
        }
        blankBefore = false;
        continue;
      }
      const src = line.match(/^\s*(?:source|from|adapted from|recipe by|by|author)\s*:\s*(.+)$/i);
      if (src && !r.source) { r.source = src[1].trim().slice(0, 500); blankBefore = false; continue; }
      if (/^\s*(calories|course|cuisine|keyword|equipment)\s*:?/i.test(line) && mode !== 'notes') { blankBefore = false; continue; }
    }

    const h = pHeading(line);
    if (h) {
      if (h.type === 'ingredients') { mode = 'ingredients'; section = ''; }
      else if (h.type === 'steps') { mode = 'steps'; methodKey = h.method ? methodKeyFor(h.method) : ''; numbered = false; }
      else if (h.type === 'notes') { mode = 'notes'; notes.push(`${h.label}:`); }
      else if (h.type === 'section') {
        if (mode === 'steps' || mode === 'notes') {
          // Not a list section here — treat the line as part of the text.
          if (mode === 'notes') { notes.push(line); } else { addStep(line); }
        } else {
          mode = 'ingredients';
          section = h.label;
        }
      }
      blankBefore = false;
      continue;
    }

    if (mode === 'start') {
      // No "Ingredients" heading: an amount-led line starts the list; a numbered line starts steps.
      if (pStepNumber(line) && r.ingredients.length) { mode = 'steps'; }
      else if (/^(\d|[-*•▢☐])/.test(line) && pLooksLikeIngredient(line)) { mode = 'ingredients'; }
      else { description.push(line); blankBefore = false; continue; }
    }

    if (mode === 'ingredients') {
      const num = pStepNumber(line);
      // A long sentence (or a numbered sentence) after the list means the steps have begun.
      if ((num && num.text.length > 40) || (!pLooksLikeIngredient(line) && line.length > 60 && /[.!]$/.test(line))) {
        mode = 'steps';
      } else {
        const ing = parseIngredientLine(line);
        ing.section = section;
        // "For serving: grated Parmesan" — no amount is normal there.
        if (ing.flag && ing.flag.startsWith('No amount') && /serv|garnish|topping|optional/i.test(section)) { ing.flag = null; }
        r.ingredients.push(ing);
        blankBefore = false;
        continue;
      }
    }

    if (mode === 'steps') {
      const num = pStepNumber(line);
      const last = r.steps[r.steps.length - 1];
      // "Step 1: Heat the Milk" + paragraph (even after a blank line) is one step.
      const lastIsStepTitle = numbered && last && last.text.length <= 40 && !/[.,;!?:]$/.test(last.text) && !last.text.includes(' — ');
      if (num) {
        numbered = true;
        addStep(num.text);
      } else if (lastIsStepTitle) {
        last.text += ` — ${pStripBullet(line)}`;
      } else if (numbered && !blankBefore && r.steps.length) {
        // A wrapped line belongs to the step above it.
        r.steps[r.steps.length - 1].text += ` ${pStripBullet(line)}`;
      } else {
        addStep(pStripBullet(line));
      }
      blankBefore = false;
      continue;
    }

    if (mode === 'notes') {
      notes.push(pStripBullet(line));
      blankBefore = false;
    }
  }

  r.description = description.join('\n').slice(0, 5000);
  r.generalNotes = notes.join('\n').replace(/:\n/g, ':\n').trim().slice(0, 10000);

  if (!r.title) { r.flags.push('No title found'); }
  if (!r.ingredients.length) { r.flags.push('No ingredients found — add them below'); }
  if (!r.steps.length) { r.flags.push('No steps found — add them below'); }
  if (!r.servings && !r.yieldQuantity) { r.flags.push('Servings not found — add them so the recipe can be scaled'); }
  if (r.methods.length && !r.steps.some(s => !s.methodKey)) {
    // Every step belongs to a method: fine. Mention it so people check the split.
    r.flags.push(`Steps were split by cooking method (${r.methods.map(m => m.name).join(', ')}) — check each step is under the right one`);
  }
  return r;
}

// Words that matter in a title, for "you may already have this" checks.
function titleWords(title) {
  return String(title).toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(w => w && !P_STOP.has(w));
}

if (typeof module !== 'undefined') {
  module.exports = { parseRecipeText, parseIngredientLine, titleWords, PARSER_VERSION };
}
