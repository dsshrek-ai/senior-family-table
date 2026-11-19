<?php
// Import the Senior Family Cookbook into The Senior Family Table.
//
//   php import-senior-family.php --user=you@example.com --dry-run   full run, then rolled back
//   php import-senior-family.php --user=you@example.com             the real import
//   php import-senior-family.php --from-json=export.json            mapping check only, no database
//
// --from-json takes the old API's output (getAllRecipes&collection=senior-family) and prints
// what would be imported and every flagged line, without connecting to anything.
// See docs/IMPORT-SENIOR-FAMILY.md. Command line only (tools/.htaccess denies web access).

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit;
}

require_once __DIR__ . '/../lib/import_senior_family.php';

$opts = getopt('', ['user:', 'dry-run', 'from-json:']);

if (isset($opts['from-json'])) {
  $data = json_decode((string)file_get_contents($opts['from-json']), true);
  $recipes = $data['recipes'] ?? $data;
  $imported = 0;
  $ingredients = 0;
  $steps = 0;
  $dimensions = [];
  $systemTags = [];
  $cookbookTags = [];
  foreach ($recipes as $src) {
    $m = mapSeniorFamilyRecipe($src);
    if ($m['skip'] !== null) {
      echo "SKIP  {$m['title']} — {$m['skip']}\n";
      continue;
    }
    $imported++;
    $ingredients += count($m['ingredients']);
    $steps += count($m['steps']);
    foreach ($m['ingredients'] as $ing) {
      $dimensions[$ing['unit_dimension']] = ($dimensions[$ing['unit_dimension']] ?? 0) + 1;
    }
    foreach ($m['tags'] as $t) {
      if ($t['scope'] === 'system') { $systemTags[$t['normalized']] = ($systemTags[$t['normalized']] ?? 0) + 1; }
      else { $cookbookTags[$t['name']] = true; }
    }
    foreach ($m['flags'] as $f) { echo "FLAG  {$m['title']}: $f\n"; }
  }
  echo "\nRecipes to import: $imported of " . count($recipes) . "\n";
  echo "Ingredient lines:  $ingredients\nSteps:             $steps\n";
  echo 'Unit dimensions:   ' . json_encode($dimensions) . "\n";
  echo 'Built-in tags:     ' . json_encode($systemTags) . "\n";
  echo 'Cookbook tags:     ' . count($cookbookTags) . "\n";
  exit(0);
}

if (!isset($opts['user'])) {
  fwrite(STDERR, "Usage: php import-senior-family.php --user=<hub username> [--dry-run]\n");
  exit(2);
}

require_once __DIR__ . '/../config.php';

$user = userByUsername($opts['user']);
if (!$user) {
  fwrite(STDERR, "No Hub user named {$opts['user']}\n");
  exit(1);
}

$report = importSeniorFamily((int)$user['id'], isset($opts['dry-run']));
foreach ($report['skipped'] as $s) { echo "SKIP  $s\n"; }
foreach ($report['flagged'] as $f) { echo "FLAG  $f\n"; }
echo ($report['dryRun'] ? "\nDRY RUN — nothing was saved.\n" : "\nImport committed.\n");
echo "Source recipes:    {$report['source']}\n";
echo "Imported:          {$report['imported']}\n";
echo "Already imported:  {$report['alreadyImported']}\n";
echo "Ingredient lines:  {$report['ingredients']}\nSteps:             {$report['steps']}\n";
if ($report['cookbookId'] !== null) { echo "Cookbook id:       {$report['cookbookId']}\n"; }
