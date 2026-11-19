<?php
// Units and quantities. The synonym table is ported from the Senior Family Cookbook
// (app.js UNIT_SYNONYMS) and extended with the values found in its live data.
// Used by the Senior Family import now, and by recipe saving and shopping lists later.

// Exact-case entries first: "T" is tablespoon, "t" is teaspoon.
const UNIT_EXACT_CASE = ['T' => 'tbsp', 't' => 'tsp'];

const UNIT_SYNONYMS = [
  // volume
  'tsp' => 'tsp', 'tsps' => 'tsp', 'teaspoon' => 'tsp', 'teaspoons' => 'tsp',
  'tbsp' => 'tbsp', 'tbsps' => 'tbsp', 'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp', 'tbs' => 'tbsp', 'tbl' => 'tbsp',
  'fl oz' => 'fl oz', 'fluid oz' => 'fl oz', 'fluid ounce' => 'fl oz', 'fluid ounces' => 'fl oz',
  'cup' => 'cup', 'cups' => 'cup', 'c' => 'cup',
  'pint' => 'pint', 'pints' => 'pint', 'pt' => 'pint',
  'quart' => 'quart', 'quarts' => 'quart', 'qt' => 'quart',
  'gallon' => 'gallon', 'gallons' => 'gallon', 'gal' => 'gallon',
  'ml' => 'ml', 'milliliter' => 'ml', 'milliliters' => 'ml',
  'l' => 'liter', 'liter' => 'liter', 'liters' => 'liter', 'litre' => 'liter',
  // mass ("oz" is treated as weight, as the Senior Family Cookbook does)
  'oz' => 'oz', 'ounce' => 'oz', 'ounces' => 'oz',
  'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
  'g' => 'g', 'gram' => 'g', 'grams' => 'g',
  'kg' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
];

const UNIT_DIMENSION = [
  'tsp' => 'volume', 'tbsp' => 'volume', 'fl oz' => 'volume', 'cup' => 'volume', 'pint' => 'volume',
  'quart' => 'volume', 'gallon' => 'volume', 'ml' => 'volume', 'liter' => 'volume',
  'oz' => 'mass', 'lb' => 'mass', 'g' => 'mass', 'kg' => 'mass',
];

// Containers: keep the size ("15 oz") separately so only same-size packages combine.
const PACKAGE_UNITS = [
  'can' => 'can', 'cans' => 'can', 'pkg' => 'package', 'pkgs' => 'package', 'package' => 'package',
  'packages' => 'package', 'bottle' => 'bottle', 'bottles' => 'bottle', 'box' => 'box', 'boxes' => 'box',
  'bag' => 'bag', 'bags' => 'bag', 'jar' => 'jar', 'jars' => 'jar', 'envelope' => 'envelope',
  'envelopes' => 'envelope', 'tub' => 'tub', 'tubs' => 'tub', 'container' => 'container', 'containers' => 'container',
];

// Countable pieces, singular form.
const COUNT_UNITS = [
  'clove' => 'clove', 'cloves' => 'clove', 'slice' => 'slice', 'slices' => 'slice', 'stalk' => 'stalk',
  'stalks' => 'stalk', 'rib' => 'rib', 'ribs' => 'rib', 'head' => 'head', 'heads' => 'head', 'bunch' => 'bunch',
  'bunches' => 'bunch', 'sprig' => 'sprig', 'sprigs' => 'sprig', 'sheet' => 'sheet', 'sheets' => 'sheet',
  'rack' => 'rack', 'racks' => 'rack', 'stick' => 'stick', 'sticks' => 'stick', 'strip' => 'strip',
  'strips' => 'strip', 'scoop' => 'scoop', 'scoops' => 'scoop', 'handful' => 'handful', 'handfuls' => 'handful',
  'pinch' => 'pinch', 'pinches' => 'pinch', 'dash' => 'dash', 'dashes' => 'dash', 'drop' => 'drop',
  'drops' => 'drop', 'splash' => 'splash', 'capful' => 'capful', 'cap' => 'capful', 'batch' => 'batch',
  'batches' => 'batch', 'piece' => 'piece', 'pieces' => 'piece', 'shaker' => 'shaker',
  'lemon' => 'lemon', 'lemons' => 'lemon', 'lime' => 'lime', 'limes' => 'lime',
];

// Size words used as a unit ("1 medium onion"): the line becomes a plain count and the
// word is kept as preparation. "each"/"whole" carry no information worth keeping.
const SIZE_WORDS = ['small', 'medium', 'large', 'big', 'whole', 'each', 'extra large', 'jumbo'];
const SIZE_WORDS_DROPPED = ['each', 'whole'];

// Phrases that sit in the unit column but aren't units: no unit, phrase kept as preparation.
const NON_UNIT_PHRASES = [
  'to taste', 'as needed', 'for serving', 'for topping', 'for slurry', 'for sauteing', 'for sautéing',
  'for finishing', 'for garnish', 'drizzle', 'a lot!', 'to cover bottom of large pan', 'optional',
];

/**
 * Interprets a free-text unit.
 * Returns ['unit' => ?string, 'dimension' => string, 'package' => ?string,
 *          'prep' => ?string (words to prepend to preparation), 'optional' => bool, 'known' => bool]
 */
function interpretUnit(?string $raw): array {
  $out = ['unit' => null, 'dimension' => 'unknown', 'package' => null, 'prep' => null, 'optional' => false, 'known' => true];
  $text = trim((string)$raw);
  if ($text === '') { return $out; }

  if (isset(UNIT_EXACT_CASE[$text])) {
    $out['unit'] = UNIT_EXACT_CASE[$text];
    $out['dimension'] = 'volume';
    return $out;
  }

  $lower = normalizeName($text);
  if (in_array($lower, NON_UNIT_PHRASES, true) && $lower !== 'optional') {
    $out['prep'] = $text;
    return $out;
  }
  $lower = preg_replace('/^(a|an)\s+/', '', $lower);      // "a pinch", "a handful", "a splash"

  // "grams per person" → g + "per person"
  if (preg_match('/^(.+?)\s+(per\s+.+)$/', $lower, $m)) {
    $inner = interpretUnit($m[1]);
    if ($inner['known'] && $inner['unit'] !== null) {
      $inner['prep'] = trim(($inner['prep'] ?? '') . ' ' . $m[2]);
      return $inner;
    }
  }

  if (isset(UNIT_SYNONYMS[$lower])) {
    $out['unit'] = UNIT_SYNONYMS[$lower];
    $out['dimension'] = UNIT_DIMENSION[$out['unit']];
    return $out;
  }

  if ($lower === 'optional') {
    $out['optional'] = true;
    return $out;
  }

  if (in_array($lower, NON_UNIT_PHRASES, true)) {
    $out['prep'] = $text;
    return $out;
  }

  // "pinch, or to taste" → pinch + "or to taste"
  if (preg_match('/^([a-z ]+?),\s*(.+)$/', $lower, $m) && isset(COUNT_UNITS[$m[1]])) {
    $out['unit'] = COUNT_UNITS[$m[1]];
    $out['dimension'] = 'count';
    $out['prep'] = $m[2];
    return $out;
  }

  // "can (15 oz)", "cans (14.5 oz)", "pkg (8 oz)"
  if (preg_match('/^([a-z]+)\s*\(([^)]+)\)$/', $lower, $m) && isset(PACKAGE_UNITS[$m[1]])) {
    $out['unit'] = PACKAGE_UNITS[$m[1]];
    $out['dimension'] = 'package';
    $out['package'] = trim($m[2]);
    return $out;
  }

  // "#10 can"
  if (preg_match('/^(#\d+)\s+([a-z]+)$/', $lower, $m) && isset(PACKAGE_UNITS[$m[2]])) {
    $out['unit'] = PACKAGE_UNITS[$m[2]];
    $out['dimension'] = 'package';
    $out['package'] = $m[1];
    return $out;
  }

  // Plain package or count unit, optionally with a size or descriptive word in front:
  // "can", "large can", "small head", "big pinch", "wide strips", "inch piece", "few dashes".
  if (preg_match('/^(?:([a-z]+(?: [a-z]+)?)\s+)?([a-z]+)$/', $lower, $m)) {
    $lead = $m[1] ?? '';
    $word = $m[2];
    if (isset(PACKAGE_UNITS[$word]) || isset(COUNT_UNITS[$word])) {
      $isPackage = isset(PACKAGE_UNITS[$word]);
      $out['unit'] = $isPackage ? PACKAGE_UNITS[$word] : COUNT_UNITS[$word];
      $out['dimension'] = $isPackage ? 'package' : 'count';
      if ($lead !== '') {
        if ($isPackage) { $out['package'] = $lead; } else { $out['prep'] = $lead; }
      }
      return $out;
    }
  }

  if (in_array($lower, SIZE_WORDS, true)) {
    $out['dimension'] = 'count';
    if (!in_array($lower, SIZE_WORDS_DROPPED, true)) { $out['prep'] = $lower; }
    return $out;
  }

  // Unknown: keep it exactly as written so nothing is lost, and report it.
  $out['unit'] = mb_substr($text, 0, 30);
  $out['known'] = false;
  return $out;
}

/**
 * Interprets a stored quantity.
 * Returns ['quantity' => ?float, 'max' => ?float, 'ok' => bool]. ok=false means the value
 * was present but unreadable — the caller imports no quantity and reports it.
 */
function interpretQuantity($raw): array {
  if ($raw === null || $raw === '') { return ['quantity' => null, 'max' => null, 'ok' => true]; }
  if (is_int($raw) || is_float($raw)) {
    $v = (float)$raw;
    return ['quantity' => $v > 0 ? $v : null, 'max' => null, 'ok' => true];
  }
  $text = trim((string)$raw);
  $single = parseQuantityText($text);
  if ($single !== null) { return ['quantity' => $single > 0 ? $single : null, 'max' => null, 'ok' => true]; }
  // Ranges: "1-2", "1–2", "1 to 2"
  if (preg_match('/^(.+?)\s*(?:-|–|—|to)\s*(.+)$/u', $text, $m)) {
    $lo = parseQuantityText($m[1]);
    $hi = parseQuantityText($m[2]);
    if ($lo !== null && $hi !== null && $hi > $lo) { return ['quantity' => $lo, 'max' => $hi, 'ok' => true]; }
  }
  return ['quantity' => null, 'max' => null, 'ok' => false];
}

const UNICODE_FRACTIONS = ['½' => 0.5, '⅓' => 1 / 3, '⅔' => 2 / 3, '¼' => 0.25, '¾' => 0.75,
  '⅛' => 0.125, '⅜' => 0.375, '⅝' => 0.625, '⅞' => 0.875, '⅕' => 0.2, '⅙' => 1 / 6];

// "2", "1.5", "1/2", "1 1/2", "1½", "½" → float; anything else → null.
function parseQuantityText(string $text): ?float {
  $t = trim($text);
  if ($t === '') { return null; }
  if (preg_match('/^\d+(?:\.\d+)?$/', $t)) { return (float)$t; }
  if (preg_match('/^(?:(\d+)\s+)?(\d+)\/(\d+)$/', $t, $m) && (int)$m[3] !== 0) {
    return (float)($m[1] ?? 0) + (int)$m[2] / (int)$m[3];
  }
  foreach (UNICODE_FRACTIONS as $glyph => $value) {
    if (preg_match('/^(\d*)\s*' . preg_quote($glyph, '/') . '$/u', $t, $m)) {
      return (float)($m[1] === '' ? 0 : $m[1]) + $value;
    }
  }
  return null;
}

// Units that read better in the plural ("2 cups", "3 cloves"); abbreviations (tsp, oz, lb) never change.
const PLURAL_UNITS = [
  'cup' => 'cups', 'pint' => 'pints', 'quart' => 'quarts', 'gallon' => 'gallons', 'liter' => 'liters',
  'can' => 'cans', 'package' => 'packages', 'bottle' => 'bottles', 'box' => 'boxes', 'bag' => 'bags', 'jar' => 'jars',
  'envelope' => 'envelopes', 'tub' => 'tubs', 'container' => 'containers', 'clove' => 'cloves', 'slice' => 'slices',
  'stalk' => 'stalks', 'rib' => 'ribs', 'head' => 'heads', 'bunch' => 'bunches', 'sprig' => 'sprigs', 'sheet' => 'sheets',
  'rack' => 'racks', 'stick' => 'sticks', 'strip' => 'strips', 'scoop' => 'scoops', 'handful' => 'handfuls',
  'pinch' => 'pinches', 'dash' => 'dashes', 'drop' => 'drops', 'piece' => 'pieces', 'batch' => 'batches',
  'capful' => 'capfuls', 'lemon' => 'lemons', 'lime' => 'limes',
];

function unitLabel(?string $unit, ?float $quantity, ?float $quantityMax = null): string {
  if ($unit === null || $unit === '') { return ''; }
  $many = ($quantityMax ?? $quantity ?? 0) > 1;
  return $many && isset(PLURAL_UNITS[$unit]) ? PLURAL_UNITS[$unit] : $unit;
}

// Friendly display: 0.5 → "½", 1.25 → "1¼", 0.333 → "⅓", 2 → "2", 0.4 → "0.4".
function formatQuantity(?float $q): string {
  if ($q === null) { return ''; }
  $whole = (int)floor($q + 1e-9);
  $frac = $q - $whole;
  if ($frac < 0.01) { return (string)$whole; }
  foreach (UNICODE_FRACTIONS as $glyph => $value) {
    if (abs($frac - $value) < 0.01) { return ($whole > 0 ? (string)$whole : '') . $glyph; }
  }
  return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

// "25 minutes", "2 hours 35 minutes", "1 hr 15 min", "1.5 hours" → minutes; else null.
function parseMinutes(?string $text): ?int {
  $t = normalizeName((string)$text);
  if ($t === '') { return null; }
  $total = 0.0;
  $matched = false;
  if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/', $t, $m)) { $total += (float)$m[1] * 60; $matched = true; }
  if (preg_match('/(\d+)\s*(?:minutes?|mins?|m)\b/', $t, $m)) { $total += (int)$m[1]; $matched = true; }
  return $matched ? (int)round($total) : null;
}
