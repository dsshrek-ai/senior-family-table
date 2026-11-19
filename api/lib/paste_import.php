<?php
// Paste-a-recipe import: duplicate suggestions and import records (ARCHITECTURE.md §7.1).

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/shopping.php';   // itemIdentityName()

const IMPORT_MAX_CHARS = 100000;
const TITLE_STOP_WORDS = ['and', 'or', 'the', 'a', 'an', 'of', 'with', 'for', 'to', 'in', 'recipe'];

function importRawText(array $b): string {
  $raw = (string)($b['rawText'] ?? '');
  if (trim($raw) === '') { fail('The pasted text is missing', 400, 'rawText'); }
  if (mb_strlen($raw) > IMPORT_MAX_CHARS) { fail('That\'s too much text for one recipe', 400, 'rawText'); }
  return $raw;
}

/** Keeps the pasted text, the reader's output, and (for new recipes) what was finally saved. */
function recordImport(int $userId, int $recipeId, string $raw, array $b, string $outcome, ?array $clean): void {
  $version = mb_substr((string)($b['parserVersion'] ?? 'unknown'), 0, 20);
  $parsed = isset($b['parsed']) ? json_encode($b['parsed'], JSON_UNESCAPED_UNICODE) : null;
  $flags = !empty($b['flags']) && is_array($b['flags']) ? json_encode(array_values($b['flags']), JSON_UNESCAPED_UNICODE) : null;
  exec_('INSERT INTO sft_recipe_imports (recipe_id, raw_text, parser_version, parsed_json, corrections, outcome, needs_review, imported_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)', 'issssssi',
    [$recipeId, $raw, $version, $parsed, $clean !== null ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null, $outcome, $flags, $userId]);
}

function titleWordSet(string $title): array {
  $words = array_filter(explode(' ', normalizeTitle($title)), fn($w) => $w !== '' && !in_array($w, TITLE_STOP_WORDS, true));
  return array_values(array_unique($words));
}

/**
 * Recipes the user can see that look like the one being imported: the same or a similar title,
 * or most of the same ingredients. Best matches first, at most five.
 */
function similarRecipes(int $userId, string $title, array $ingredientNames, ?int $cookbookId): array {
  $want = titleWordSet($title);
  $wantNorm = normalizeTitle($title);
  $wantIng = array_values(array_unique(array_filter(array_map(fn($n) => itemIdentityName((string)$n), $ingredientNames))));
  $recipes = q('SELECT r.id, r.title, r.title_normalized FROM sft_recipes r WHERE ' . VISIBLE_RECIPE_SQL, 'ii', [$userId, $userId]);
  if (!$recipes) { return []; }
  $ingByRecipe = [];
  if (count($wantIng) >= 3) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $recipes));
    foreach (q("SELECT recipe_id, item_name FROM sft_recipe_ingredients WHERE recipe_id IN ($ids)") as $row) {
      $ingByRecipe[(int)$row['recipe_id']][itemIdentityName($row['item_name'])] = true;
    }
  }
  $inBook = [];
  if ($cookbookId !== null) {
    foreach (q('SELECT recipe_id FROM sft_cookbook_recipes WHERE cookbook_id = ?', 'i', [$cookbookId]) as $row) { $inBook[(int)$row['recipe_id']] = true; }
  }
  $matches = [];
  foreach ($recipes as $r) {
    $id = (int)$r['id'];
    $score = 0.0;
    $reasons = [];
    if ($wantNorm !== '' && $r['title_normalized'] === $wantNorm) {
      $score = 1.0;
      $reasons[] = 'Same title';
    } elseif ($want) {
      $have = titleWordSet($r['title']);
      $shared = count(array_intersect($want, $have));
      $union = count(array_unique(array_merge($want, $have)));
      if ($shared >= 2 && $union > 0 && $shared / $union >= 0.6) {
        $score = max($score, 0.6 + 0.3 * $shared / $union);
        $reasons[] = 'Similar title';
      }
    }
    if (isset($ingByRecipe[$id])) {
      $shared = count(array_intersect($wantIng, array_keys($ingByRecipe[$id])));
      $bigger = max(count($wantIng), count($ingByRecipe[$id]));
      if ($shared >= 4 && $shared / $bigger >= 0.7) {
        $score = max($score, 0.5 + 0.4 * $shared / $bigger);
        $reasons[] = "$shared of $bigger ingredients match";
      }
    }
    if ($score > 0) {
      $matches[] = ['id' => $id, 'title' => $r['title'], 'score' => round($score, 3), 'reasons' => $reasons, 'inThisCookbook' => isset($inBook[$id])];
    }
  }
  usort($matches, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']));
  return array_slice($matches, 0, 5);
}
