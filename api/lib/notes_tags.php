<?php
// Family notes and tags: permission checks and small helpers (REQUIREMENTS.md §7–8).

require_once __DIR__ . '/core.php';

function noteVisibility(array $b): string {
  $v = (string)($b['visibility'] ?? 'shared');
  if (!in_array($v, ['shared', 'private'], true)) { fail('Choose who can see this note', 400, 'visibility'); }
  return $v;
}

/**
 * The note, if this user may see it: shared notes on a recipe they can see, or their own.
 * Another person's private note is "not found" — even for a co-owner.
 */
function visibleNote(int $userId, int $noteId): array {
  $note = q1('SELECT * FROM sft_recipe_notes WHERE id = ? AND deleted_at IS NULL', 'i', [$noteId]);
  if (!$note || ($note['visibility'] === 'private' && (int)$note['user_id'] !== $userId) || !canViewRecipe($userId, (int)$note['recipe_id'])) {
    notFound('Note');
  }
  return $note;
}

function noteById(int $noteId, int $userId): array {
  $n = q1('SELECT n.*, u.username, u.display_name FROM sft_recipe_notes n JOIN users u ON u.id = n.user_id WHERE n.id = ?', 'i', [$noteId]);
  return [
    'id' => (int)$n['id'], 'visibility' => $n['visibility'], 'text' => $n['note_text'],
    'author' => ['id' => (int)$n['user_id'], 'name' => $n['display_name'] ?: $n['username']],
    'mine' => (int)$n['user_id'] === $userId, 'createdAt' => $n['created_at'], 'updatedAt' => $n['updated_at'],
  ];
}

// Tidy tag names: spaces collapsed, each word capitalised unless it already has capitals
// ("bbq" → "Bbq" but "BBQ" stays, "make-ahead" → "Make-ahead").
function tagName(string $raw): string {
  $name = trim(preg_replace('/\s+/u', ' ', $raw));
  if ($name === '') { fail('Name the tag', 400, 'name'); }
  if (mb_strlen($name) > 80) { fail('Tag names can be up to 80 characters', 400, 'name'); }
  $words = array_map(function ($w) {
    if (preg_match('/\p{Lu}/u', $w)) { return $w; }
    return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
  }, explode(' ', $name));
  return implode(' ', $words);
}

/** May this user put $tagId on (or take it off) $recipeId? Returns the tag. */
function requireTagUse(int $userId, int $tagId, int $recipeId): array {
  $tag = q1('SELECT * FROM sft_categories WHERE id = ?', 'i', [$tagId]);
  if (!$tag) { notFound('Tag'); }
  if ($tag['scope'] === 'system') {
    if (!canEditRecipe($userId, $recipeId)) { fail('Only people who can edit this recipe can change its built-in tags', 403); }
  } elseif ($tag['scope'] === 'cookbook') {
    $role = cookbookRole($userId, (int)$tag['cookbook_id']);
    $inBook = q1('SELECT 1 FROM sft_cookbook_recipes WHERE cookbook_id = ? AND recipe_id = ?', 'ii', [(int)$tag['cookbook_id'], $recipeId]);
    if ($role === null || !$inBook) { notFound('Tag'); }
    if (!in_array($role, ['owner', 'editor'], true)) { fail('Only the cookbook\'s owners and editors can use its tags', 403); }
  } elseif ((int)$tag['owner_user_id'] !== $userId) {
    notFound('Tag');
  }
  return $tag;
}

/** May this user rename or delete $tagId? Built-in tags never change. */
function requireTagManage(int $userId, int $tagId): array {
  $tag = q1('SELECT * FROM sft_categories WHERE id = ?', 'i', [$tagId]);
  if (!$tag) { notFound('Tag'); }
  if ($tag['scope'] === 'system') { fail('Built-in tags can\'t be renamed or deleted', 403); }
  if ($tag['scope'] === 'cookbook') {
    requireCookbookRole($userId, (int)$tag['cookbook_id'], ['owner', 'editor']);
  } elseif ((int)$tag['owner_user_id'] !== $userId) {
    notFound('Tag');
  }
  return $tag;
}
