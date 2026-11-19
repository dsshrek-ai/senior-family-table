<?php
// One-time import of the Senior Family Cookbook (collection 'senior-family' only — never
// Living Lean). Plan and decisions: docs/IMPORT-SENIOR-FAMILY.md.
//
// mapSeniorFamilyRecipe() is pure (no database) so it can be checked against an export of the
// live data; importSeniorFamily() reads cookbook_recipes and writes sft_ rows in one transaction.

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/units.php';

const SFC_SOURCE_COLLECTION = 'senior-family';
const SFC_SOURCE_PREFIX = 'senior-family-cookbook:';
const SFC_PARSER_VERSION = 'sfc-migrate-1';
const SFC_COOKBOOK_TITLE = 'Senior Family Cookbook';
const SFC_COOKBOOK_DESCRIPTION = 'The family recipes, imported from the original Senior Family Cookbook app.';

// Confirmed by the owner on 2026-11-19.
const SFC_SKIP_TITLES = ['Rice Pilaf (DUP 2021)'];
// A spreadsheet turned "1/2" into the date 2026-01-02 on these lines; owner confirmed ½ Tbsp.
const SFC_QUANTITY_FIXES = [
  'refried beans with lard (instant pot)|chili powder' => 0.5,
  'refried beans with lard (instant pot)|cumin' => 0.5,
];

// Old tag (normalized) → built-in system category/tag (normalized name in sft_categories).
const SFC_SYSTEM_TAGS = [
  'main dish' => 'main dishes', 'main course' => 'main dishes', 'main dishes' => 'main dishes',
  'side' => 'side dishes', 'side dish' => 'side dishes', 'side dishes' => 'side dishes',
  'soup' => 'soup', 'salad' => 'salad', 'breakfast' => 'breakfast', 'appetizer' => 'appetizer',
  'dessert' => 'dessert', 'instant pot' => 'instant pot', 'crock pot' => 'slow cooker',
  'slow cooker' => 'slow cooker', 'stovetop' => 'stovetop', 'stove top' => 'stovetop',
  'oven' => 'oven', 'air fryer' => 'air fryer',
  'freezer-friendly' => 'freezer friendly', 'freezer friendly' => 'freezer friendly',
  'high-protein' => 'high protein', 'high protein' => 'high protein', 'gluten free' => 'gluten free',
  'family favorite' => 'family favorite',
];

// Near-duplicate old tags merged into one cookbook tag (normalized → display name).
const SFC_TAG_MERGES = [
  'make ahead' => 'Make-Ahead', 'make-ahead' => 'Make-Ahead',
  'crowd size' => 'Crowd Size', 'crowd sized' => 'Crowd Size',
  'bean' => 'Beans', 'beans' => 'Beans',
  'potato' => 'Potatoes', 'potatoes' => 'Potatoes',
  'cupcake' => 'Cupcakes', 'cupcakes' => 'Cupcakes',
];

/**
 * Maps one Senior Family recipe (the shape the old API's shapeRecipe() returns) to
 * The Senior Family Table's structure. Never touches the database.
 */
function mapSeniorFamilyRecipe(array $src): array {
  $title = trim((string)($src['name'] ?? ''));
  $out = ['sourceId' => (int)($src['id'] ?? 0), 'title' => $title, 'skip' => null, 'flags' => []];
  if (($src['collection'] ?? SFC_SOURCE_COLLECTION) !== SFC_SOURCE_COLLECTION) {
    $out['skip'] = 'not a Senior Family recipe (' . ($src['collection'] ?? '?') . ')';
    return $out;
  }
  if (in_array($title, SFC_SKIP_TITLES, true)) {
    $out['skip'] = 'duplicate — skipped by owner decision';
    return $out;
  }
  if ($title === '') {
    $out['skip'] = 'no title';
    return $out;
  }

  // --- recipe fields ---
  $notesParts = [];
  if (trim((string)($src['notes'] ?? '')) !== '') { $notesParts[] = trim($src['notes']); }
  if (trim((string)($src['nutrition'] ?? '')) !== '') { $notesParts[] = 'Nutrition: ' . trim($src['nutrition']); }
  $times = [];
  foreach (['prepTime' => 'prep', 'cookTime' => 'cook', 'totalTime' => 'total'] as $key => $name) {
    $raw = trim((string)($src[$key] ?? ''));
    $times[$name] = null;
    if ($raw === '') { continue; }
    $times[$name] = parseMinutes($raw);
    if ($times[$name] === null) { $notesParts[] = ucfirst($name) . ' time: ' . $raw; }
  }
  $servings = isset($src['baseServings']) && $src['baseServings'] !== null && (float)$src['baseServings'] > 0
    ? (float)$src['baseServings'] : null;

  $out['recipe'] = [
    'title' => mb_substr($title, 0, 200),
    'description' => trim((string)($src['story'] ?? '')) ?: null,
    'servings' => $servings,
    'prep_minutes' => $times['prep'],
    'cook_minutes' => $times['cook'],
    'total_minutes' => $times['total'],
    'general_notes' => $notesParts ? implode("\n\n", $notesParts) : null,
    'source_text' => 'Senior Family Cookbook',
  ];

  // --- ingredients ---
  $out['ingredients'] = [];
  $position = 0;
  foreach (($src['ingredients'] ?? []) as $ing) {
    $position++;
    $out['ingredients'][] = mapSeniorFamilyIngredient($title, $position, (array)$ing, $out['flags']);
  }

  // --- steps ---
  $out['steps'] = [];
  foreach (($src['steps'] ?? []) as $step) {
    $text = trim((string)$step);
    if ($text !== '') { $out['steps'][] = $text; }
  }

  // --- tags (old API returns "A, B, C"; category is a one-off extra tag) ---
  $rawTags = is_array($src['tags'] ?? null) ? $src['tags'] : explode(',', (string)($src['tags'] ?? ''));
  if (trim((string)($src['category'] ?? '')) !== '') { $rawTags[] = $src['category']; }
  $out['tags'] = [];
  $seen = [];
  foreach ($rawTags as $raw) {
    $name = trim((string)$raw);
    if ($name === '') { continue; }
    $norm = normalizeName($name);
    if (isset(SFC_SYSTEM_TAGS[$norm])) {
      $tag = ['scope' => 'system', 'normalized' => SFC_SYSTEM_TAGS[$norm], 'name' => null];
    } else {
      $display = SFC_TAG_MERGES[$norm] ?? $name;
      $tag = ['scope' => 'cookbook', 'normalized' => normalizeName($display), 'name' => $display];
    }
    $key = $tag['scope'] . ':' . $tag['normalized'];
    if (isset($seen[$key])) { continue; }
    $seen[$key] = true;
    $out['tags'][] = $tag;
  }

  return $out;
}

function mapSeniorFamilyIngredient(string $recipeTitle, int $position, array $ing, array &$flags): array {
  $name = trim((string)($ing['ingredient'] ?? ''));
  $rawUnit = trim((string)($ing['unit'] ?? ''));
  $rawNotes = trim((string)($ing['notes'] ?? ''));
  $rawQty = $ing['quantity'] ?? null;

  // Unit and note swapped ("4 · the · tsp" → "4 tsp").
  if (normalizeName($rawUnit) === 'the' && interpretUnit($rawNotes)['dimension'] !== 'unknown') {
    $rawUnit = $rawNotes;
    $rawNotes = '';
    $flags[] = "#$position $name: unit and note were swapped — fixed to \"$rawUnit\"";
  }

  // Amount typed into the unit column with the quantity left blank ("1-2 drops").
  if (($rawQty === null || $rawQty === '')
      && preg_match('/^([\d\/.½¼¾⅓⅔⅛]+(?:\s*(?:-|–|to)\s*[\d\/.½¼¾⅓⅔⅛]+)?)\s+(.+)$/u', $rawUnit, $m)
      && interpretQuantity($m[1])['ok']) {
    $rawQty = $m[1];
    $rawUnit = $m[2];
  }

  $fixKey = normalizeName($recipeTitle) . '|' . normalizeName($name);
  if (isset(SFC_QUANTITY_FIXES[$fixKey]) && !is_int($rawQty) && !is_float($rawQty)
      && interpretQuantity($rawQty)['ok'] === false) {
    $qty = ['quantity' => SFC_QUANTITY_FIXES[$fixKey], 'max' => null, 'ok' => true];
    $flags[] = "#$position $name: quantity \"$rawQty\" corrected to " . formatQuantity($qty['quantity']);
  } else {
    $qty = interpretQuantity($rawQty);
    if (!$qty['ok']) {
      $flags[] = "#$position $name: unreadable quantity \"$rawQty\" — imported with no quantity";
    }
  }

  $unit = interpretUnit($rawUnit);
  if (!$unit['known']) {
    $flags[] = "#$position $name: unrecognized unit \"$rawUnit\" — kept as written";
  }
  // "3 Granny Smith apples" — a quantity with no unit is a count.
  if ($unit['unit'] === null && $unit['dimension'] === 'unknown' && $qty['quantity'] !== null) {
    $unit['dimension'] = 'count';
  }

  $prepParts = [];
  if ($unit['prep'] !== null) { $prepParts[] = $unit['prep']; }
  if ($rawNotes !== '') { $prepParts[] = $rawNotes; }

  // "1 cup flour (sifted)" — how the line read in the old app.
  $display = trim(implode(' ', array_filter([
    $qty['quantity'] !== null
      ? formatQuantity($qty['quantity']) . ($qty['max'] !== null ? '–' . formatQuantity($qty['max']) : '')
      : '',
    $rawUnit,
    $name,
  ], fn($p) => $p !== '')));
  if ($rawNotes !== '') { $display .= ' (' . $rawNotes . ')'; }

  return [
    'position' => $position,
    'original_text' => mb_substr($display !== '' ? $display : '(blank line)', 0, 500),
    'quantity' => $qty['quantity'],
    'quantity_max' => $qty['max'],
    'unit' => $unit['unit'],
    'unit_dimension' => $unit['dimension'],
    'package_qualifier' => $unit['package'] !== null ? mb_substr($unit['package'], 0, 50) : null,
    'item_name' => mb_substr($name !== '' ? $name : '(unnamed)', 0, 200),
    'preparation' => $prepParts ? mb_substr(implode(', ', $prepParts), 0, 200) : null,
    'is_optional' => ($unit['optional'] || normalizeName($rawNotes) === 'optional') ? 1 : 0,
  ];
}

// ---------------------------------------------------------------------------
// Database side
// ---------------------------------------------------------------------------

/** Reads the source rows in the same shape the old API returns. */
function loadSeniorFamilySource(): array {
  $rows = q(
    "SELECT r.*, (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                    FROM cookbook_recipe_tags rt JOIN cookbook_tags t ON t.id = rt.tag_id
                   WHERE rt.recipe_id = r.id) AS tag_list
       FROM cookbook_recipes r WHERE r.collection = ? ORDER BY r.name",
    's', [SFC_SOURCE_COLLECTION]
  );
  return array_map(fn($row) => [
    'id' => (int)$row['id'],
    'name' => $row['name'],
    'collection' => $row['collection'],
    'category' => $row['category'],
    'baseServings' => $row['base_servings'] !== null ? (int)$row['base_servings'] : null,
    'prepTime' => $row['prep_time'],
    'cookTime' => $row['cook_time'],
    'totalTime' => $row['total_time'],
    'tested' => (bool)$row['tested'],
    'story' => $row['story'],
    'nutrition' => $row['nutrition'],
    'notes' => $row['notes'],
    'ingredients' => json_decode($row['ingredients'], true) ?? [],
    'steps' => json_decode($row['steps'], true) ?? [],
    'tags' => (string)($row['tag_list'] ?? ''),
  ], $rows);
}

/**
 * Imports every not-yet-imported Senior Family recipe for $userId.
 * Dry run does the full import inside the transaction and then rolls it back, so it
 * checks every constraint without leaving anything behind.
 */
function importSeniorFamily(int $userId, bool $dryRun): array {
  $report = ['dryRun' => $dryRun, 'source' => 0, 'imported' => 0, 'alreadyImported' => 0,
    'skipped' => [], 'flagged' => [], 'ingredients' => 0, 'steps' => 0, 'cookbookId' => null];

  $source = loadSeniorFamilySource();
  $report['source'] = count($source);

  db()->begin_transaction();
  try {
    $cookbookId = findOrCreateImportCookbook($userId);
    $report['cookbookId'] = $cookbookId;
    $systemTags = [];
    foreach (q("SELECT id, normalized_name FROM sft_categories WHERE scope = 'system'") as $row) {
      $systemTags[$row['normalized_name']] = (int)$row['id'];
    }

    foreach ($source as $src) {
      $mapped = mapSeniorFamilyRecipe($src);
      if ($mapped['skip'] !== null) {
        $report['skipped'][] = $mapped['title'] . ' — ' . $mapped['skip'];
        continue;
      }
      $sourceRef = SFC_SOURCE_PREFIX . $mapped['sourceId'];
      if (q1('SELECT 1 FROM sft_recipe_imports WHERE source_ref = ?', 's', [$sourceRef])) {
        $report['alreadyImported']++;
        continue;
      }
      writeImportedRecipe($userId, $cookbookId, $src, $mapped, $sourceRef, $systemTags);
      $report['imported']++;
      $report['ingredients'] += count($mapped['ingredients']);
      $report['steps'] += count($mapped['steps']);
      foreach ($mapped['flags'] as $flag) { $report['flagged'][] = $mapped['title'] . ': ' . $flag; }
    }

    if ($dryRun) {
      db()->rollback();
      $report['cookbookId'] = null;
    } else {
      audit($userId, 'cookbook', $cookbookId, 'imported', 'Senior Family Cookbook: ' . $report['imported'] . ' recipes');
      db()->commit();
    }
  } catch (Throwable $e) {
    db()->rollback();
    throw $e;
  }
  return $report;
}

function findOrCreateImportCookbook(int $userId): int {
  $row = q1(
    'SELECT c.id FROM sft_cookbooks c JOIN sft_cookbook_members m ON m.cookbook_id = c.id AND m.user_id = ? AND m.role = \'owner\'
     WHERE c.title = ? AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1',
    'is', [$userId, SFC_COOKBOOK_TITLE]
  );
  if ($row) { return (int)$row['id']; }
  $id = exec_('INSERT INTO sft_cookbooks (title, description, created_by) VALUES (?, ?, ?)',
    'ssi', [SFC_COOKBOOK_TITLE, SFC_COOKBOOK_DESCRIPTION, $userId]);
  exec_('INSERT INTO sft_cookbook_members (cookbook_id, user_id, role, added_by) VALUES (?, ?, \'owner\', ?)',
    'iii', [$id, $userId, $userId]);
  return $id;
}

function cookbookTagId(int $cookbookId, string $name, string $normalized): int {
  $row = q1("SELECT id FROM sft_categories WHERE scope = 'cookbook' AND cookbook_id = ? AND normalized_name = ?",
    'is', [$cookbookId, $normalized]);
  if ($row) { return (int)$row['id']; }
  return exec_("INSERT INTO sft_categories (name, normalized_name, kind, scope, cookbook_id) VALUES (?, ?, 'descriptor', 'cookbook', ?)",
    'ssi', [mb_substr($name, 0, 80), mb_substr($normalized, 0, 80), $cookbookId]);
}

// Exact normalized alias match only — no fuzzy matching (ARCHITECTURE.md §7.2).
function matchCatalogIngredient(int $userId, string $itemName): ?int {
  $row = q1(
    "SELECT a.ingredient_id FROM sft_ingredient_aliases a JOIN sft_ingredients i ON i.id = a.ingredient_id
      WHERE a.normalized_alias = ? AND a.status = 'approved' AND i.status = 'approved'
        AND (a.scope = 'system' OR (a.scope = 'user' AND a.owner_user_id = ?))
      ORDER BY a.scope = 'user' DESC LIMIT 1",
    'si', [normalizeName($itemName), $userId]
  );
  return $row ? (int)$row['ingredient_id'] : null;
}

function writeImportedRecipe(int $userId, int $cookbookId, array $src, array $mapped, string $sourceRef, array $systemTags): void {
  $r = $mapped['recipe'];
  $recipeId = exec_(
    'INSERT INTO sft_recipes (title, title_normalized, description, servings, prep_minutes, cook_minutes,
       total_minutes, source_text, general_notes, created_by, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'sssdiiissii',
    [$r['title'], mb_substr(normalizeTitle($r['title']), 0, 200), $r['description'], $r['servings'],
     $r['prep_minutes'], $r['cook_minutes'], $r['total_minutes'], $r['source_text'], $r['general_notes'],
     $userId, $userId]
  );
  exec_("INSERT INTO sft_recipe_members (recipe_id, user_id, role, added_by) VALUES (?, ?, 'co_owner', ?)",
    'iii', [$recipeId, $userId, $userId]);
  exec_('INSERT INTO sft_cookbook_recipes (cookbook_id, recipe_id, display_order, added_by) VALUES (?, ?, 0, ?)',
    'iii', [$cookbookId, $recipeId, $userId]);

  $snapshotIngredients = [];
  foreach ($mapped['ingredients'] as $ing) {
    $ingredientId = matchCatalogIngredient($userId, $ing['item_name']);
    exec_(
      'INSERT INTO sft_recipe_ingredients (recipe_id, position, original_text, quantity, quantity_max, unit,
         unit_dimension, package_qualifier, item_name, ingredient_id, preparation, is_optional)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      'iisddssssisi',
      [$recipeId, $ing['position'], $ing['original_text'], $ing['quantity'], $ing['quantity_max'], $ing['unit'],
       $ing['unit_dimension'], $ing['package_qualifier'], $ing['item_name'], $ingredientId, $ing['preparation'],
       $ing['is_optional']]
    );
    $snapshotIngredients[] = $ing + ['ingredient_id' => $ingredientId];
  }

  $number = 0;
  foreach ($mapped['steps'] as $text) {
    $number++;
    exec_('INSERT INTO sft_recipe_steps (recipe_id, method_id, step_number, instruction) VALUES (?, NULL, ?, ?)',
      'iis', [$recipeId, $number, $text]);
  }

  $tagNames = [];
  foreach ($mapped['tags'] as $tag) {
    if ($tag['scope'] === 'system') {
      if (!isset($systemTags[$tag['normalized']])) { continue; }
      $categoryId = $systemTags[$tag['normalized']];
    } else {
      $categoryId = cookbookTagId($cookbookId, $tag['name'], $tag['normalized']);
    }
    exec_('INSERT IGNORE INTO sft_recipe_categories (recipe_id, category_id, added_by) VALUES (?, ?, ?)',
      'iii', [$recipeId, $categoryId, $userId]);
    $tagNames[] = $tag['name'] ?? $tag['normalized'];
  }

  $snapshot = json_encode([
    'recipe' => $r, 'ingredients' => $snapshotIngredients, 'methods' => [],
    'steps' => $mapped['steps'], 'tags' => $tagNames,
  ], JSON_UNESCAPED_UNICODE);
  exec_("INSERT INTO sft_recipe_revisions (recipe_id, version, snapshot, change_note, changed_by) VALUES (?, 1, ?, ?, ?)",
    'issi', [$recipeId, $snapshot, 'Imported from Senior Family Cookbook #' . $mapped['sourceId'], $userId]);

  exec_(
    "INSERT INTO sft_recipe_imports (recipe_id, raw_text, parser_version, parsed_json, corrections, outcome,
       source_ref, needs_review, imported_by)
     VALUES (?, ?, ?, ?, NULL, 'created', ?, ?, ?)",
    'isssssi',
    [$recipeId, json_encode($src, JSON_UNESCAPED_UNICODE), SFC_PARSER_VERSION, $snapshot, $sourceRef,
     $mapped['flags'] ? json_encode($mapped['flags'], JSON_UNESCAPED_UNICODE) : null, $userId]
  );
}
