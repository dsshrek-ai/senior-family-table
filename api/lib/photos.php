<?php
// Recipe and note photos (docs/ARCHITECTURE.md §8).
// Files live in PHOTO_DIR, outside public_html, named by a random key. Browsers fetch them
// through signed links that expire, because an <img> can't send the sign-in token.

require_once __DIR__ . '/core.php';

const PHOTO_FULL_PX = 1600;
const PHOTO_THUMB_PX = 480;
const PHOTO_MAX_PIXELS = 30000000;   // 30 megapixels — keeps GD within the memory limit
const RECIPE_PHOTO_LIMIT = 30;
const NOTE_PHOTO_LIMIT = 4;

function photoPath(string $key, string $size): string {
  return rtrim(PHOTO_DIR, '/\\') . '/' . $key . '_' . $size . '.jpg';
}

// ---- Signed links ------------------------------------------------------------

function photoSig(string $type, int $id, string $size, int $exp): string {
  $secret = base64_decode(PHOTO_URL_KEY, true) ?: PHOTO_URL_KEY;
  return substr(hash_hmac('sha256', "$type:$id:$size:$exp", $secret), 0, 40);
}

/**
 * The query string of a signed link (the page puts the API address in front). Expiry is
 * rounded to the hour, so the link stays the same — and cached — for at least an hour.
 */
function photoLink(string $type, int $id, string $size): string {
  $ttl = max(300, defined('PHOTO_URL_TTL_SECONDS') ? (int)PHOTO_URL_TTL_SECONDS : 3600);
  $exp = (intdiv(time(), $ttl) + 2) * $ttl;
  return '?' . http_build_query(['action' => 'photo', 't' => $type, 'id' => $id, 's' => $size, 'e' => $exp,
    'g' => photoSig($type, $id, $size, $exp)]);
}

/** Answers a signed photo link with the image itself. No sign-in; the signature is the permission. */
function servePhoto(array $get): void {
  $type = (string)($get['t'] ?? '');
  $id = (int)($get['id'] ?? 0);
  $size = (string)($get['s'] ?? '');
  $exp = (int)($get['e'] ?? 0);
  $sig = (string)($get['g'] ?? '');
  if (!in_array($type, ['r', 'n'], true) || !in_array($size, ['full', 'thumb'], true) || $id <= 0
      || !hash_equals(photoSig($type, $id, $size, $exp), $sig)) {
    notFound('Photo');
  }
  if ($exp < time()) { fail('This photo link has expired — reload the page', 410); }
  $row = $type === 'r'
    ? q1('SELECT p.storage_key FROM sft_recipe_photos p JOIN sft_recipes r ON r.id = p.recipe_id AND r.deleted_at IS NULL
           WHERE p.id = ? AND p.deleted_at IS NULL', 'i', [$id])
    : q1('SELECT p.storage_key FROM sft_recipe_note_photos p JOIN sft_recipe_notes n ON n.id = p.note_id AND n.deleted_at IS NULL
           JOIN sft_recipes r ON r.id = n.recipe_id AND r.deleted_at IS NULL WHERE p.id = ?', 'i', [$id]);
  $path = $row ? photoPath($row['storage_key'], $size) : '';
  if (!$row || !is_file($path)) { notFound('Photo'); }
  header('Content-Type: image/jpeg');
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: private, max-age=' . max(0, $exp - time()));
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

// ---- Upload: check, turn upright, shrink, re-encode (drops location and other metadata) ----

/** @return array{key: string, width: int, height: int} */
function storeUploadedPhoto(?array $file): array {
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { fail('Choose a photo', 400, 'file'); }
  $maxMb = defined('PHOTO_MAX_UPLOAD_MB') ? (int)PHOTO_MAX_UPLOAD_MB : 15;
  if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $maxMb * 1048576) {
    fail("That photo is too big (the limit is $maxMb MB)", 400, 'file');
  }
  if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { fail('The photo didn\'t arrive — please try again', 400, 'file'); }
  if (!function_exists('imagecreatetruecolor')) { fail('Photos aren\'t set up on the server yet (GD is missing)', 500); }

  // The real file type, from its contents — never the name.
  $info = @getimagesize($file['tmp_name']);
  $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
  if (!$info || !isset($types[$info[2]]) || !function_exists($types[$info[2]])) { fail('Use a JPEG, PNG or WebP photo', 400, 'file'); }
  if ($info[0] * $info[1] > PHOTO_MAX_PIXELS) { fail('That photo has too many pixels — try a smaller copy', 400, 'file'); }

  @ini_set('memory_limit', '256M');
  $src = @($types[$info[2]])($file['tmp_name']);
  if (!$src) { fail('That photo couldn\'t be read — it may be damaged', 400, 'file'); }
  $src = applyExifRotation($src, $file['tmp_name'], $info[2]);

  if (!is_dir(PHOTO_DIR) && !@mkdir(PHOTO_DIR, 0750, true)) { fail('The photo folder isn\'t set up on the server yet', 500); }
  $key = bin2hex(random_bytes(16));
  $full = shrinkTo($src, PHOTO_FULL_PX);
  $thumb = shrinkTo($full, PHOTO_THUMB_PX);
  $w = imagesx($full);
  $h = imagesy($full);
  writeJpeg($full, photoPath($key, 'full'), 82);
  writeJpeg($thumb, photoPath($key, 'thumb'), 78);
  imagedestroy($src);
  imagedestroy($full);
  imagedestroy($thumb);
  return ['key' => $key, 'width' => $w, 'height' => $h];
}

// Phones store "which way is up" separately; JPEG re-encoding would lose it.
function applyExifRotation($img, string $path, int $type) {
  if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) { return $img; }
  $exif = @exif_read_data($path);
  $o = (int)($exif['Orientation'] ?? 1);
  if (in_array($o, [2, 4, 5, 7], true)) { imageflip($img, IMG_FLIP_HORIZONTAL); }
  $angle = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;
  if ($angle) {
    $rotated = imagerotate($img, $angle, 0);
    if ($rotated) { imagedestroy($img); $img = $rotated; }
  }
  return $img;
}

// A new image that fits inside $max × $max, on white (transparent PNGs would turn black as JPEG).
function shrinkTo($img, int $max) {
  $w = imagesx($img);
  $h = imagesy($img);
  $scale = min(1, $max / max($w, $h));
  $nw = max(1, (int)round($w * $scale));
  $nh = max(1, (int)round($h * $scale));
  $out = imagecreatetruecolor($nw, $nh);
  imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
  imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
  return $out;
}

function writeJpeg($img, string $path, int $quality): void {
  $tmp = $path . '.part';
  imageinterlace($img, true);
  if (!imagejpeg($img, $tmp, $quality) || !rename($tmp, $path)) {
    @unlink($tmp);
    fail('The photo couldn\'t be saved on the server', 500);
  }
}

function removePhotoFiles(string $key): void {
  @unlink(photoPath($key, 'full'));
  @unlink(photoPath($key, 'thumb'));
}

// ---- Recipe photos -----------------------------------------------------------

function recipePhotos(int $recipeId): array {
  $rows = q(
    'SELECT p.id, p.caption, p.is_cover, p.width, p.height, p.uploaded_at, u.id AS uid, u.username, u.display_name
       FROM sft_recipe_photos p JOIN users u ON u.id = p.uploaded_by
      WHERE p.recipe_id = ? AND p.deleted_at IS NULL ORDER BY p.position, p.id', 'i', [$recipeId]);
  return array_map(fn($p) => [
    'id' => (int)$p['id'], 'caption' => $p['caption'], 'isCover' => (bool)$p['is_cover'],
    'width' => (int)$p['width'], 'height' => (int)$p['height'], 'uploadedAt' => $p['uploaded_at'],
    'uploadedBy' => ['id' => (int)$p['uid'], 'name' => $p['display_name'] ?: $p['username']],
    'thumb' => photoLink('r', (int)$p['id'], 'thumb'), 'full' => photoLink('r', (int)$p['id'], 'full'),
  ], $rows);
}

/** The photo, if this user may change it (anyone who can edit the recipe). */
function editableRecipePhoto(int $userId, int $photoId): array {
  $p = q1('SELECT * FROM sft_recipe_photos WHERE id = ? AND deleted_at IS NULL', 'i', [$photoId]);
  if (!$p || !canViewRecipe($userId, (int)$p['recipe_id'])) { notFound('Photo'); }
  if (!canEditRecipe($userId, (int)$p['recipe_id'])) { fail('Only people who can edit this recipe can change its photos', 403); }
  return $p;
}

/** Positions 0..n-1 in the current order. The first photo is the cover. */
function tidyRecipePhotos(int $recipeId): void {
  $rows = q('SELECT id FROM sft_recipe_photos WHERE recipe_id = ? AND deleted_at IS NULL ORDER BY position, id', 'i', [$recipeId]);
  foreach ($rows as $i => $r) {
    exec_('UPDATE sft_recipe_photos SET position = ?, is_cover = ? WHERE id = ?', 'iii', [$i, $i === 0 ? 1 : 0, (int)$r['id']]);
  }
}

// ---- Note photos (same storage; seen by whoever can see the note) ----------------

/** @param int[] $noteIds @return array<int, array> note id => photos */
function notePhotos(array $noteIds): array {
  $out = [];
  if (!$noteIds) { return $out; }
  $ids = implode(',', array_map('intval', $noteIds));
  foreach (q("SELECT id, note_id FROM sft_recipe_note_photos WHERE note_id IN ($ids) ORDER BY id") as $p) {
    $out[(int)$p['note_id']][] = ['id' => (int)$p['id'], 'thumb' => photoLink('n', (int)$p['id'], 'thumb'), 'full' => photoLink('n', (int)$p['id'], 'full')];
  }
  return $out;
}
