<?php
// Reading and writing the master recipe (docs/ARCHITECTURE.md §4, §7.3).
// Child rows are updated IN PLACE by id, because shopping lists point at ingredient ids.

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/units.php';

function nullableFloat($v): ?float { return $v === null ? null : (float)$v; }
function nullableInt($v): ?int { return $v === null ? null : (int)$v; }

/** The master recipe as one array — used by getRecipe and stored as each revision snapshot. */
function recipeMaster(int $recipeId): array {
  $r = q1('SELECT * FROM sft_recipes WHERE id = ?', 'i', [$recipeId]);
  if (!$r) { notFound('Recipe'); }
  $ingredients = q(
    'SELECT id, position, section, original_text, quantity, quantity_max, unit, unit_dimension, package_qualifier,
            item_name, ingredient_id, preparation, is_optional
       FROM sft_recipe_ingredients WHERE recipe_id = ? ORDER BY position, id', 'i', [$recipeId]);
  $methods = q('SELECT id, method_name FROM sft_recipe_methods WHERE recipe_id = ? ORDER BY sequence, id', 'i', [$recipeId]);
  $steps = q('SELECT id, method_id, step_number, instruction FROM sft_recipe_steps WHERE recipe_id = ?
              ORDER BY method_id IS NOT NULL, method_id, step_number, id', 'i', [$recipeId]);
  return [
    'id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'],
    'servings' => nullableFloat($r['servings']),
    'yieldQuantity' => nullableFloat($r['yield_quantity']), 'yieldUnit' => $r['yield_unit'],
    'prepMinutes' => nullableInt($r['prep_minutes']), 'cookMinutes' => nullableInt($r['cook_minutes']),
    'totalMinutes' => nullableInt($r['total_minutes']),
    'source' => $r['source_text'], 'generalNotes' => $r['general_notes'],
    'version' => (int)$r['version'], 'updatedAt' => $r['updated_at'],
    'ingredients' => array_map(fn($i) => [
      'id' => (int)$i['id'], 'section' => $i['section'], 'text' => $i['original_text'],
      'quantity' => nullableFloat($i['quantity']), 'quantityMax' => nullableFloat($i['quantity_max']),
      'unit' => $i['unit'], 'dimension' => $i['unit_dimension'], 'package' => $i['package_qualifier'],
      'name' => $i['item_name'], 'ingredientId' => nullableInt($i['ingredient_id']),
      'preparation' => $i['preparation'], 'optional' => (bool)$i['is_optional'],
    ], $ingredients),
    'methods' => array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['method_name']], $methods),
    'steps' => array_map(fn($s) => [
      'id' => (int)$s['id'], 'methodId' => nullableInt($s['method_id']), 'text' => $s['instruction'],
    ], $steps),
  ];
}

function writeRevision(int $recipeId, int $version, int $userId, string $note): void {
  exec_('INSERT INTO sft_recipe_revisions (recipe_id, version, snapshot, change_note, changed_by) VALUES (?, ?, ?, ?, ?)',
    'iissi', [$recipeId, $version, json_encode(recipeMaster($recipeId), JSON_UNESCAPED_UNICODE), mb_substr($note, 0, 255), $userId]);
}

// ---------------------------------------------------------------------------
// Validation of an incoming recipe (from the editor or a revision restore)
// ---------------------------------------------------------------------------

function cleanText($v, int $max, string $field, bool $required = false): ?string {
  $s = trim((string)($v ?? ''));
  if ($s === '') {
    if ($required) { fail('Please fill in this field', 400, $field); }
    return null;
  }
  if (mb_strlen($s) > $max) { fail("Too long (max $max characters)", 400, $field); }
  return $s;
}

function cleanNumber($v, string $field, float $max): ?float {
  if ($v === null || $v === '') { return null; }
  if (is_int($v) || is_float($v)) { $n = (float)$v; }
  else {
    $n = parseQuantityText((string)$v);
    if ($n === null) { fail('Enter a number, like 4 or 1 1/2', 400, $field); }
  }
  if ($n <= 0 || $n > $max) { fail('Enter a number greater than 0', 400, $field); }
  return round($n, 4);
}

function cleanMinutes($v, string $field): ?int {
  if ($v === null || $v === '') { return null; }
  if (!is_numeric($v) || (int)$v < 0 || (int)$v > 100000) { fail('Enter minutes as a whole number', 400, $field); }
  return (int)$v;
}

/** Validates editor input. Returns ['fields' => [...], 'ingredients' => [...], 'methods' => [...], 'steps' => [...]]. */
function cleanRecipeInput(array $in): array {
  $fields = [
    'title' => cleanText($in['title'] ?? null, 200, 'title', true),
    'description' => cleanText($in['description'] ?? null, 5000, 'description'),
    'servings' => cleanNumber($in['servings'] ?? null, 'servings', 10000),
    'yield_quantity' => cleanNumber($in['yieldQuantity'] ?? null, 'yieldQuantity', 10000),
    'yield_unit' => cleanText($in['yieldUnit'] ?? null, 50, 'yieldUnit'),
    'prep_minutes' => cleanMinutes($in['prepMinutes'] ?? null, 'prepMinutes'),
    'cook_minutes' => cleanMinutes($in['cookMinutes'] ?? null, 'cookMinutes'),
    'total_minutes' => cleanMinutes($in['totalMinutes'] ?? null, 'totalMinutes'),
    'source_text' => cleanText($in['source'] ?? null, 500, 'source'),
    'general_notes' => cleanText($in['generalNotes'] ?? null, 10000, 'generalNotes'),
  ];
  if ($fields['yield_unit'] !== null && $fields['yield_quantity'] === null) {
    fail('Enter how many (e.g. 1) for the batch size', 400, 'yieldQuantity');
  }

  $ingredients = [];
  foreach (array_values((array)($in['ingredients'] ?? [])) as $n => $row) {
    $row = (array)$row;
    $f = "ingredients.$n";
    $name = cleanText($row['name'] ?? null, 200, "$f.name");
    $qtyRaw = $row['quantity'] ?? null;
    $unitRaw = cleanText($row['unit'] ?? null, 30, "$f.unit");
    $prep = cleanText($row['preparation'] ?? null, 200, "$f.preparation");
    $section = cleanText($row['section'] ?? null, 100, "$f.section");
    if ($name === null && ($qtyRaw === null || $qtyRaw === '') && $unitRaw === null && $prep === null) { continue; } // blank row
    if ($name === null) { fail('Name the ingredient', 400, "$f.name"); }
    $qty = cleanNumber($qtyRaw, "$f.quantity", 100000);
    $qtyMax = cleanNumber($row['quantityMax'] ?? null, "$f.quantityMax", 100000);
    if ($qtyMax !== null && ($qty === null || $qtyMax <= $qty)) {
      fail('The upper amount must be more than the lower one', 400, "$f.quantityMax");
    }
    $unit = interpretUnit($unitRaw);
    $package = cleanText($row['package'] ?? null, 50, "$f.package") ?? $unit['package'];
    $dimension = $unit['dimension'];
    if ($unit['unit'] === null && $dimension === 'unknown' && $qty !== null) { $dimension = 'count'; }
    if ($unit['prep'] !== null) { $prep = $prep === null ? $unit['prep'] : $unit['prep'] . ', ' . $prep; }
    $ingredients[] = [
      'id' => isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : null,
      'section' => $section, 'quantity' => $qty, 'quantity_max' => $qtyMax,
      'unit' => $unit['unit'], 'unit_dimension' => $dimension, 'package_qualifier' => $package,
      'item_name' => $name, 'preparation' => $prep,
      'is_optional' => (!empty($row['optional']) || $unit['optional']) ? 1 : 0,
    ];
  }

  $methods = [];
  $methodKeys = [];
  foreach (array_values((array)($in['methods'] ?? [])) as $n => $row) {
    $row = (array)$row;
    $name = cleanText($row['name'] ?? null, 100, "methods.$n.name");
    if ($name === null) { continue; }
    $key = (string)($row['key'] ?? ('id' . ($row['id'] ?? $n)));
    $methodKeys[$key] = true;
    $methods[] = ['id' => isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : null, 'key' => $key, 'name' => $name];
  }

  $steps = [];
  foreach (array_values((array)($in['steps'] ?? [])) as $n => $row) {
    $row = (array)$row;
    $text = cleanText($row['text'] ?? null, 5000, "steps.$n.text");
    if ($text === null) { continue; }
    $methodKey = isset($row['methodKey']) && $row['methodKey'] !== '' && $row['methodKey'] !== null ? (string)$row['methodKey'] : null;
    if ($methodKey !== null && !isset($methodKeys[$methodKey])) { $methodKey = null; }
    $steps[] = ['id' => isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : null, 'methodKey' => $methodKey, 'text' => $text];
  }

  return ['fields' => $fields, 'ingredients' => $ingredients, 'methods' => $methods, 'steps' => $steps];
}

// How an ingredient reads: "1½ cups flour (sifted)".
function ingredientDisplay(array $i): string {
  $qty = $i['quantity'] !== null
    ? formatQuantity((float)$i['quantity']) . ($i['quantity_max'] !== null ? '–' . formatQuantity((float)$i['quantity_max']) : '')
    : '';
  $unit = unitLabel($i['unit'], $i['quantity'], $i['quantity_max']);
  if ($i['package_qualifier'] !== null && $i['unit_dimension'] === 'package') {
    $unit = trim($unit . ' (' . $i['package_qualifier'] . ')');
  }
  $text = trim(implode(' ', array_filter([$qty, $unit, $i['item_name']], fn($p) => $p !== '')));
  if ($i['preparation'] !== null) { $text .= ' (' . $i['preparation'] . ')'; }
  if ($i['is_optional']) { $text .= ' — optional'; }
  return mb_substr($text, 0, 500);
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/** Creates a recipe, makes $userId its co-owner and links it into $cookbookId. Returns the id. */
function createRecipe(int $userId, int $cookbookId, array $clean, string $note = 'Created'): int {
  $f = $clean['fields'];
  $id = exec_(
    'INSERT INTO sft_recipes (title, title_normalized, description, servings, yield_quantity, yield_unit, prep_minutes,
       cook_minutes, total_minutes, source_text, general_notes, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'sssddsiiissii',
    [$f['title'], mb_substr(normalizeTitle($f['title']), 0, 200), $f['description'], $f['servings'], $f['yield_quantity'],
     $f['yield_unit'], $f['prep_minutes'], $f['cook_minutes'], $f['total_minutes'], $f['source_text'], $f['general_notes'],
     $userId, $userId]
  );
  exec_("INSERT INTO sft_recipe_members (recipe_id, user_id, role, added_by) VALUES (?, ?, 'co_owner', ?)", 'iii', [$id, $userId, $userId]);
  exec_('INSERT INTO sft_cookbook_recipes (cookbook_id, recipe_id, added_by) VALUES (?, ?, ?)', 'iii', [$cookbookId, $id, $userId]);
  writeChildren($id, $clean);
  writeRevision($id, 1, $userId, $note);
  return $id;
}

/**
 * Saves over the master recipe if it is still at $baseVersion. Returns the new version, or
 * null if someone else saved first (the caller reports the conflict).
 */
function saveRecipe(int $userId, int $recipeId, int $baseVersion, array $clean, string $note): ?int {
  $f = $clean['fields'];
  $stmt = db()->prepare(
    'UPDATE sft_recipes SET title = ?, title_normalized = ?, description = ?, servings = ?, yield_quantity = ?, yield_unit = ?,
       prep_minutes = ?, cook_minutes = ?, total_minutes = ?, source_text = ?, general_notes = ?, updated_by = ?,
       version = version + 1
     WHERE id = ? AND version = ? AND deleted_at IS NULL');
  $titleNorm = mb_substr(normalizeTitle($f['title']), 0, 200);
  $stmt->bind_param('sssddsiiissiii', $f['title'], $titleNorm, $f['description'], $f['servings'], $f['yield_quantity'],
    $f['yield_unit'], $f['prep_minutes'], $f['cook_minutes'], $f['total_minutes'], $f['source_text'], $f['general_notes'],
    $userId, $recipeId, $baseVersion);
  $stmt->execute();
  $changed = $stmt->affected_rows;
  $stmt->close();
  if ($changed !== 1) { return null; }
  writeChildren($recipeId, $clean);
  $version = $baseVersion + 1;
  writeRevision($recipeId, $version, $userId, $note);
  return $version;
}

// Updates rows whose id belongs to this recipe, inserts the rest, deletes what's gone.
function writeChildren(int $recipeId, array $clean): void {
  // --- ingredients ---
  $existing = [];
  foreach (q('SELECT * FROM sft_recipe_ingredients WHERE recipe_id = ?', 'i', [$recipeId]) as $row) { $existing[(int)$row['id']] = $row; }
  $keep = [];
  foreach ($clean['ingredients'] as $pos => $ing) {
    $position = $pos + 1;
    $old = $ing['id'] !== null ? ($existing[$ing['id']] ?? null) : null;
    // Keep the line exactly as originally written (e.g. imported) unless its contents changed.
    $same = $old !== null
      && (string)$old['item_name'] === (string)$ing['item_name']
      && (string)$old['unit'] === (string)$ing['unit']
      && (string)$old['preparation'] === (string)$ing['preparation']
      && (string)$old['package_qualifier'] === (string)$ing['package_qualifier']
      && (int)$old['is_optional'] === (int)$ing['is_optional']
      && nullableFloat($old['quantity']) === $ing['quantity']
      && nullableFloat($old['quantity_max']) === $ing['quantity_max'];
    $text = $same ? $old['original_text'] : ingredientDisplay($ing);
    $ingredientId = $same ? nullableInt($old['ingredient_id']) : matchCatalogIngredientForRecipe($recipeId, $ing['item_name']);
    if ($old !== null) {
      exec_('UPDATE sft_recipe_ingredients SET position = ?, section = ?, original_text = ?, quantity = ?, quantity_max = ?,
               unit = ?, unit_dimension = ?, package_qualifier = ?, item_name = ?, ingredient_id = ?, preparation = ?, is_optional = ?
             WHERE id = ?',
        'issddssssisii', [$position, $ing['section'], $text, $ing['quantity'], $ing['quantity_max'], $ing['unit'],
          $ing['unit_dimension'], $ing['package_qualifier'], $ing['item_name'], $ingredientId, $ing['preparation'],
          $ing['is_optional'], $ing['id']]);
      $keep[$ing['id']] = true;
    } else {
      exec_('INSERT INTO sft_recipe_ingredients (recipe_id, position, section, original_text, quantity, quantity_max, unit,
               unit_dimension, package_qualifier, item_name, ingredient_id, preparation, is_optional)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iissddssssisi', [$recipeId, $position, $ing['section'], $text, $ing['quantity'], $ing['quantity_max'], $ing['unit'],
          $ing['unit_dimension'], $ing['package_qualifier'], $ing['item_name'], $ingredientId, $ing['preparation'], $ing['is_optional']]);
    }
  }
  foreach (array_keys($existing) as $id) {
    if (!isset($keep[$id])) { exec_('DELETE FROM sft_recipe_ingredients WHERE id = ?', 'i', [$id]); }
  }

  // --- methods (steps refer to them by the editor's key) ---
  $existingMethods = [];
  foreach (q('SELECT id FROM sft_recipe_methods WHERE recipe_id = ?', 'i', [$recipeId]) as $row) { $existingMethods[(int)$row['id']] = true; }
  $methodIdByKey = [];
  $keepMethods = [];
  foreach ($clean['methods'] as $seq => $m) {
    if ($m['id'] !== null && isset($existingMethods[$m['id']])) {
      exec_('UPDATE sft_recipe_methods SET method_name = ?, sequence = ? WHERE id = ?', 'sii', [$m['name'], $seq + 1, $m['id']]);
      $methodIdByKey[$m['key']] = $m['id'];
      $keepMethods[$m['id']] = true;
    } else {
      $methodIdByKey[$m['key']] = exec_('INSERT INTO sft_recipe_methods (recipe_id, method_name, sequence) VALUES (?, ?, ?)',
        'isi', [$recipeId, $m['name'], $seq + 1]);
    }
  }

  // --- steps (numbered within each method) ---
  $existingSteps = [];
  foreach (q('SELECT id FROM sft_recipe_steps WHERE recipe_id = ?', 'i', [$recipeId]) as $row) { $existingSteps[(int)$row['id']] = true; }
  $keepSteps = [];
  $numberByMethod = [];
  foreach ($clean['steps'] as $s) {
    $methodId = $s['methodKey'] !== null ? ($methodIdByKey[$s['methodKey']] ?? null) : null;
    $bucket = (string)($methodId ?? 0);
    $numberByMethod[$bucket] = ($numberByMethod[$bucket] ?? 0) + 1;
    if ($s['id'] !== null && isset($existingSteps[$s['id']])) {
      exec_('UPDATE sft_recipe_steps SET method_id = ?, step_number = ?, instruction = ? WHERE id = ?',
        'iisi', [$methodId, $numberByMethod[$bucket], $s['text'], $s['id']]);
      $keepSteps[$s['id']] = true;
    } else {
      exec_('INSERT INTO sft_recipe_steps (recipe_id, method_id, step_number, instruction) VALUES (?, ?, ?, ?)',
        'iiis', [$recipeId, $methodId, $numberByMethod[$bucket], $s['text']]);
    }
  }
  foreach (array_keys($existingSteps) as $id) {
    if (!isset($keepSteps[$id])) { exec_('DELETE FROM sft_recipe_steps WHERE id = ?', 'i', [$id]); }
  }
  foreach (array_keys($existingMethods) as $id) {
    if (!isset($keepMethods[$id])) { exec_('DELETE FROM sft_recipe_methods WHERE id = ?', 'i', [$id]); }
  }
}

// Catalog match for whoever created the recipe (exact alias only, ARCHITECTURE.md §7.2).
function matchCatalogIngredientForRecipe(int $recipeId, string $itemName): ?int {
  $row = q1(
    "SELECT a.ingredient_id FROM sft_ingredient_aliases a JOIN sft_ingredients i ON i.id = a.ingredient_id
      WHERE a.normalized_alias = ? AND a.status = 'approved' AND i.status = 'approved' AND a.scope = 'system' LIMIT 1",
    's', [normalizeName($itemName)]
  );
  return $row ? (int)$row['ingredient_id'] : null;
}

/**
 * A separate copy of recipe $sourceId in $cookbookId, co-owned by $userId (and nobody else).
 * Copies the recipe exactly as it reads now -- ingredient lines keep their original wording and
 * catalog links, methods and steps keep their order. Tags: built-in ones, the copier's own, and
 * the target cookbook's. Photos get their own files. Not copied: family notes, stars and other
 * personal data, sharing, and the history (the copy starts at version 1). Returns the new id.
 */
function copyRecipe(int $userId, int $sourceId, int $cookbookId, string $title): int {
  $src = q1('SELECT * FROM sft_recipes WHERE id = ? AND deleted_at IS NULL', 'i', [$sourceId]);
  if (!$src) { notFound('Recipe'); }
  $id = exec_(
    'INSERT INTO sft_recipes (title, title_normalized, description, servings, yield_quantity, yield_unit, prep_minutes,
       cook_minutes, total_minutes, source_text, general_notes, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'sssddsiiissii',
    [$title, mb_substr(normalizeTitle($title), 0, 200), $src['description'], nullableFloat($src['servings']),
     nullableFloat($src['yield_quantity']), $src['yield_unit'], nullableInt($src['prep_minutes']), nullableInt($src['cook_minutes']),
     nullableInt($src['total_minutes']), $src['source_text'], $src['general_notes'], $userId, $userId]
  );
  exec_("INSERT INTO sft_recipe_members (recipe_id, user_id, role, added_by) VALUES (?, ?, 'co_owner', ?)", 'iii', [$id, $userId, $userId]);
  exec_('INSERT INTO sft_cookbook_recipes (cookbook_id, recipe_id, added_by) VALUES (?, ?, ?)', 'iii', [$cookbookId, $id, $userId]);

  exec_('INSERT INTO sft_recipe_ingredients (recipe_id, position, section, original_text, quantity, quantity_max, unit,
           unit_dimension, package_qualifier, item_name, ingredient_id, preparation, is_optional)
         SELECT ?, position, section, original_text, quantity, quantity_max, unit,
           unit_dimension, package_qualifier, item_name, ingredient_id, preparation, is_optional
           FROM sft_recipe_ingredients WHERE recipe_id = ? ORDER BY position, id', 'ii', [$id, $sourceId]);
  $methodMap = [];
  foreach (q('SELECT id, method_name, sequence FROM sft_recipe_methods WHERE recipe_id = ? ORDER BY sequence, id', 'i', [$sourceId]) as $m) {
    $methodMap[(int)$m['id']] = exec_('INSERT INTO sft_recipe_methods (recipe_id, method_name, sequence) VALUES (?, ?, ?)',
      'isi', [$id, $m['method_name'], (int)$m['sequence']]);
  }
  foreach (q('SELECT method_id, step_number, instruction FROM sft_recipe_steps WHERE recipe_id = ? ORDER BY id', 'i', [$sourceId]) as $s) {
    $methodId = $s['method_id'] !== null ? ($methodMap[(int)$s['method_id']] ?? null) : null;
    exec_('INSERT INTO sft_recipe_steps (recipe_id, method_id, step_number, instruction) VALUES (?, ?, ?, ?)',
      'iiis', [$id, $methodId, (int)$s['step_number'], $s['instruction']]);
  }

  exec_("INSERT INTO sft_recipe_categories (recipe_id, category_id, added_by)
         SELECT ?, rc.category_id, ? FROM sft_recipe_categories rc JOIN sft_categories c ON c.id = rc.category_id
          WHERE rc.recipe_id = ? AND (c.scope = 'system' OR (c.scope = 'user' AND c.owner_user_id = ?)
                                      OR (c.scope = 'cookbook' AND c.cookbook_id = ?))",
    'iiiii', [$id, $userId, $sourceId, $userId, $cookbookId]);

  copyRecipePhotos($sourceId, $id);
  writeRevision($id, 1, $userId, mb_substr('Copied from "' . $src['title'] . '"', 0, 255));
  return $id;
}

/** Turns a stored snapshot back into editor input, keeping row ids so links survive. */
function snapshotToInput(array $snap): array {
  $methods = array_map(fn($m) => ['id' => $m['id'] ?? null, 'key' => 'm' . ($m['id'] ?? ''), 'name' => $m['name']], $snap['methods'] ?? []);
  return [
    'title' => $snap['title'] ?? '', 'description' => $snap['description'] ?? null, 'servings' => $snap['servings'] ?? null,
    'yieldQuantity' => $snap['yieldQuantity'] ?? null, 'yieldUnit' => $snap['yieldUnit'] ?? null,
    'prepMinutes' => $snap['prepMinutes'] ?? null, 'cookMinutes' => $snap['cookMinutes'] ?? null,
    'totalMinutes' => $snap['totalMinutes'] ?? null, 'source' => $snap['source'] ?? null,
    'generalNotes' => $snap['generalNotes'] ?? null,
    'ingredients' => array_map(fn($i) => [
      'id' => $i['id'] ?? null, 'section' => $i['section'] ?? null, 'quantity' => $i['quantity'] ?? null,
      'quantityMax' => $i['quantityMax'] ?? null, 'unit' => $i['unit'] ?? null, 'package' => $i['package'] ?? null,
      'name' => $i['name'] ?? '', 'preparation' => $i['preparation'] ?? null, 'optional' => $i['optional'] ?? false,
    ], $snap['ingredients'] ?? []),
    'methods' => $methods,
    'steps' => array_map(fn($s) => [
      'id' => $s['id'] ?? null, 'methodKey' => isset($s['methodId']) && $s['methodId'] !== null ? 'm' . $s['methodId'] : null,
      'text' => $s['text'] ?? '',
    ], $snap['steps'] ?? []),
  ];
}
