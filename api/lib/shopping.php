<?php
// Shopping lists (docs/ARCHITECTURE.md §6): the Senior Family Cookbook model, server-side.
//
// Each recipe on a list contributes its scaled ingredients. A contribution merges into an
// existing item only when it is the same ingredient AND a compatible unit; otherwise it becomes
// its own item. Every contribution is kept in sft_shopping_item_sources so a recipe can later be
// removed (its share subtracted) or the list rebuilt.

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/units.php';
require_once __DIR__ . '/recipes.php';

// ---------------------------------------------------------------------------
// Grocery departments — ported from the Senior Family Cookbook (app.js DEPT_ORDER/DEPT_KEYWORDS)
// ---------------------------------------------------------------------------

const DEPT_ORDER = [
  'Produce', 'Meat & Seafood', 'Dairy & Eggs', 'Bakery & Bread',
  'Baking', 'Dry Goods & Pasta', 'Canned & Jarred',
  'Spices & Seasonings', 'Oils & Condiments', 'Frozen', 'Other',
];

const DEPT_KEYWORDS = [
  'Produce' => ['onion','garlic','tomato','pepper','carrot','celery','potato','lettuce',
    'spinach','kale','mushroom','lemon','lime','orange','apple','banana','cucumber',
    'zucchini','squash','corn','green bean','pea','broccoli','cauliflower','asparagus',
    'beet','radish','scallion','shallot','chive','parsley','cilantro','basil','mint',
    'thyme','rosemary','sage','dill','ginger','jalapeño','jalapeno','avocado',
    'cabbage','artichoke','eggplant','fennel','leek','arugula','chard','collard',
    'turnip','parsnip','yam','sweet potato','pumpkin','butternut','acorn','spaghetti squash',
    'pear','peach','plum','cherry','grape','melon','watermelon','mango','papaya',
    'pineapple','kiwi','fig','pomegranate','cranberry','blueberry','strawberry',
    'raspberry','blackberry','rhubarb','berry','berries','fresh herb','scallion','bok choy'],
  'Meat & Seafood' => ['chicken','beef','pork','turkey','lamb','fish','salmon','tuna',
    'shrimp','crab','lobster','sausage','bacon','ham','steak','roast','veal','duck',
    'venison','anchovy','clam','mussel','scallop','tilapia','cod','halibut','flounder',
    'meatball','brisket','ribs','tenderloin','sirloin','chuck','filet','fillet',
    'prosciutto','pancetta','pepperoni','salami','chorizo','kielbasa','ground beef',
    'ground turkey','ground pork','ground lamb','ground chicken','hot dog','bratwurst'],
  'Dairy & Eggs' => ['milk','cream','butter','cheese','yogurt','egg','sour cream',
    'cottage cheese','ricotta','mozzarella','parmesan','cheddar','cream cheese',
    'half-and-half','half and half','whipping cream','heavy cream','buttermilk',
    'kefir','ghee','brie','feta','gouda','swiss','gruyere','provolone','mascarpone',
    'colby','monterey jack','goat cheese','queso','creme fraiche'],
  'Bakery & Bread' => ['bread','roll','baguette','pita','tortilla','wrap','bun',
    'croissant','bagel','english muffin','crumpet','naan','flatbread','lavash',
    'sourdough','ciabatta','focaccia','challah'],
  'Baking' => ['flour','sugar','baking powder','baking soda','yeast','cocoa powder',
    'chocolate chip','vanilla','cornstarch','corn starch','powdered sugar',
    'confectioner','brown sugar','molasses','honey','maple syrup','shortening','lard',
    'almond flour','bread flour','cake flour','whole wheat flour','oat flour',
    'tapioca','arrowroot','gelatin','pectin','extract','food coloring','sprinkle',
    'cocoa','dark chocolate','white chocolate','bittersweet','semisweet','meringue',
    'cream of tartar','active dry','instant yeast'],
  'Dry Goods & Pasta' => ['pasta','spaghetti','penne','fettuccine','linguine','rigatoni',
    'farfalle','orzo','lasagna','noodle','rice','oat','oatmeal','cereal','quinoa',
    'barley','farro','bulgur','couscous','lentil','bean','chickpea','black bean',
    'kidney bean','pinto bean','navy bean','white bean','bread crumb','panko',
    'cracker','broth','stock','ramen','udon','soba','rice noodle','vermicelli',
    'polenta','grits','cornmeal','millet','buckwheat','spelt','wild rice','brown rice',
    'jasmine rice','basmati','arborio','fregola','macaroni','rotini','ziti','cavatappi'],
  'Canned & Jarred' => ['tomato sauce','tomato paste','diced tomato','crushed tomato',
    'canned','salsa','pickle','olive','jam','jelly','peanut butter','almond butter',
    'tahini','coconut milk','coconut cream','roasted pepper','sun-dried','artichoke heart',
    'chili','soup','applesauce','fruit preserves','caramel sauce','hot fudge'],
  'Spices & Seasonings' => ['salt','pepper','cumin','paprika','cinnamon','oregano',
    'coriander','turmeric','cayenne','chili powder','garlic powder','onion powder',
    'bay leaf','nutmeg','clove','cardamom','curry','allspice','anise','caraway',
    'celery seed','fennel seed','fenugreek','lemongrass','mace','marjoram',
    'mustard seed','saffron','star anise','sumac','tarragon','herbes de provence',
    'italian seasoning','old bay','cajun','creole','dried basil','dried oregano',
    'dried thyme','dried rosemary','dried parsley','red pepper flake','chili flake',
    'smoked paprika','seasoning','spice',"za'atar"],
  'Oils & Condiments' => ['olive oil','vegetable oil','canola oil','sesame oil',
    'coconut oil','avocado oil','peanut oil','grapeseed oil','vinegar','balsamic',
    'apple cider vinegar','rice vinegar','wine vinegar','soy sauce','tamari',
    'worcestershire','hot sauce','ketchup','mustard','mayo','mayonnaise','dressing',
    'relish','capers','fish sauce','oyster sauce','hoisin','teriyaki','sriracha',
    'miso','cooking spray','nonstick',' oil'],
  'Frozen' => ['frozen','ice cream','sorbet','popsicle'],
];

// The Senior Family Cookbook's keyword lists, with one improvement: the LONGEST matching keyword
// wins (ties go to the earlier department), so "cornstarch" is Baking rather than Produce ("corn"),
// "garlic powder" is Spices rather than Produce ("garlic"), and "chili powder" isn't Canned ("chili").
function guessDepartment(string $name): string {
  $n = mb_strtolower($name);
  $best = 'Other';
  $bestLen = 0;
  foreach (DEPT_ORDER as $dept) {
    if ($dept === 'Other') { break; }
    foreach (DEPT_KEYWORDS[$dept] as $kw) {
      $len = mb_strlen($kw);
      if ($len > $bestLen && mb_strpos($n, $kw) !== false) { $best = $dept; $bestLen = $len; }
    }
  }
  return $best;
}

function cleanDepartment($v): string {
  $d = trim((string)$v);
  return in_array($d, DEPT_ORDER, true) ? $d : 'Other';
}

// ---------------------------------------------------------------------------
// Units: which contributions can be added together, and how
// ---------------------------------------------------------------------------

// US volume in teaspoons, US weight in ounces (as the Senior Family Cookbook). Metric is
// kept separate — no automatic conversion between systems.
const VOL_TO_TSP = ['tsp' => 1, 'tbsp' => 3, 'fl oz' => 6, 'cup' => 48, 'pint' => 96, 'quart' => 192, 'gallon' => 768];
const MASS_TO_OZ = ['oz' => 1, 'lb' => 16];
const METRIC_VOL_TO_ML = ['ml' => 1, 'liter' => 1000];
const METRIC_MASS_TO_G = ['g' => 1, 'kg' => 1000];

/** The "can these be added" group for a unit, or null when only an identical unit can combine. */
function unitGroup(?string $unit, string $dimension, ?string $package): string {
  $u = (string)$unit;
  if (isset(VOL_TO_TSP[$u])) { return 'vol'; }
  if (isset(MASS_TO_OZ[$u])) { return 'mass'; }
  if (isset(METRIC_VOL_TO_ML[$u])) { return 'mvol'; }
  if (isset(METRIC_MASS_TO_G[$u])) { return 'mmass'; }
  if ($dimension === 'package') { return 'pkg:' . $u . '|' . mb_strtolower((string)$package); }
  return 'unit:' . mb_strtolower($u);   // count units ("clove") or none ("2 onions")
}

/** Converts $qty $unit into the group's base unit (tsp, oz, ml, g), or returns it as-is. */
function toBase(float $qty, ?string $unit): float {
  $u = (string)$unit;
  return $qty * (VOL_TO_TSP[$u] ?? MASS_TO_OZ[$u] ?? METRIC_VOL_TO_ML[$u] ?? METRIC_MASS_TO_G[$u] ?? 1);
}

/** Can $qty in $fromUnit be expressed in $toUnit? (same group, convertible) */
function convertQuantity(float $qty, ?string $fromUnit, ?string $toUnit): ?float {
  $f = (string)$fromUnit;
  $t = (string)$toUnit;
  if ($f === $t) { return $qty; }
  foreach ([VOL_TO_TSP, MASS_TO_OZ, METRIC_VOL_TO_ML, METRIC_MASS_TO_G] as $table) {
    if (isset($table[$f], $table[$t])) { return $qty * $table[$f] / $table[$t]; }
  }
  return null;
}

/** Friendliest unit for a base amount — tsp → tbsp → cup → quart, oz → lb (Senior Family rules). */
function readableFromBase(float $base, string $group, ?string $fallbackUnit): array {
  switch ($group) {
    case 'vol':
      if ($base >= 192) { return [$base / 192, 'quart']; }
      if ($base >= 48) { return [$base / 48, 'cup']; }
      if ($base >= 3) { return [$base / 3, 'tbsp']; }
      return [$base, 'tsp'];
    case 'mass':
      return $base >= 16 ? [$base / 16, 'lb'] : [$base, 'oz'];
    case 'mvol':
      return $base >= 1000 ? [$base / 1000, 'liter'] : [$base, 'ml'];
    case 'mmass':
      return $base >= 1000 ? [$base / 1000, 'kg'] : [$base, 'g'];
  }
  return [$base, $fallbackUnit];
}

// "Onions" and "onion" are the same thing; "glass" is not "glas".
function itemIdentityName(string $name): string {
  $n = normalizeName($name);
  if (preg_match('/(ch|sh|x|o)es$/', $n)) { return mb_substr($n, 0, -2); }
  if (preg_match('/ies$/', $n)) { return mb_substr($n, 0, -3) . 'y'; }
  if (preg_match('/[^s]s$/', $n)) { return mb_substr($n, 0, -1); }
  return $n;
}

function itemIdentity(?int $ingredientId, string $name): string {
  return $ingredientId !== null ? 'ing:' . $ingredientId : 'name:' . itemIdentityName($name);
}

// ---------------------------------------------------------------------------
// Change tracking for live updates (§6.7)
// ---------------------------------------------------------------------------

/** Bumps the list's change counter once per request and returns the new value. */
function listSeq(int $listId): int {
  static $seqs = [];
  if (!isset($seqs[$listId])) {
    exec_('UPDATE sft_shopping_lists SET change_seq = LAST_INSERT_ID(change_seq + 1) WHERE id = ?', 'i', [$listId]);
    $seqs[$listId] = (int)q1('SELECT LAST_INSERT_ID() AS s')['s'];
  }
  return $seqs[$listId];
}

/** Marks an item as changed (so other devices pick it up) by $userId. */
function touchItem(int $listId, int $itemId, ?int $userId): void {
  exec_('UPDATE sft_shopping_items SET changed_seq = ?, row_version = row_version + 1, updated_by = ? WHERE id = ?',
    'iii', [listSeq($listId), $userId, $itemId]);
}

// ---------------------------------------------------------------------------
// Contributions
// ---------------------------------------------------------------------------

/** Scale factor for a recipe: servings or batches requested ÷ the recipe's base. */
function scaleFactor(array $recipe, string $mode, float $value): float {
  if ($mode === 'batches') {
    $base = $recipe['yield_quantity'] !== null ? (float)$recipe['yield_quantity'] : 1.0;
  } else {
    $base = $recipe['servings'] !== null ? (float)$recipe['servings'] : 0.0;
  }
  if ($base <= 0) { return 1.0; } // no base to scale from — use as written
  return $value / $base;
}

/**
 * Adds the scaled ingredients of one list-recipe to the list.
 * $excluded: recipe_ingredient ids the user already has ("have it" — Senior Family step 2).
 */
function contributeRecipe(int $listId, int $listRecipeId, int $recipeId, float $factor, array $excluded, int $userId): void {
  $skip = array_flip(array_map('intval', $excluded));
  $rows = q(
    'SELECT ri.id, ri.quantity, ri.quantity_max, ri.unit, ri.unit_dimension, ri.package_qualifier, ri.item_name,
            ri.ingredient_id, i.default_department
       FROM sft_recipe_ingredients ri LEFT JOIN sft_ingredients i ON i.id = ri.ingredient_id
      WHERE ri.recipe_id = ? ORDER BY ri.position', 'i', [$recipeId]);
  foreach ($rows as $row) {
    if (isset($skip[(int)$row['id']])) { continue; }
    // A range ("2–3") shops for the larger amount.
    $q = $row['quantity_max'] !== null ? (float)$row['quantity_max'] : ($row['quantity'] !== null ? (float)$row['quantity'] : null);
    addContribution($listId, $listRecipeId, $row, $q !== null ? $q * $factor : null, $userId);
  }
}

function addContribution(int $listId, int $listRecipeId, array $ing, ?float $qty, int $userId): void {
  $ingredientId = $ing['ingredient_id'] !== null ? (int)$ing['ingredient_id'] : null;
  $identity = itemIdentity($ingredientId, $ing['item_name']);
  $unit = $ing['unit'];
  $group = unitGroup($unit, $ing['unit_dimension'], $ing['package_qualifier']);
  $key = $identity . '|' . ($qty === null ? 'noqty' : $group);

  // 1. Same ingredient, compatible unit, not yet checked off → merge.
  $item = null;
  if ($qty === null) {
    // "Salt to taste": joins any existing salt item; adds no amount.
    $item = q1("SELECT * FROM sft_shopping_items WHERE list_id = ? AND deleted_at IS NULL AND checked = 0
                 AND LEFT(merge_key, CHAR_LENGTH(?) + 1) = CONCAT(?, '|') ORDER BY id LIMIT 1", 'iss', [$listId, $identity, $identity]);
  } else {
    $item = q1('SELECT * FROM sft_shopping_items WHERE list_id = ? AND deleted_at IS NULL AND checked = 0 AND merge_key = ?
                ORDER BY id LIMIT 1', 'is', [$listId, $key]);
    if (!$item) {
      // An amount-less placeholder ("salt to taste") becomes this item if nobody edited it.
      $item = q1("SELECT * FROM sft_shopping_items WHERE list_id = ? AND deleted_at IS NULL AND checked = 0 AND is_edited = 0
                   AND merge_key = ? ORDER BY id LIMIT 1", 'is', [$listId, $identity . '|noqty']);
      if ($item) {
        exec_('UPDATE sft_shopping_items SET merge_key = ? WHERE id = ?', 'si', [$key, (int)$item['id']]);
        $item['merge_key'] = $key;
      }
    }
    // A hand-edited item can still take more, if its (edited) unit converts.
    if ($item && (int)$item['is_edited'] === 1 && $item['quantity'] !== null && convertQuantity($qty, $unit, $item['unit']) === null) {
      $item = null;
    }
  }

  if ($item) {
    $itemId = (int)$item['id'];
    exec_('INSERT INTO sft_shopping_item_sources (item_id, list_recipe_id, recipe_ingredient_id, contributed_quantity, contributed_unit)
           VALUES (?, ?, ?, ?, ?)', 'iiids', [$itemId, $listRecipeId, (int)$ing['id'], $qty, $unit]);
    if ($qty !== null) {
      if ((int)$item['is_edited'] === 1) {
        // Respect the person's edit: add in their unit.
        $add = convertQuantity($qty, $unit, $item['unit']) ?? $qty;
        exec_('UPDATE sft_shopping_items SET quantity = ? WHERE id = ?', 'di', [round((float)$item['quantity'] + $add, 4), $itemId]);
      } else {
        recomputeItem($itemId);
      }
    }
    touchItem($listId, $itemId, $userId);
    return;
  }

  // 2. Otherwise a new item.
  $dept = $ing['default_department'] ?? null;
  $dept = $dept !== null && in_array($dept, DEPT_ORDER, true) ? $dept : guessDepartment($ing['item_name']);
  $itemId = exec_(
    'INSERT INTO sft_shopping_items (list_id, ingredient_id, merge_key, label, quantity, unit, department, position, updated_by)
     VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)',
    'iissssii', [$listId, $ingredientId, $key, mb_substr($ing['item_name'], 0, 200), $unit, $dept, nextItemPosition($listId), $userId]);
  if ($ing['unit_dimension'] === 'package' && $ing['package_qualifier'] !== null) {
    exec_('UPDATE sft_shopping_items SET notes = ? WHERE id = ?', 'si', [mb_substr($ing['package_qualifier'], 0, 255), $itemId]);
  }
  exec_('INSERT INTO sft_shopping_item_sources (item_id, list_recipe_id, recipe_ingredient_id, contributed_quantity, contributed_unit)
         VALUES (?, ?, ?, ?, ?)', 'iiids', [$itemId, $listRecipeId, (int)$ing['id'], $qty, $unit]);
  recomputeItem($itemId);
  touchItem($listId, $itemId, $userId);
}

// Rounds a measured amount UP to the nearest kitchen fraction for shopping:
// 5.28 tbsp → 5⅓, 9.92 tbsp → 10, 2.666 cups → 2⅔, 1.25 lb stays 1¼.
function friendlyAmountUp(float $q): float {
  $whole = floor($q);
  $frac = $q - $whole;
  if ($frac < 0.02) { return $whole; }
  foreach ([1 / 8, 1 / 4, 1 / 3, 1 / 2, 2 / 3, 3 / 4, 7 / 8, 1] as $f) {
    if ($f >= $frac - 0.02) { return $whole + $f; }
  }
  return $whole + 1;
}

function nextItemPosition(int $listId): int {
  return (int)q1('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM sft_shopping_items WHERE list_id = ?', 'i', [$listId])['p'];
}

/**
 * Sets an un-edited item's amount from its sources: summed in base units, shown in the
 * friendliest unit; countable things round up to whole ("2.5 onions" → 3).
 */
function recomputeItem(int $itemId): void {
  $item = q1('SELECT * FROM sft_shopping_items WHERE id = ?', 'i', [$itemId]);
  if (!$item || (int)$item['is_edited'] === 1) { return; }
  $sources = q('SELECT contributed_quantity, contributed_unit FROM sft_shopping_item_sources WHERE item_id = ? AND contributed_quantity IS NOT NULL', 'i', [$itemId]);
  if (!$sources) {
    exec_('UPDATE sft_shopping_items SET quantity = NULL WHERE id = ?', 'i', [$itemId]);
    return;
  }
  $unit = $sources[0]['contributed_unit'];
  $dimension = isset(VOL_TO_TSP[(string)$unit]) || isset(METRIC_VOL_TO_ML[(string)$unit]) ? 'volume'
    : (isset(MASS_TO_OZ[(string)$unit]) || isset(METRIC_MASS_TO_G[(string)$unit]) ? 'mass' : 'other');
  $group = unitGroup($unit, $dimension, null);
  $base = 0.0;
  foreach ($sources as $s) { $base += toBase((float)$s['contributed_quantity'], $s['contributed_unit']); }
  [$qty, $displayUnit] = readableFromBase($base, $group, $unit);
  $qty = $dimension === 'other' ? ceil($qty - 1e-6) : friendlyAmountUp($qty);
  exec_('UPDATE sft_shopping_items SET quantity = ?, unit = ? WHERE id = ?', 'dsi', [round($qty, 4), $displayUnit, $itemId]);
}

/** Takes one list-recipe's contributions back out (removing a recipe from the list). */
function withdrawRecipe(int $listId, int $listRecipeId, int $userId): void {
  $itemIds = array_map(fn($r) => (int)$r['item_id'],
    q('SELECT DISTINCT item_id FROM sft_shopping_item_sources WHERE list_recipe_id = ?', 'i', [$listRecipeId]));
  exec_('DELETE FROM sft_shopping_item_sources WHERE list_recipe_id = ?', 'i', [$listRecipeId]);
  foreach ($itemIds as $itemId) {
    $item = q1('SELECT * FROM sft_shopping_items WHERE id = ? AND deleted_at IS NULL', 'i', [$itemId]);
    if (!$item) { continue; }
    $left = (int)q1('SELECT COUNT(*) AS n FROM sft_shopping_item_sources WHERE item_id = ?', 'i', [$itemId])['n'];
    if ((int)$item['is_edited'] === 1) {
      // Someone typed their own amount — leave it, but flag it.
      exec_('UPDATE sft_shopping_items SET needs_review = 1 WHERE id = ?', 'i', [$itemId]);
    } elseif ($left === 0 && (int)$item['is_manual'] === 0) {
      exec_('UPDATE sft_shopping_items SET deleted_at = NOW() WHERE id = ?', 'i', [$itemId]);
    } else {
      recomputeItem($itemId);
    }
    touchItem($listId, $itemId, $userId);
  }
}

/**
 * "Rebuild from recipes" (Senior Family "Reset"): throws away edits to recipe items and builds
 * them again from the recipes on the list. Items added by hand are kept.
 */
function rebuildList(int $listId, int $userId): void {
  foreach (q('SELECT id FROM sft_shopping_items WHERE list_id = ? AND is_manual = 0 AND deleted_at IS NULL', 'i', [$listId]) as $row) {
    exec_('UPDATE sft_shopping_items SET deleted_at = NOW() WHERE id = ?', 'i', [(int)$row['id']]);
    touchItem($listId, (int)$row['id'], $userId);
  }
  exec_('DELETE s FROM sft_shopping_item_sources s JOIN sft_shopping_items i ON i.id = s.item_id WHERE i.list_id = ?', 'i', [$listId]);
  foreach (q('SELECT * FROM sft_shopping_list_recipes WHERE list_id = ? ORDER BY id', 'i', [$listId]) as $lr) {
    contributeRecipe($listId, (int)$lr['id'], (int)$lr['recipe_id'], (float)$lr['scale_factor'],
      json_decode((string)$lr['excluded_ingredient_ids'], true) ?: [], $userId);
  }
}

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

function listRole(int $userId, int $listId): ?string {
  $row = q1('SELECT m.role FROM sft_shopping_list_members m JOIN sft_shopping_lists l ON l.id = m.list_id
             WHERE m.list_id = ? AND m.user_id = ? AND l.deleted_at IS NULL', 'ii', [$listId, $userId]);
  return $row ? $row['role'] : null;
}

function requireListRole(int $userId, int $listId, array $roles): string {
  $role = listRole($userId, $listId);
  if ($role === null) { notFound('Shopping list'); }
  if (!in_array($role, $roles, true)) { fail('You can view this list but not change it', 403); }
  return $role;
}

/** The item, if it belongs to a list the user can use with one of $roles. */
function requireItem(int $userId, int $itemId, array $roles): array {
  $item = q1('SELECT * FROM sft_shopping_items WHERE id = ? AND deleted_at IS NULL', 'i', [$itemId]);
  if (!$item || listRole($userId, (int)$item['list_id']) === null) { notFound('Item'); }
  requireListRole($userId, (int)$item['list_id'], $roles);
  return $item;
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

function shapeItem(array $i, array $sourcesByItem): array {
  return [
    'id' => (int)$i['id'], 'label' => $i['label'],
    'quantity' => $i['quantity'] !== null ? (float)$i['quantity'] : null, 'unit' => $i['unit'],
    'notes' => $i['notes'], 'department' => $i['department'], 'departmentManual' => (bool)$i['department_manual'],
    'storeId' => $i['store_id'] !== null ? (int)$i['store_id'] : null,
    'assignedUserId' => $i['assigned_user_id'] !== null ? (int)$i['assigned_user_id'] : null,
    'manual' => (bool)$i['is_manual'], 'edited' => (bool)$i['is_edited'], 'needsReview' => (bool)$i['needs_review'],
    'checked' => (bool)$i['checked'], 'position' => (int)$i['position'], 'rowVersion' => (int)$i['row_version'],
    'sources' => $sourcesByItem[(int)$i['id']] ?? [],
  ];
}

/** Items (optionally only those changed after $since) with the recipe titles they came from. */
function listItems(int $listId, ?int $since = null): array {
  $sql = 'SELECT * FROM sft_shopping_items WHERE list_id = ?';
  $types = 'i';
  $params = [$listId];
  if ($since !== null) { $sql .= ' AND changed_seq > ?'; $types .= 'i'; $params[] = $since; }
  else { $sql .= ' AND deleted_at IS NULL'; }
  $rows = q($sql . ' ORDER BY position, id', $types, $params);
  if (!$rows) { return ['items' => [], 'deleted' => []]; }
  $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
  $sourcesByItem = [];
  foreach (q("SELECT s.item_id, r.title FROM sft_shopping_item_sources s
                JOIN sft_shopping_list_recipes lr ON lr.id = s.list_recipe_id JOIN sft_recipes r ON r.id = lr.recipe_id
               WHERE s.item_id IN ($ids) GROUP BY s.item_id, r.id, r.title ORDER BY r.title") as $s) {
    $sourcesByItem[(int)$s['item_id']][] = $s['title'];
  }
  $items = [];
  $deleted = [];
  foreach ($rows as $r) {
    if ($r['deleted_at'] !== null) { $deleted[] = (int)$r['id']; continue; }
    $items[] = shapeItem($r, $sourcesByItem);
  }
  return ['items' => $items, 'deleted' => $deleted];
}

function listRecipes(int $listId): array {
  return array_map(fn($r) => [
    'id' => (int)$r['id'], 'recipeId' => (int)$r['recipe_id'], 'title' => $r['title'], 'deleted' => $r['deleted_at'] !== null,
    'scaleMode' => $r['scale_mode'], 'scaleValue' => (float)$r['scale_value'],
    'excludedIngredientIds' => json_decode((string)$r['excluded_ingredient_ids'], true) ?: [],
  ], q('SELECT lr.*, r.title, r.deleted_at FROM sft_shopping_list_recipes lr JOIN sft_recipes r ON r.id = lr.recipe_id
         WHERE lr.list_id = ? ORDER BY lr.id', 'i', [$listId]));
}

function listMembersShort(int $listId): array {
  return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['display_name'] ?: $r['username'], 'role' => $r['role']],
    q("SELECT u.id, u.username, u.display_name, m.role FROM sft_shopping_list_members m JOIN users u ON u.id = m.user_id
        WHERE m.list_id = ? ORDER BY FIELD(m.role, 'owner', 'editor', 'viewer'), u.display_name", 'i', [$listId]));
}

/** Stores this user can pick: built-in, their own, and any already used on this list. */
function storesFor(int $userId, ?int $listId): array {
  $sql = "SELECT id, name, scope FROM sft_stores WHERE scope = 'system' OR (scope = 'user' AND owner_user_id = ?)";
  $types = 'i';
  $params = [$userId];
  if ($listId !== null) {
    $sql .= ' OR id IN (SELECT store_id FROM sft_shopping_items WHERE list_id = ? AND store_id IS NOT NULL AND deleted_at IS NULL)';
    $types .= 'i';
    $params[] = $listId;
  }
  return array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'custom' => $s['scope'] === 'user'],
    q($sql . ' ORDER BY scope = \'user\', sort_order, name', $types, $params));
}

// ---------------------------------------------------------------------------
// Input helpers for the list actions
// ---------------------------------------------------------------------------

function scaleModeParam(array $b): string {
  $mode = (string)($b['scaleMode'] ?? $b['mode'] ?? 'servings');
  if (!in_array($mode, ['servings', 'batches'], true)) { fail('Choose servings or batches', 400, 'scaleMode'); }
  return $mode;
}

/**
 * Scale and "have it" choices for a recipe going onto a list: [mode, value, factor, excludedIds].
 * Without a value, the person's saved amount or else the recipe's own amount is used; without exclusions, optional ingredients
 * are left off (they're unticked by default in the add sheet too).
 */
function listRecipeSettings(int $recipeId, array $b, ?int $userId = null): array {
  $recipe = q1('SELECT servings, yield_quantity FROM sft_recipes WHERE id = ?', 'i', [$recipeId]);
  // No amount given (e.g. several recipes added at once): use this person's saved amount, if any.
  if ($userId !== null && ($b['scaleValue'] ?? null) === null && !isset($b['scaleMode'])) {
    $saved = q1('SELECT saved_scale_mode, saved_scale FROM sft_recipe_user_state WHERE recipe_id = ? AND user_id = ? AND saved_scale IS NOT NULL', 'ii', [$recipeId, $userId]);
    if ($saved) { $b['scaleMode'] = $saved['saved_scale_mode']; $b['scaleValue'] = (float)$saved['saved_scale']; }
  }
  $mode = scaleModeParam($b);
  if ($mode === 'batches' && $recipe['yield_quantity'] === null) { $mode = 'servings'; }
  $value = cleanNumber($b['scaleValue'] ?? null, 'scaleValue', 10000);
  if ($value === null) {
    $value = $mode === 'batches' ? (float)$recipe['yield_quantity'] : ($recipe['servings'] !== null ? (float)$recipe['servings'] : 1.0);
  }
  $factor = scaleFactor($recipe, $mode, $value);
  $ids = array_map(fn($r) => (int)$r['id'], q('SELECT id FROM sft_recipe_ingredients WHERE recipe_id = ?', 'i', [$recipeId]));
  if (isset($b['excludedIngredientIds']) && is_array($b['excludedIngredientIds'])) {
    $excluded = array_values(array_intersect(array_map('intval', $b['excludedIngredientIds']), $ids));
  } else {
    $excluded = array_map(fn($r) => (int)$r['id'], q('SELECT id FROM sft_recipe_ingredients WHERE recipe_id = ? AND is_optional = 1', 'i', [$recipeId]));
  }
  return [$mode, $value, round($factor, 4), $excluded];
}

// List amounts may be 0 ("don't need it", Senior Family rule) — hidden while shopping.
function itemQuantity($v): ?float {
  if ($v === null || $v === '') { return null; }
  $n = is_int($v) || is_float($v) ? (float)$v : parseQuantityText((string)$v);
  if ($n === null || $n < 0 || $n > 100000) { fail('Enter an amount like 2, 1.5 or 1 1/2', 400, 'quantity'); }
  return round($n, 4);
}

function itemUnit($v): ?string {
  $t = trim((string)($v ?? ''));
  if ($t === '') { return null; }
  if (mb_strlen($t) > 30) { fail('Unit is too long', 400, 'unit'); }
  $u = interpretUnit($t);
  return $u['known'] && $u['unit'] !== null && $u['prep'] === null && $u['package'] === null ? $u['unit'] : $t;
}

function itemStore(int $userId, int $listId, $v): ?int {
  if ($v === null || $v === '') { return null; }
  $id = (int)$v;
  foreach (storesFor($userId, $listId) as $s) { if ($s['id'] === $id) { return $id; } }
  fail('Choose a store from the list', 400, 'storeId');
  return null;
}

function listItemById(int $itemId): array {
  $item = q1('SELECT list_id FROM sft_shopping_items WHERE id = ?', 'i', [$itemId]);
  foreach (listItems((int)$item['list_id'])['items'] as $i) { if ($i['id'] === $itemId) { return $i; } }
  return [];
}

// Does the stored value still match what the editor started from?
function sameValue(string $field, $current, $base): bool {
  if ($field === 'quantity') {
    if ($current === null || $base === null || $base === '') { return ($current === null) === ($base === null || $base === ''); }
    return abs((float)$current - (float)$base) < 0.00005;
  }
  if ($field === 'storeId' || $field === 'assignedUserId') {
    return ($current === null ? null : (int)$current) === ($base === null || $base === '' ? null : (int)$base);
  }
  return trim((string)$current) === trim((string)$base);
}
