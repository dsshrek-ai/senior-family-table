<?php
// The Senior Family Table — API.
// Sign-in is My Apps Hub SSO; every action checks the permission rules in lib/core.php
// (docs/ARCHITECTURE.md §3.2). Guessed IDs always get "not found".

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/recipes.php';
require_once __DIR__ . '/lib/shopping.php';
require_once __DIR__ . '/lib/notes_tags.php';
require_once __DIR__ . '/lib/paste_import.php';
require_once __DIR__ . '/lib/photos.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function body(): array {
  static $decoded = null;
  if ($decoded === null) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($decoded)) { $decoded = []; }
  }
  return $decoded;
}

function requirePost(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('Use POST for this action', 405); }
}

function intParam(array $src, string $key): int {
  $v = $src[$key] ?? null;
  if (!is_numeric($v) || (int)$v <= 0) { fail('Missing ' . $key, 400, $key); }
  return (int)$v;
}

function textParam(array $src, string $key, int $max, bool $required = true): ?string {
  $v = trim((string)($src[$key] ?? ''));
  if ($v === '') {
    if ($required) { fail('Please fill in ' . $key, 400, $key); }
    return null;
  }
  if (mb_strlen($v) > $max) { fail(ucfirst($key) . ' is too long (max ' . $max . ' characters)', 400, $key); }
  return $v;
}

function roleParam(array $src, array $allowed): string {
  $role = (string)($src['role'] ?? '');
  if (!in_array($role, $allowed, true)) { fail('Choose a role: ' . implode(', ', $allowed), 400, 'role'); }
  return $role;
}

// Signed-in user with access to this app.
function currentUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) { fail('Please sign in', 401); }
  $user = userFromToken($m[1]);
  if (!$user) { fail('Your session has expired — please sign in again', 401); }
  if (!hasAppAccess((int)$user['id'])) { fail('You don\'t have access to The Senior Family Table yet', 403); }
  $user['id'] = (int)$user['id'];
  return $user;
}

function publicUser(array $row): array {
  return ['id' => (int)$row['id'], 'name' => $row['display_name'] ?: $row['username']];
}

// The last owner of something can't be removed or demoted.
function ownerCount(string $table, string $idColumn, int $id, string $ownerRole): int {
  $row = q1("SELECT COUNT(*) AS n FROM $table WHERE $idColumn = ? AND role = ?", 'is', [$id, $ownerRole]);
  return (int)$row['n'];
}

// Tags the user is allowed to see on a recipe: built-in, their own, and those of
// cookbooks they belong to.
function visibleRecipeTags(int $userId, int $recipeId): array {
  return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'scope' => $r['scope']], q(
    "SELECT c.id, c.name, c.kind, c.scope FROM sft_recipe_categories rc JOIN sft_categories c ON c.id = rc.category_id
      WHERE rc.recipe_id = ? AND (c.scope = 'system'
         OR (c.scope = 'user' AND c.owner_user_id = ?)
         OR (c.scope = 'cookbook' AND EXISTS (SELECT 1 FROM sft_cookbook_members m WHERE m.cookbook_id = c.cookbook_id AND m.user_id = ?)))
      ORDER BY c.kind, c.name",
    'iii', [$recipeId, $userId, $userId]
  ));
}

// ---------------------------------------------------------------------------

try {
  $action = (string)($_GET['action'] ?? body()['action'] ?? '');

  // ---- Sign-in for people who open the app directly instead of from the Hub ----
  if ($action === 'login') {
    requirePost();
    $b = body();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    if ($username === '' || $password === '') { fail('Enter your email and password'); }
    $row = q1('SELECT id, password_hash FROM users WHERE username = ?', 's', [$username]);
    if (!$row || !password_verify($password, (string)$row['password_hash'])) { fail('Incorrect email or password', 401); }
    $token = bin2hex(random_bytes(32));
    $days = defined('SESSION_LIFETIME_DAYS') ? (int)SESSION_LIFETIME_DAYS : 30;
    exec_('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
      'sii', [$token, (int)$row['id'], $days]);
    respond(['ok' => true, 'token' => $token]);
  }
  if ($action === 'logout') {
    requirePost();
    $token = (string)(body()['token'] ?? '');
    if ($token !== '') { exec_('DELETE FROM sessions WHERE token = ?', 's', [$token]); }
    respond(['ok' => true]);
  }

  // Signed, expiring photo links (lib/photos.php). The signature is the permission.
  if ($action === 'photo') { servePhoto($_GET); }

  $user = currentUser();
  $uid = $user['id'];

  switch ($action) {

    case 'whoAmI':
      respond(['ok' => true, 'user' => publicUser($user), 'isAdmin' => (bool)$user['is_admin']]);

    // ---- Cookbooks ---------------------------------------------------------

    case 'listCookbooks': {
      $rows = q(
        'SELECT c.id, c.title, c.description, c.archived_at, m.role,
                (SELECT COUNT(*) FROM sft_cookbook_recipes cr JOIN sft_recipes r ON r.id = cr.recipe_id AND r.deleted_at IS NULL
                  WHERE cr.cookbook_id = c.id) AS recipe_count,
                (SELECT COUNT(*) FROM sft_cookbook_members m2 WHERE m2.cookbook_id = c.id) AS member_count
           FROM sft_cookbooks c JOIN sft_cookbook_members m ON m.cookbook_id = c.id AND m.user_id = ?
          WHERE c.deleted_at IS NULL ORDER BY c.archived_at IS NOT NULL, c.title',
        'i', [$uid]
      );
      respond(['ok' => true, 'cookbooks' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'],
        'archived' => $r['archived_at'] !== null, 'role' => $r['role'],
        'recipeCount' => (int)$r['recipe_count'], 'shared' => (int)$r['member_count'] > 1,
      ], $rows)]);
    }

    case 'createCookbook': {
      requirePost();
      $b = body();
      $title = textParam($b, 'title', 150);
      $description = textParam($b, 'description', 2000, false);
      db()->begin_transaction();
      $id = exec_('INSERT INTO sft_cookbooks (title, description, created_by) VALUES (?, ?, ?)', 'ssi', [$title, $description, $uid]);
      exec_("INSERT INTO sft_cookbook_members (cookbook_id, user_id, role, added_by) VALUES (?, ?, 'owner', ?)", 'iii', [$id, $uid, $uid]);
      db()->commit();
      respond(['ok' => true, 'id' => $id]);
    }

    case 'updateCookbook': {
      requirePost();
      $b = body();
      $id = intParam($b, 'id');
      requireCookbookRole($uid, $id, ['owner']);
      if (array_key_exists('title', $b)) {
        exec_('UPDATE sft_cookbooks SET title = ? WHERE id = ?', 'si', [textParam($b, 'title', 150), $id]);
      }
      if (array_key_exists('description', $b)) {
        exec_('UPDATE sft_cookbooks SET description = ? WHERE id = ?', 'si', [textParam($b, 'description', 2000, false), $id]);
      }
      if (array_key_exists('archived', $b)) {
        exec_('UPDATE sft_cookbooks SET archived_at = ' . ($b['archived'] ? 'NOW()' : 'NULL') . ' WHERE id = ?', 'i', [$id]);
      }
      respond(['ok' => true]);
    }

    // Soft delete; recipes are never deleted with a cookbook, only unlinked from view.
    case 'deleteCookbook': {
      requirePost();
      $id = intParam(body(), 'id');
      requireCookbookRole($uid, $id, ['owner']);
      exec_('UPDATE sft_cookbooks SET deleted_at = NOW() WHERE id = ?', 'i', [$id]);
      audit($uid, 'cookbook', $id, 'deleted');
      respond(['ok' => true]);
    }

    case 'getCookbook': {
      $id = intParam($_GET, 'id');
      $role = requireCookbookRole($uid, $id, ['owner', 'editor', 'viewer']);
      $book = q1('SELECT id, title, description, archived_at FROM sft_cookbooks WHERE id = ?', 'i', [$id]);
      $recipes = q(
        'SELECT r.id, r.title, r.description, r.servings, cr.display_order, cp.id AS cover_id,
                (SELECT us.is_favorite FROM sft_recipe_user_state us WHERE us.recipe_id = r.id AND us.user_id = ?) AS fav
           FROM sft_cookbook_recipes cr JOIN sft_recipes r ON r.id = cr.recipe_id AND r.deleted_at IS NULL
           LEFT JOIN sft_recipe_photos cp ON cp.recipe_id = r.id AND cp.is_cover = 1 AND cp.deleted_at IS NULL
          WHERE cr.cookbook_id = ? ORDER BY cr.display_order, r.title',
        'ii', [$uid, $id]
      );
      $out = [];
      foreach ($recipes as $r) {
        $out[] = [
          'id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'],
          'servings' => $r['servings'] !== null ? (float)$r['servings'] : null, 'favorite' => (bool)$r['fav'],
          'tags' => array_map(fn($t) => $t['name'], visibleRecipeTags($uid, (int)$r['id'])),
          'cover' => $r['cover_id'] ? photoLink('r', (int)$r['cover_id'], 'thumb') : null,
        ];
      }
      respond(['ok' => true, 'cookbook' => [
        'id' => (int)$book['id'], 'title' => $book['title'], 'description' => $book['description'],
        'archived' => $book['archived_at'] !== null, 'role' => $role,
      ], 'recipes' => $out]);
    }

    // ---- Cookbook sharing --------------------------------------------------

    case 'cookbookMembers': {
      $id = intParam($_GET, 'id');
      requireCookbookRole($uid, $id, ['owner', 'editor', 'viewer']);
      $rows = q(
        'SELECT u.id, u.username, u.display_name, m.role FROM sft_cookbook_members m JOIN users u ON u.id = m.user_id
          WHERE m.cookbook_id = ? ORDER BY m.role, u.display_name',
        'i', [$id]
      );
      respond(['ok' => true, 'members' => array_map(fn($r) => publicUser($r) + ['role' => $r['role']], $rows)]);
    }

    case 'addCookbookMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'cookbookId');
      requireCookbookRole($uid, $id, ['owner']);
      $role = roleParam($b, ['owner', 'editor', 'viewer']);
      $invitee = userByUsername((string)($b['username'] ?? ''));
      if (!$invitee) {
        fail('No My Apps Hub account uses that username. They need to sign up on the Hub first.', 404, 'username');
      }
      $inviteeId = (int)$invitee['id'];
      if (cookbookRole($inviteeId, $id) !== null) { fail('They\'re already in this cookbook', 409, 'username'); }
      exec_('INSERT INTO sft_cookbook_members (cookbook_id, user_id, role, added_by) VALUES (?, ?, ?, ?)',
        'iisi', [$id, $inviteeId, $role, $uid]);
      grantAppAccess($inviteeId);
      audit($uid, 'cookbook', $id, 'member_added', "user $inviteeId as $role");
      respond(['ok' => true, 'member' => publicUser($invitee) + ['role' => $role]]);
    }

    case 'setCookbookMemberRole': {
      requirePost();
      $b = body();
      $id = intParam($b, 'cookbookId');
      $memberId = intParam($b, 'userId');
      requireCookbookRole($uid, $id, ['owner']);
      $role = roleParam($b, ['owner', 'editor', 'viewer']);
      $current = cookbookRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'owner' && $role !== 'owner' && ownerCount('sft_cookbook_members', 'cookbook_id', $id, 'owner') <= 1) {
        fail('A cookbook needs at least one owner. Make someone else an owner first.', 409);
      }
      exec_('UPDATE sft_cookbook_members SET role = ? WHERE cookbook_id = ? AND user_id = ?', 'sii', [$role, $id, $memberId]);
      audit($uid, 'cookbook', $id, 'role_changed', "user $memberId: $current → $role");
      respond(['ok' => true]);
    }

    // Owners can remove anyone; anyone can remove themselves (leave).
    case 'removeCookbookMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'cookbookId');
      $memberId = intParam($b, 'userId');
      if ($memberId === $uid) {
        requireCookbookRole($uid, $id, ['owner', 'editor', 'viewer']);
      } else {
        requireCookbookRole($uid, $id, ['owner']);
      }
      $current = cookbookRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'owner' && ownerCount('sft_cookbook_members', 'cookbook_id', $id, 'owner') <= 1) {
        fail('A cookbook needs at least one owner. Make someone else an owner first, or delete the cookbook.', 409);
      }
      exec_('DELETE FROM sft_cookbook_members WHERE cookbook_id = ? AND user_id = ?', 'ii', [$id, $memberId]);
      audit($uid, 'cookbook', $id, $memberId === $uid ? 'member_left' : 'member_removed', "user $memberId ($current)");
      respond(['ok' => true]);
    }

    // ---- Recipes -----------------------------------------------------------

    case 'getRecipe': {
      $id = intParam($_GET, 'id');
      requireViewRecipe($uid, $id);
      $recipe = recipeMaster($id);
      $role = recipeRole($uid, $id);
      // Only cookbooks this user belongs to — never reveal the others (§3.2).
      $cookbooks = q(
        'SELECT c.id, c.title, m.role FROM sft_cookbook_recipes cr JOIN sft_cookbooks c ON c.id = cr.cookbook_id AND c.deleted_at IS NULL
           JOIN sft_cookbook_members m ON m.cookbook_id = c.id AND m.user_id = ?
          WHERE cr.recipe_id = ? ORDER BY c.title', 'ii', [$uid, $id]);
      // Shared notes, plus this user's own private notes — never anyone else's.
      $notes = q(
        "SELECT n.id, n.visibility, n.note_text, n.created_at, n.updated_at, u.id AS author_id, u.username, u.display_name
           FROM sft_recipe_notes n JOIN users u ON u.id = n.user_id
          WHERE n.recipe_id = ? AND n.deleted_at IS NULL AND (n.visibility = 'shared' OR n.user_id = ?)
          ORDER BY n.created_at", 'ii', [$id, $uid]);
      $state = q1('SELECT is_favorite, rating, tested_approved, saved_scale_mode, saved_scale FROM sft_recipe_user_state WHERE recipe_id = ? AND user_id = ?', 'ii', [$id, $uid]);
      $updatedBy = q1('SELECT u.id, u.username, u.display_name FROM sft_recipes r JOIN users u ON u.id = r.updated_by WHERE r.id = ?', 'i', [$id]);
      $notePhotos = notePhotos(array_map(fn($n) => (int)$n['id'], $notes));

      $recipe += [
        'myRole' => $role, 'canEdit' => in_array($role, ['co_owner', 'editor'], true), 'canManage' => $role === 'co_owner',
        'updatedBy' => $updatedBy ? publicUser($updatedBy) : null,
        'tags' => visibleRecipeTags($uid, $id),
        'cookbooks' => array_map(fn($c) => ['id' => (int)$c['id'], 'title' => $c['title'], 'role' => $c['role']], $cookbooks),
        'notes' => array_map(fn($n) => [
          'id' => (int)$n['id'], 'visibility' => $n['visibility'], 'text' => $n['note_text'],
          'author' => publicUser(['id' => $n['author_id'], 'username' => $n['username'], 'display_name' => $n['display_name']]),
          'mine' => (int)$n['author_id'] === $uid, 'createdAt' => $n['created_at'], 'updatedAt' => $n['updated_at'],
          'photos' => $notePhotos[(int)$n['id']] ?? [],
        ], $notes),
        'photos' => recipePhotos($id),
        'me' => [
          'favorite' => $state ? (bool)$state['is_favorite'] : false,
          'rating' => $state && $state['rating'] !== null ? (int)$state['rating'] : null,
          'testedApproved' => $state ? (bool)$state['tested_approved'] : false,
          'savedScaleMode' => $state['saved_scale_mode'] ?? null,
          'savedScale' => $state && $state['saved_scale'] !== null ? (float)$state['saved_scale'] : null,
        ],
      ];
      respond(['ok' => true, 'recipe' => $recipe]);
    }

    // New recipe, created inside a cookbook the user can edit.
    case 'createRecipe': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      $clean = cleanRecipeInput((array)($b['recipe'] ?? []));
      db()->begin_transaction();
      $id = createRecipe($uid, $cookbookId, $clean);
      db()->commit();
      respond(['ok' => true, 'id' => $id]);
    }

    // Save over the master. `version` must be the version the editor loaded; if someone else
    // saved in between, nothing is written and the caller gets 409 with who and when.
    case 'saveRecipe': {
      requirePost();
      $b = body();
      $id = intParam($b, 'id');
      requireViewRecipe($uid, $id);
      if (!canEditRecipe($uid, $id)) { fail('You can view this recipe but not edit it', 403); }
      $clean = cleanRecipeInput((array)($b['recipe'] ?? []));
      db()->begin_transaction();
      $version = saveRecipe($uid, $id, intParam($b, 'version'), $clean, 'Edited');
      if ($version === null) {
        db()->rollback();
        $cur = q1('SELECT r.version, r.updated_at, u.id, u.username, u.display_name FROM sft_recipes r JOIN users u ON u.id = r.updated_by WHERE r.id = ?', 'i', [$id]);
        respond(['ok' => false, 'conflict' => true, 'error' => 'Someone else saved this recipe while you were editing.',
          'currentVersion' => (int)$cur['version'], 'updatedAt' => $cur['updated_at'], 'updatedBy' => publicUser($cur)], 409);
      }
      db()->commit();
      respond(['ok' => true, 'version' => $version]);
    }

    case 'deleteRecipe': {
      requirePost();
      $id = intParam(body(), 'id');
      requireViewRecipe($uid, $id);
      if (recipeRole($uid, $id) !== 'co_owner') { fail('Only a co-owner can delete this recipe', 403); }
      exec_('UPDATE sft_recipes SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', 'ii', [$uid, $id]);
      audit($uid, 'recipe', $id, 'deleted');
      respond(['ok' => true]);
    }

    // Recipes this user co-owns that were deleted in the last 30 days.
    case 'deletedRecipes': {
      $rows = q(
        "SELECT r.id, r.title, r.deleted_at FROM sft_recipes r JOIN sft_recipe_members m ON m.recipe_id = r.id AND m.user_id = ? AND m.role = 'co_owner'
          WHERE r.deleted_at IS NOT NULL AND r.deleted_at > NOW() - INTERVAL 30 DAY ORDER BY r.deleted_at DESC", 'i', [$uid]);
      respond(['ok' => true, 'recipes' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'deletedAt' => $r['deleted_at']], $rows)]);
    }

    case 'restoreRecipe': {
      requirePost();
      $id = intParam(body(), 'id');
      $ok = q1(
        "SELECT 1 FROM sft_recipes r JOIN sft_recipe_members m ON m.recipe_id = r.id AND m.user_id = ? AND m.role = 'co_owner'
          WHERE r.id = ? AND r.deleted_at IS NOT NULL AND r.deleted_at > NOW() - INTERVAL 30 DAY", 'ii', [$uid, $id]);
      if (!$ok) { notFound('Deleted recipe'); }
      exec_('UPDATE sft_recipes SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', 'i', [$id]);
      audit($uid, 'recipe', $id, 'restored');
      respond(['ok' => true]);
    }

    // ---- Revisions ---------------------------------------------------------

    case 'recipeRevisions': {
      $id = intParam($_GET, 'id');
      requireViewRecipe($uid, $id);
      $rows = q(
        'SELECT v.id, v.version, v.change_note, v.changed_at, u.id AS uid, u.username, u.display_name
           FROM sft_recipe_revisions v JOIN users u ON u.id = v.changed_by WHERE v.recipe_id = ? ORDER BY v.version DESC', 'i', [$id]);
      respond(['ok' => true, 'revisions' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'version' => (int)$r['version'], 'note' => $r['change_note'], 'changedAt' => $r['changed_at'],
        'changedBy' => publicUser(['id' => $r['uid'], 'username' => $r['username'], 'display_name' => $r['display_name']]),
      ], $rows)]);
    }

    case 'getRevision': {
      $id = intParam($_GET, 'id');
      requireViewRecipe($uid, $id);
      $row = q1('SELECT version, snapshot FROM sft_recipe_revisions WHERE recipe_id = ? AND id = ?', 'ii', [$id, intParam($_GET, 'revisionId')]);
      if (!$row) { notFound('Version'); }
      respond(['ok' => true, 'version' => (int)$row['version'], 'snapshot' => json_decode($row['snapshot'], true)]);
    }

    // Restoring = saving that snapshot as a new version. History is never rewritten.
    case 'restoreRevision': {
      requirePost();
      $b = body();
      $id = intParam($b, 'id');
      requireViewRecipe($uid, $id);
      if (!canEditRecipe($uid, $id)) { fail('You can view this recipe but not edit it', 403); }
      $row = q1('SELECT version, snapshot FROM sft_recipe_revisions WHERE recipe_id = ? AND id = ?', 'ii', [$id, intParam($b, 'revisionId')]);
      if (!$row) { notFound('Version'); }
      $clean = cleanRecipeInput(snapshotToInput((array)json_decode($row['snapshot'], true)));
      db()->begin_transaction();
      $version = saveRecipe($uid, $id, intParam($b, 'version'), $clean, 'Restored version ' . $row['version']);
      if ($version === null) {
        db()->rollback();
        fail('Someone else saved this recipe just now. Reload and try again.', 409);
      }
      audit($uid, 'recipe', $id, 'revision_restored', 'version ' . $row['version']);
      db()->commit();
      respond(['ok' => true, 'version' => $version]);
    }

    // ---- Recipes in cookbooks ----------------------------------------------

    // Add a recipe the user can already see into one of their cookbooks (no copy is made).
    case 'linkRecipe': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      $recipeId = intParam($b, 'recipeId');
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      requireViewRecipe($uid, $recipeId);
      exec_('INSERT IGNORE INTO sft_cookbook_recipes (cookbook_id, recipe_id, added_by) VALUES (?, ?, ?)', 'iii', [$cookbookId, $recipeId, $uid]);
      respond(['ok' => true]);
    }

    // A separate copy in one of the user's cookbooks (any recipe they can see). Editing either one
    // never changes the other. If that cookbook already has a recipe with the same title and no
    // new title is given, the copy is called "… (copy)".
    case 'copyRecipe': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      $recipeId = intParam($b, 'recipeId');
      requireViewRecipe($uid, $recipeId);
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      $src = q1('SELECT title FROM sft_recipes WHERE id = ?', 'i', [$recipeId]);
      $title = cleanText($b['title'] ?? null, 200, 'title') ?? $src['title'];
      if ($title === $src['title'] && q1(
        'SELECT 1 FROM sft_cookbook_recipes cr JOIN sft_recipes r ON r.id = cr.recipe_id AND r.deleted_at IS NULL
          WHERE cr.cookbook_id = ? AND r.title_normalized = ?', 'is', [$cookbookId, mb_substr(normalizeTitle($title), 0, 200)])) {
        $title = mb_substr($title, 0, 193) . ' (copy)';
      }
      db()->begin_transaction();
      $id = copyRecipe($uid, $recipeId, $cookbookId, $title);
      db()->commit();
      audit($uid, 'recipe', $id, 'copied', "from recipe $recipeId into cookbook $cookbookId");
      respond(['ok' => true, 'id' => $id, 'title' => $title]);
    }

    // Remove from one cookbook only; the recipe and its other cookbooks are untouched.
    case 'unlinkRecipe': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      $recipeId = intParam($b, 'recipeId');
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      exec_('DELETE FROM sft_cookbook_recipes WHERE cookbook_id = ? AND recipe_id = ?', 'ii', [$cookbookId, $recipeId]);
      respond(['ok' => true]);
    }

    // Search every recipe the user can see (or one cookbook): title, ingredients, visible tags.
    case 'searchRecipes': {
      $term = trim((string)($_GET['q'] ?? ''));
      $cookbookId = isset($_GET['cookbookId']) && $_GET['cookbookId'] !== '' ? intParam($_GET, 'cookbookId') : null;
      if ($cookbookId !== null) { requireCookbookRole($uid, $cookbookId, ['owner', 'editor', 'viewer']); }
      $favoritesOnly = !empty($_GET['favorites']);
      $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)) . '%';
      $sql = 'SELECT r.id, r.title, r.description, r.servings,
                     (SELECT us.is_favorite FROM sft_recipe_user_state us WHERE us.recipe_id = r.id AND us.user_id = ?) AS fav
                FROM sft_recipes r WHERE ' . VISIBLE_RECIPE_SQL;
      $types = 'iii';
      $params = [$uid, $uid, $uid];
      if ($term !== '') {
        $sql .= " AND (LOWER(r.title) LIKE ? OR EXISTS (SELECT 1 FROM sft_recipe_ingredients i WHERE i.recipe_id = r.id AND LOWER(i.item_name) LIKE ?)
                  OR EXISTS (SELECT 1 FROM sft_recipe_categories rc JOIN sft_categories c ON c.id = rc.category_id
                              WHERE rc.recipe_id = r.id AND LOWER(c.name) LIKE ? AND (c.scope = 'system'
                                OR (c.scope = 'user' AND c.owner_user_id = ?)
                                OR (c.scope = 'cookbook' AND EXISTS (SELECT 1 FROM sft_cookbook_members m WHERE m.cookbook_id = c.cookbook_id AND m.user_id = ?))))
                  OR EXISTS (SELECT 1 FROM sft_recipe_notes n WHERE n.recipe_id = r.id AND n.deleted_at IS NULL
                              AND LOWER(n.note_text) LIKE ? AND (n.visibility = 'shared' OR n.user_id = ?)))";
        $types .= 'sssiisi';
        array_push($params, $like, $like, $like, $uid, $uid, $like, $uid);
      }
      if ($cookbookId !== null) {
        $sql .= ' AND EXISTS (SELECT 1 FROM sft_cookbook_recipes x WHERE x.recipe_id = r.id AND x.cookbook_id = ?)';
        $types .= 'i';
        $params[] = $cookbookId;
      }
      if ($favoritesOnly) {
        $sql .= ' AND EXISTS (SELECT 1 FROM sft_recipe_user_state f WHERE f.recipe_id = r.id AND f.user_id = ? AND f.is_favorite = 1)';
        $types .= 'i';
        $params[] = $uid;
      }
      $sql .= ' ORDER BY r.title LIMIT 200';
      $rows = q($sql, $types, $params);
      respond(['ok' => true, 'recipes' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'],
        'servings' => $r['servings'] !== null ? (float)$r['servings'] : null, 'favorite' => (bool)$r['fav'],
      ], $rows)]);
    }

    case 'setFavorite': {
      requirePost();
      $b = body();
      $id = intParam($b, 'recipeId');
      requireViewRecipe($uid, $id);
      $fav = !empty($b['favorite']) ? 1 : 0;
      exec_('INSERT INTO sft_recipe_user_state (recipe_id, user_id, is_favorite) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_favorite = VALUES(is_favorite)', 'iii', [$id, $uid, $fav]);
      respond(['ok' => true, 'favorite' => (bool)$fav]);
    }

    // ---- Recipe sharing (co-owners, editors, viewers) -----------------------

    case 'recipeMembers': {
      $id = intParam($_GET, 'id');
      requireViewRecipe($uid, $id);
      $rows = q(
        "SELECT u.id, u.username, u.display_name, m.role FROM sft_recipe_members m JOIN users u ON u.id = m.user_id
          WHERE m.recipe_id = ? ORDER BY FIELD(m.role, 'co_owner', 'editor', 'viewer'), u.display_name", 'i', [$id]);
      respond(['ok' => true, 'members' => array_map(fn($r) => publicUser($r) + ['role' => $r['role']], $rows)]);
    }

    case 'addRecipeMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'recipeId');
      requireViewRecipe($uid, $id);
      if (recipeRole($uid, $id) !== 'co_owner') { fail('Only a co-owner can share this recipe', 403); }
      $role = roleParam($b, ['co_owner', 'editor', 'viewer']);
      $invitee = userByUsername((string)($b['username'] ?? ''));
      if (!$invitee) { fail('No My Apps Hub account uses that username. They need to sign up on the Hub first.', 404, 'username'); }
      $inviteeId = (int)$invitee['id'];
      if (recipeRole($inviteeId, $id) !== null) { fail('They already have a role on this recipe', 409, 'username'); }
      exec_('INSERT INTO sft_recipe_members (recipe_id, user_id, role, added_by) VALUES (?, ?, ?, ?)', 'iisi', [$id, $inviteeId, $role, $uid]);
      grantAppAccess($inviteeId);
      audit($uid, 'recipe', $id, 'member_added', "user $inviteeId as $role");
      respond(['ok' => true, 'member' => publicUser($invitee) + ['role' => $role]]);
    }

    case 'setRecipeMemberRole': {
      requirePost();
      $b = body();
      $id = intParam($b, 'recipeId');
      $memberId = intParam($b, 'userId');
      requireViewRecipe($uid, $id);
      if (recipeRole($uid, $id) !== 'co_owner') { fail('Only a co-owner can change roles', 403); }
      $role = roleParam($b, ['co_owner', 'editor', 'viewer']);
      $current = recipeRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'co_owner' && $role !== 'co_owner' && ownerCount('sft_recipe_members', 'recipe_id', $id, 'co_owner') <= 1) {
        fail('A recipe needs at least one co-owner. Make someone else a co-owner first.', 409);
      }
      exec_('UPDATE sft_recipe_members SET role = ? WHERE recipe_id = ? AND user_id = ?', 'sii', [$role, $id, $memberId]);
      audit($uid, 'recipe', $id, 'role_changed', "user $memberId: $current → $role");
      respond(['ok' => true]);
    }

    case 'removeRecipeMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'recipeId');
      $memberId = intParam($b, 'userId');
      requireViewRecipe($uid, $id);
      if ($memberId !== $uid && recipeRole($uid, $id) !== 'co_owner') { fail('Only a co-owner can remove people', 403); }
      $current = recipeRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'co_owner' && ownerCount('sft_recipe_members', 'recipe_id', $id, 'co_owner') <= 1) {
        fail('A recipe needs at least one co-owner. Make someone else a co-owner first, or delete the recipe.', 409);
      }
      exec_('DELETE FROM sft_recipe_members WHERE recipe_id = ? AND user_id = ?', 'ii', [$id, $memberId]);
      audit($uid, 'recipe', $id, $memberId === $uid ? 'member_left' : 'member_removed', "user $memberId ($current)");
      respond(['ok' => true]);
    }

    // ---- Scaling (personal) ------------------------------------------------

    // Remembers "I make this as 2 pans" for this user only; the master recipe never changes.
    case 'setSavedScale': {
      requirePost();
      $b = body();
      $id = intParam($b, 'recipeId');
      requireViewRecipe($uid, $id);
      $mode = null;
      $value = null;
      if (($b['value'] ?? null) !== null && $b['value'] !== '') {
        $mode = scaleModeParam($b);
        $value = cleanNumber($b['value'], 'value', 10000);
      }
      exec_('INSERT INTO sft_recipe_user_state (recipe_id, user_id, saved_scale_mode, saved_scale) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE saved_scale_mode = VALUES(saved_scale_mode), saved_scale = VALUES(saved_scale)',
        'iisd', [$id, $uid, $mode, $value]);
      respond(['ok' => true]);
    }

    // ---- Shopping lists ----------------------------------------------------

    case 'listLists': {
      $rows = q(
        "SELECT l.id, l.title, l.status, l.updated_at, l.completed_at, m.role,
                (SELECT COUNT(*) FROM sft_shopping_items i WHERE i.list_id = l.id AND i.deleted_at IS NULL
                   AND i.quantity IS NOT NULL AND i.quantity > 0) AS items,
                (SELECT COUNT(*) FROM sft_shopping_items i WHERE i.list_id = l.id AND i.deleted_at IS NULL
                   AND i.quantity IS NOT NULL AND i.quantity > 0 AND i.checked = 1) AS checked_items,
                (SELECT COUNT(*) FROM sft_shopping_list_members m2 WHERE m2.list_id = l.id) AS members
           FROM sft_shopping_lists l JOIN sft_shopping_list_members m ON m.list_id = l.id AND m.user_id = ?
          WHERE l.deleted_at IS NULL ORDER BY l.status = 'completed', COALESCE(l.completed_at, l.updated_at) DESC", 'i', [$uid]);
      respond(['ok' => true, 'lists' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status'], 'role' => $r['role'],
        'items' => (int)$r['items'], 'checked' => (int)$r['checked_items'], 'shared' => (int)$r['members'] > 1,
        'updatedAt' => $r['updated_at'], 'completedAt' => $r['completed_at'],
      ], $rows)]);
    }

    case 'createList': {
      requirePost();
      $title = textParam(body(), 'title', 150);
      db()->begin_transaction();
      $id = exec_('INSERT INTO sft_shopping_lists (title, created_by) VALUES (?, ?)', 'si', [$title, $uid]);
      exec_("INSERT INTO sft_shopping_list_members (list_id, user_id, role, added_by) VALUES (?, ?, 'owner', ?)", 'iii', [$id, $uid, $uid]);
      db()->commit();
      respond(['ok' => true, 'id' => $id]);
    }

    case 'updateList': {
      requirePost();
      $b = body();
      $id = intParam($b, 'id');
      $role = requireListRole($uid, $id, ['owner', 'editor']);
      if (array_key_exists('title', $b)) {
        if ($role !== 'owner') { fail('Only the list\'s owner can rename it', 403); }
        exec_('UPDATE sft_shopping_lists SET title = ? WHERE id = ?', 'si', [textParam($b, 'title', 150), $id]);
      }
      if (array_key_exists('status', $b)) {
        $status = $b['status'] === 'completed' ? 'completed' : 'active';
        exec_('UPDATE sft_shopping_lists SET status = ?, completed_at = ' . ($status === 'completed' ? 'NOW()' : 'NULL') . ' WHERE id = ?',
          'si', [$status, $id]);
      }
      listSeq($id);
      respond(['ok' => true]);
    }

    case 'deleteList': {
      requirePost();
      $id = intParam(body(), 'id');
      requireListRole($uid, $id, ['owner']);
      exec_('UPDATE sft_shopping_lists SET deleted_at = NOW() WHERE id = ?', 'i', [$id]);
      audit($uid, 'shopping_list', $id, 'deleted');
      respond(['ok' => true]);
    }

    // A fresh copy (everything unchecked) owned by the person copying — e.g. reuse last
    // year's Thanksgiving list.
    case 'copyList': {
      requirePost();
      $b = body();
      $from = intParam($b, 'id');
      requireListRole($uid, $from, ['owner', 'editor', 'viewer']);
      $title = textParam($b, 'title', 150);
      db()->begin_transaction();
      $id = exec_('INSERT INTO sft_shopping_lists (title, created_by) VALUES (?, ?)', 'si', [$title, $uid]);
      exec_("INSERT INTO sft_shopping_list_members (list_id, user_id, role, added_by) VALUES (?, ?, 'owner', ?)", 'iii', [$id, $uid, $uid]);
      $recipeMap = [];
      foreach (q('SELECT * FROM sft_shopping_list_recipes WHERE list_id = ?', 'i', [$from]) as $lr) {
        $recipeMap[(int)$lr['id']] = exec_(
          'INSERT INTO sft_shopping_list_recipes (list_id, recipe_id, scale_mode, scale_value, scale_factor, excluded_ingredient_ids, added_by)
           VALUES (?, ?, ?, ?, ?, ?, ?)', 'iisddsi',
          [$id, (int)$lr['recipe_id'], $lr['scale_mode'], (float)$lr['scale_value'], (float)$lr['scale_factor'], $lr['excluded_ingredient_ids'], $uid]);
      }
      foreach (q('SELECT * FROM sft_shopping_items WHERE list_id = ? AND deleted_at IS NULL ORDER BY position, id', 'i', [$from]) as $it) {
        $newItem = exec_(
          'INSERT INTO sft_shopping_items (list_id, ingredient_id, merge_key, label, quantity, unit, notes, department, department_manual,
             store_id, is_manual, is_edited, position, updated_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'iissdsssiiiiii',
          [$id, $it['ingredient_id'] !== null ? (int)$it['ingredient_id'] : null, $it['merge_key'], $it['label'],
           $it['quantity'] !== null ? (float)$it['quantity'] : null, $it['unit'], $it['notes'], $it['department'],
           (int)$it['department_manual'], $it['store_id'] !== null ? (int)$it['store_id'] : null, (int)$it['is_manual'],
           (int)$it['is_edited'], (int)$it['position'], $uid]);
        foreach (q('SELECT * FROM sft_shopping_item_sources WHERE item_id = ?', 'i', [(int)$it['id']]) as $s) {
          if (!isset($recipeMap[(int)$s['list_recipe_id']])) { continue; }
          exec_('INSERT INTO sft_shopping_item_sources (item_id, list_recipe_id, recipe_ingredient_id, contributed_quantity, contributed_unit)
                 VALUES (?, ?, ?, ?, ?)', 'iiids',
            [$newItem, $recipeMap[(int)$s['list_recipe_id']], (int)$s['recipe_ingredient_id'],
             $s['contributed_quantity'] !== null ? (float)$s['contributed_quantity'] : null, $s['contributed_unit']]);
        }
      }
      db()->commit();
      respond(['ok' => true, 'id' => $id]);
    }

    case 'getList': {
      $id = intParam($_GET, 'id');
      $role = requireListRole($uid, $id, ['owner', 'editor', 'viewer']);
      $l = q1('SELECT id, title, status, change_seq, completed_at FROM sft_shopping_lists WHERE id = ?', 'i', [$id]);
      $items = listItems($id);
      respond(['ok' => true, 'list' => [
        'id' => (int)$l['id'], 'title' => $l['title'], 'status' => $l['status'], 'role' => $role,
        'changeSeq' => (int)$l['change_seq'], 'completedAt' => $l['completed_at'],
      ], 'items' => $items['items'], 'recipes' => listRecipes($id), 'members' => listMembersShort($id),
        'stores' => storesFor($uid, $id), 'departments' => DEPT_ORDER, 'me' => $uid]);
    }

    // Polled by every open copy of the list: only what changed since `since`.
    case 'listChanges': {
      $id = intParam($_GET, 'id');
      requireListRole($uid, $id, ['owner', 'editor', 'viewer']);
      $since = (int)($_GET['since'] ?? 0);
      $l = q1('SELECT title, status, change_seq FROM sft_shopping_lists WHERE id = ?', 'i', [$id]);
      if ((int)$l['change_seq'] === $since) {
        respond(['ok' => true, 'changeSeq' => $since, 'unchanged' => true]);
      }
      $changes = listItems($id, $since);
      respond(['ok' => true, 'changeSeq' => (int)$l['change_seq'], 'title' => $l['title'], 'status' => $l['status'],
        'items' => $changes['items'], 'deleted' => $changes['deleted'], 'recipes' => listRecipes($id),
        'members' => listMembersShort($id), 'stores' => storesFor($uid, $id)]);
    }

    // ---- Recipes on a list -------------------------------------------------

    case 'addRecipeToList': {
      requirePost();
      $b = body();
      $listId = intParam($b, 'listId');
      $recipeId = intParam($b, 'recipeId');
      requireListRole($uid, $listId, ['owner', 'editor']);
      requireViewRecipe($uid, $recipeId);
      $existing = q1('SELECT id FROM sft_shopping_list_recipes WHERE list_id = ? AND recipe_id = ?', 'ii', [$listId, $recipeId]);
      if ($existing) {
        respond(['ok' => false, 'error' => 'That recipe is already on this list — change its amount there.',
          'listRecipeId' => (int)$existing['id']], 409);
      }
      [$mode, $value, $factor, $excluded] = listRecipeSettings($recipeId, $b, $uid);
      db()->begin_transaction();
      $lrId = exec_('INSERT INTO sft_shopping_list_recipes (list_id, recipe_id, scale_mode, scale_value, scale_factor, excluded_ingredient_ids, added_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)', 'iisddsi', [$listId, $recipeId, $mode, $value, $factor, json_encode($excluded), $uid]);
      contributeRecipe($listId, $lrId, $recipeId, $factor, $excluded, $uid);
      exec_('UPDATE sft_shopping_lists SET updated_at = NOW() WHERE id = ?', 'i', [$listId]);
      db()->commit();
      respond(['ok' => true, 'listRecipeId' => $lrId]);
    }

    // New amount or different "have it" choices: take the old contribution out, put the new one in.
    case 'updateListRecipe': {
      requirePost();
      $b = body();
      $lr = q1('SELECT * FROM sft_shopping_list_recipes WHERE id = ?', 'i', [intParam($b, 'id')]);
      if (!$lr || listRole($uid, (int)$lr['list_id']) === null) { notFound('Recipe on this list'); }
      $listId = (int)$lr['list_id'];
      requireListRole($uid, $listId, ['owner', 'editor']);
      [$mode, $value, $factor, $excluded] = listRecipeSettings((int)$lr['recipe_id'], $b);
      db()->begin_transaction();
      withdrawRecipe($listId, (int)$lr['id'], $uid);
      exec_('UPDATE sft_shopping_list_recipes SET scale_mode = ?, scale_value = ?, scale_factor = ?, excluded_ingredient_ids = ? WHERE id = ?',
        'sddsi', [$mode, $value, $factor, json_encode($excluded), (int)$lr['id']]);
      contributeRecipe($listId, (int)$lr['id'], (int)$lr['recipe_id'], $factor, $excluded, $uid);
      db()->commit();
      respond(['ok' => true]);
    }

    case 'removeListRecipe': {
      requirePost();
      $lr = q1('SELECT * FROM sft_shopping_list_recipes WHERE id = ?', 'i', [intParam(body(), 'id')]);
      if (!$lr || listRole($uid, (int)$lr['list_id']) === null) { notFound('Recipe on this list'); }
      $listId = (int)$lr['list_id'];
      requireListRole($uid, $listId, ['owner', 'editor']);
      db()->begin_transaction();
      withdrawRecipe($listId, (int)$lr['id'], $uid);
      exec_('DELETE FROM sft_shopping_list_recipes WHERE id = ?', 'i', [(int)$lr['id']]);
      listSeq($listId);
      db()->commit();
      respond(['ok' => true]);
    }

    case 'rebuildList': {
      requirePost();
      $listId = intParam(body(), 'listId');
      requireListRole($uid, $listId, ['owner', 'editor']);
      db()->begin_transaction();
      rebuildList($listId, $uid);
      db()->commit();
      respond(['ok' => true]);
    }

    // ---- Items -------------------------------------------------------------

    case 'addItem': {
      requirePost();
      $b = body();
      $listId = intParam($b, 'listId');
      requireListRole($uid, $listId, ['owner', 'editor']);
      $label = textParam($b, 'label', 200);
      $qty = itemQuantity($b['quantity'] ?? null);
      $unit = itemUnit($b['unit'] ?? null);
      $dept = isset($b['department']) && $b['department'] !== '' ? cleanDepartment($b['department']) : guessDepartment($label);
      $storeId = itemStore($uid, $listId, $b['storeId'] ?? null);
      db()->begin_transaction();
      $id = exec_(
        'INSERT INTO sft_shopping_items (list_id, label, quantity, unit, notes, department, department_manual, store_id, is_manual, position, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', 'isdsssiiii',
        [$listId, $label, $qty, $unit, textParam($b, 'notes', 255, false), $dept, isset($b['department']) && $b['department'] !== '' ? 1 : 0,
         $storeId, nextItemPosition($listId), $uid]);
      touchItem($listId, $id, $uid);
      db()->commit();
      respond(['ok' => true, 'item' => listItemById($id)]);
    }

    // Field-level compare-and-set: each changed field carries the value the editor started from.
    // If someone else changed THAT field meanwhile, nothing is saved and the current item comes back.
    case 'updateItem': {
      requirePost();
      $b = body();
      $item = requireItem($uid, intParam($b, 'id'), ['owner', 'editor']);
      $listId = (int)$item['list_id'];
      $changes = (array)($b['changes'] ?? []);
      $base = (array)($b['base'] ?? []);
      $columns = ['label' => 'label', 'quantity' => 'quantity', 'unit' => 'unit', 'notes' => 'notes',
        'department' => 'department', 'storeId' => 'store_id', 'assignedUserId' => 'assigned_user_id'];
      foreach ($changes as $field => $_) {
        if (!isset($columns[$field])) { fail('Unknown field ' . $field); }
        if (!array_key_exists($field, $base) || !sameValue($field, $item[$columns[$field]], $base[$field])) {
          $who = q1('SELECT u.id, u.username, u.display_name FROM users u WHERE u.id = ?', 'i', [(int)$item['updated_by']]);
          respond(['ok' => false, 'conflict' => true, 'error' => 'Someone else changed this item first.',
            'changedBy' => $who ? publicUser($who) : null, 'item' => listItemById((int)$item['id'])], 409);
        }
      }
      $set = [];
      $types = '';
      $params = [];
      $add = function (string $col, string $t, $v) use (&$set, &$types, &$params) { $set[] = "$col = ?"; $types .= $t; $params[] = $v; };
      if (array_key_exists('label', $changes)) {
        $label = textParam($changes, 'label', 200);
        $add('label', 's', $label);
        if (!(int)$item['department_manual'] && !array_key_exists('department', $changes)) { $add('department', 's', guessDepartment($label)); }
      }
      if (array_key_exists('quantity', $changes)) { $add('quantity', 'd', itemQuantity($changes['quantity'])); }
      if (array_key_exists('unit', $changes)) { $add('unit', 's', itemUnit($changes['unit'])); }
      if (array_key_exists('notes', $changes)) { $add('notes', 's', textParam($changes, 'notes', 255, false)); }
      if (array_key_exists('department', $changes)) { $add('department', 's', cleanDepartment($changes['department'])); $add('department_manual', 'i', 1); }
      if (array_key_exists('storeId', $changes)) { $add('store_id', 'i', itemStore($uid, $listId, $changes['storeId'])); }
      if (array_key_exists('assignedUserId', $changes)) {
        $assignee = $changes['assignedUserId'] === null || $changes['assignedUserId'] === '' ? null : (int)$changes['assignedUserId'];
        if ($assignee !== null && listRole($assignee, $listId) === null) { fail('They aren\'t on this list', 400, 'assignedUserId'); }
        $add('assigned_user_id', 'i', $assignee);
      }
      // Hand-typed amounts are kept when more recipes are added (§6.4).
      if (!(int)$item['is_manual'] && array_intersect(['label', 'quantity', 'unit'], array_keys($changes))) {
        $add('is_edited', 'i', 1);
        $add('needs_review', 'i', 0);
      }
      if ($set) {
        db()->begin_transaction();
        $types .= 'i';
        $params[] = (int)$item['id'];
        exec_('UPDATE sft_shopping_items SET ' . implode(', ', $set) . ' WHERE id = ?', $types, $params);
        touchItem($listId, (int)$item['id'], $uid);
        db()->commit();
      }
      respond(['ok' => true, 'item' => listItemById((int)$item['id'])]);
    }

    // Sends the wanted state, not a toggle — two people tapping eggs at once both end with eggs checked.
    case 'setItemChecked': {
      requirePost();
      $b = body();
      $item = requireItem($uid, intParam($b, 'id'), ['owner', 'editor']);
      $checked = !empty($b['checked']) ? 1 : 0;
      if ((int)$item['checked'] !== $checked) {
        db()->begin_transaction();
        exec_('UPDATE sft_shopping_items SET checked = ?, checked_by = ?, checked_at = ' . ($checked ? 'NOW()' : 'NULL') . ' WHERE id = ?',
          'iii', [$checked, $checked ? $uid : null, (int)$item['id']]);
        touchItem((int)$item['list_id'], (int)$item['id'], $uid);
        db()->commit();
      }
      respond(['ok' => true, 'item' => listItemById((int)$item['id'])]);
    }

    case 'deleteItem': {
      requirePost();
      $item = requireItem($uid, intParam(body(), 'id'), ['owner', 'editor']);
      db()->begin_transaction();
      exec_('UPDATE sft_shopping_items SET deleted_at = NOW() WHERE id = ?', 'i', [(int)$item['id']]);
      exec_('DELETE FROM sft_shopping_item_sources WHERE item_id = ?', 'i', [(int)$item['id']]);
      touchItem((int)$item['list_id'], (int)$item['id'], $uid);
      db()->commit();
      respond(['ok' => true]);
    }

    case 'addStore': {
      requirePost();
      $name = textParam(body(), 'name', 80);
      $existing = q1("SELECT id FROM sft_stores WHERE name = ? AND (scope = 'system' OR (scope = 'user' AND owner_user_id = ?))", 'si', [$name, $uid]);
      $id = $existing ? (int)$existing['id']
        : exec_("INSERT INTO sft_stores (name, scope, owner_user_id) VALUES (?, 'user', ?)", 'si', [$name, $uid]);
      respond(['ok' => true, 'store' => ['id' => $id, 'name' => $name]]);
    }

    // ---- List sharing ------------------------------------------------------

    case 'listMembers': {
      $id = intParam($_GET, 'id');
      requireListRole($uid, $id, ['owner', 'editor', 'viewer']);
      respond(['ok' => true, 'members' => listMembersShort($id)]);
    }

    case 'addListMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'listId');
      requireListRole($uid, $id, ['owner']);
      $role = roleParam($b, ['owner', 'editor', 'viewer']);
      $invitee = userByUsername((string)($b['username'] ?? ''));
      if (!$invitee) { fail('No My Apps Hub account uses that username. They need to sign up on the Hub first.', 404, 'username'); }
      $inviteeId = (int)$invitee['id'];
      if (listRole($inviteeId, $id) !== null) { fail('They\'re already on this list', 409, 'username'); }
      exec_('INSERT INTO sft_shopping_list_members (list_id, user_id, role, added_by) VALUES (?, ?, ?, ?)', 'iisi', [$id, $inviteeId, $role, $uid]);
      grantAppAccess($inviteeId);
      listSeq($id);
      audit($uid, 'shopping_list', $id, 'member_added', "user $inviteeId as $role");
      respond(['ok' => true, 'member' => publicUser($invitee) + ['role' => $role]]);
    }

    case 'setListMemberRole': {
      requirePost();
      $b = body();
      $id = intParam($b, 'listId');
      $memberId = intParam($b, 'userId');
      requireListRole($uid, $id, ['owner']);
      $role = roleParam($b, ['owner', 'editor', 'viewer']);
      $current = listRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'owner' && $role !== 'owner' && ownerCount('sft_shopping_list_members', 'list_id', $id, 'owner') <= 1) {
        fail('A list needs at least one owner. Make someone else an owner first.', 409);
      }
      exec_('UPDATE sft_shopping_list_members SET role = ? WHERE list_id = ? AND user_id = ?', 'sii', [$role, $id, $memberId]);
      listSeq($id);
      audit($uid, 'shopping_list', $id, 'role_changed', "user $memberId: $current → $role");
      respond(['ok' => true]);
    }

    case 'removeListMember': {
      requirePost();
      $b = body();
      $id = intParam($b, 'listId');
      $memberId = intParam($b, 'userId');
      if ($memberId === $uid) { requireListRole($uid, $id, ['owner', 'editor', 'viewer']); }
      else { requireListRole($uid, $id, ['owner']); }
      $current = listRole($memberId, $id);
      if ($current === null) { notFound('Member'); }
      if ($current === 'owner' && ownerCount('sft_shopping_list_members', 'list_id', $id, 'owner') <= 1) {
        fail('A list needs at least one owner. Make someone else an owner first, or delete the list.', 409);
      }
      exec_('DELETE FROM sft_shopping_list_members WHERE list_id = ? AND user_id = ?', 'ii', [$id, $memberId]);
      exec_('UPDATE sft_shopping_items SET assigned_user_id = NULL WHERE list_id = ? AND assigned_user_id = ?', 'ii', [$id, $memberId]);
      listSeq($id);
      audit($uid, 'shopping_list', $id, $memberId === $uid ? 'member_left' : 'member_removed', "user $memberId ($current)");
      respond(['ok' => true]);
    }

    // ---- Family notes (ARCHITECTURE.md §3.2, REQUIREMENTS.md §8) ------------
    // Anyone who can see a recipe may add a note: "shared" (everyone who can see the recipe) or
    // "private" (only the author, ever — not even co-owners). Authors edit and delete their own
    // notes; co-owners may also remove shared notes.

    case 'addNote': {
      requirePost();
      $b = body();
      $recipeId = intParam($b, 'recipeId');
      requireViewRecipe($uid, $recipeId);
      $visibility = noteVisibility($b);
      $text = textParam($b, 'text', 5000);
      $id = exec_('INSERT INTO sft_recipe_notes (recipe_id, user_id, visibility, note_text) VALUES (?, ?, ?, ?)',
        'iiss', [$recipeId, $uid, $visibility, $text]);
      respond(['ok' => true, 'note' => noteById($id, $uid)]);
    }

    case 'updateNote': {
      requirePost();
      $b = body();
      $note = visibleNote($uid, intParam($b, 'id'));
      if ((int)$note['user_id'] !== $uid) { fail('Only the person who wrote a note can change it', 403); }
      $text = textParam($b, 'text', 5000);
      $visibility = array_key_exists('visibility', $b) ? noteVisibility($b) : $note['visibility'];
      exec_('UPDATE sft_recipe_notes SET note_text = ?, visibility = ? WHERE id = ?', 'ssi', [$text, $visibility, (int)$note['id']]);
      respond(['ok' => true, 'note' => noteById((int)$note['id'], $uid)]);
    }

    case 'deleteNote': {
      requirePost();
      $note = visibleNote($uid, intParam(body(), 'id'));
      $mine = (int)$note['user_id'] === $uid;
      $moderator = $note['visibility'] === 'shared' && recipeRole($uid, (int)$note['recipe_id']) === 'co_owner';
      if (!$mine && !$moderator) { fail('Only the person who wrote a note, or a co-owner, can remove it', 403); }
      exec_('UPDATE sft_recipe_notes SET deleted_at = NOW() WHERE id = ?', 'i', [(int)$note['id']]);
      if (!$mine) { audit($uid, 'note', (int)$note['id'], 'deleted', 'shared note by user ' . $note['user_id']); }
      respond(['ok' => true]);
    }

    // ---- Photos (ARCHITECTURE.md §8) -------------------------------------------
    // Recipe photos: added and arranged by anyone who can edit the recipe; the first is the
    // cover. Note photos: added and removed by the note's author, seen by whoever sees the note.
    // Uploads are multipart (the file is "file"); the action and ids go in the address.

    case 'uploadRecipePhoto': {
      requirePost();
      $recipeId = intParam($_GET, 'recipeId');
      requireViewRecipe($uid, $recipeId);
      if (!canEditRecipe($uid, $recipeId)) { fail('Only people who can edit this recipe can add photos', 403); }
      $count = q1('SELECT COUNT(*) AS n, COALESCE(MAX(position), -1) AS last FROM sft_recipe_photos WHERE recipe_id = ? AND deleted_at IS NULL', 'i', [$recipeId]);
      if ((int)$count['n'] >= RECIPE_PHOTO_LIMIT) { fail('A recipe can have up to ' . RECIPE_PHOTO_LIMIT . ' photos', 400, 'file'); }
      $caption = textParam($_POST, 'caption', 255, false);
      $stored = storeUploadedPhoto($_FILES['file'] ?? null);
      $id = exec_('INSERT INTO sft_recipe_photos (recipe_id, storage_key, caption, is_cover, position, width, height, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'issiiiii', [$recipeId, $stored['key'], $caption, (int)$count['n'] === 0 ? 1 : 0, (int)$count['last'] + 1, $stored['width'], $stored['height'], $uid]);
      audit($uid, 'photo', $id, 'added', "recipe $recipeId");
      respond(['ok' => true, 'id' => $id, 'photos' => recipePhotos($recipeId)]);
    }

    case 'setPhotoCaption': {
      requirePost();
      $b = body();
      $p = editableRecipePhoto($uid, intParam($b, 'id'));
      exec_('UPDATE sft_recipe_photos SET caption = ? WHERE id = ?', 'si', [textParam($b, 'caption', 255, false), (int)$p['id']]);
      respond(['ok' => true, 'photos' => recipePhotos((int)$p['recipe_id'])]);
    }

    // Move a photo one place earlier (direction -1) or later (1), or to the front (`first`),
    // where it becomes the cover.
    case 'movePhoto': {
      requirePost();
      $b = body();
      $p = editableRecipePhoto($uid, intParam($b, 'id'));
      $recipeId = (int)$p['recipe_id'];
      tidyRecipePhotos($recipeId);
      $ids = array_map(fn($r) => (int)$r['id'], q('SELECT id FROM sft_recipe_photos WHERE recipe_id = ? AND deleted_at IS NULL ORDER BY position', 'i', [$recipeId]));
      $from = array_search((int)$p['id'], $ids, true);
      $to = !empty($b['first']) ? 0 : max(0, min(count($ids) - 1, $from + ((int)($b['direction'] ?? 0) < 0 ? -1 : 1)));
      array_splice($ids, $from, 1);
      array_splice($ids, $to, 0, [(int)$p['id']]);
      foreach ($ids as $i => $pid) { exec_('UPDATE sft_recipe_photos SET position = ? WHERE id = ?', 'ii', [$i, $pid]); }
      tidyRecipePhotos($recipeId);
      respond(['ok' => true, 'photos' => recipePhotos($recipeId)]);
    }

    // Removed photos are kept for 30 days (the purge job deletes the files), like recipes.
    case 'deletePhoto': {
      requirePost();
      $p = editableRecipePhoto($uid, intParam(body(), 'id'));
      exec_('UPDATE sft_recipe_photos SET deleted_at = NOW() WHERE id = ?', 'i', [(int)$p['id']]);
      tidyRecipePhotos((int)$p['recipe_id']);
      audit($uid, 'photo', (int)$p['id'], 'deleted', 'recipe ' . $p['recipe_id']);
      respond(['ok' => true, 'photos' => recipePhotos((int)$p['recipe_id'])]);
    }

    case 'uploadNotePhoto': {
      requirePost();
      $note = visibleNote($uid, intParam($_GET, 'noteId'));
      if ((int)$note['user_id'] !== $uid) { fail('Only the person who wrote a note can add photos to it', 403); }
      $n = q1('SELECT COUNT(*) AS n FROM sft_recipe_note_photos WHERE note_id = ?', 'i', [(int)$note['id']]);
      if ((int)$n['n'] >= NOTE_PHOTO_LIMIT) { fail('A note can have up to ' . NOTE_PHOTO_LIMIT . ' photos', 400, 'file'); }
      $stored = storeUploadedPhoto($_FILES['file'] ?? null);
      exec_('INSERT INTO sft_recipe_note_photos (note_id, storage_key) VALUES (?, ?)', 'is', [(int)$note['id'], $stored['key']]);
      respond(['ok' => true, 'note' => noteById((int)$note['id'], $uid)]);
    }

    case 'deleteNotePhoto': {
      requirePost();
      $p = q1('SELECT id, note_id, storage_key FROM sft_recipe_note_photos WHERE id = ?', 'i', [intParam(body(), 'id')]);
      if (!$p) { notFound('Photo'); }
      $note = visibleNote($uid, (int)$p['note_id']);
      if ((int)$note['user_id'] !== $uid) { fail('Only the person who wrote a note can remove its photos', 403); }
      exec_('DELETE FROM sft_recipe_note_photos WHERE id = ?', 'i', [(int)$p['id']]);
      removePhotoFiles($p['storage_key']);
      respond(['ok' => true, 'note' => noteById((int)$note['id'], $uid)]);
    }

    // ---- Tags (REQUIREMENTS.md §7) --------------------------------------------
    // system: built-in, set by recipe editors · cookbook: belongs to a cookbook, set by its
    // owners/editors, seen by its members · user: personal, seen and set only by its owner.

    // Everything the user could put on this recipe, and what's on it now.
    case 'tagChoices': {
      $recipeId = intParam($_GET, 'recipeId');
      requireViewRecipe($uid, $recipeId);
      $on = array_flip(array_map(fn($r) => (int)$r['category_id'],
        q('SELECT category_id FROM sft_recipe_categories WHERE recipe_id = ?', 'i', [$recipeId])));
      $shape = fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'on' => isset($on[(int)$r['id']])];
      $books = q(
        'SELECT c.id, c.title, m.role FROM sft_cookbook_recipes cr JOIN sft_cookbooks c ON c.id = cr.cookbook_id AND c.deleted_at IS NULL
           JOIN sft_cookbook_members m ON m.cookbook_id = c.id AND m.user_id = ? WHERE cr.recipe_id = ? ORDER BY c.title', 'ii', [$uid, $recipeId]);
      respond(['ok' => true,
        'canEditBuiltIn' => canEditRecipe($uid, $recipeId),
        'builtIn' => array_map($shape, q("SELECT id, name, kind FROM sft_categories WHERE scope = 'system'
                                          ORDER BY FIELD(kind, 'category', 'method', 'descriptor'), id")),
        'cookbooks' => array_map(fn($b) => [
          'id' => (int)$b['id'], 'title' => $b['title'], 'canEdit' => in_array($b['role'], ['owner', 'editor'], true),
          'tags' => array_map($shape, q("SELECT id, name, kind FROM sft_categories WHERE scope = 'cookbook' AND cookbook_id = ? ORDER BY name", 'i', [(int)$b['id']])),
        ], $books),
        'mine' => array_map($shape, q("SELECT id, name, kind FROM sft_categories WHERE scope = 'user' AND owner_user_id = ? ORDER BY name", 'i', [$uid])),
      ]);
    }

    case 'setRecipeTag': {
      requirePost();
      $b = body();
      $recipeId = intParam($b, 'recipeId');
      requireViewRecipe($uid, $recipeId);
      $tag = requireTagUse($uid, intParam($b, 'tagId'), $recipeId);
      if (!empty($b['on'])) {
        exec_('INSERT IGNORE INTO sft_recipe_categories (recipe_id, category_id, added_by) VALUES (?, ?, ?)', 'iii', [$recipeId, (int)$tag['id'], $uid]);
      } else {
        exec_('DELETE FROM sft_recipe_categories WHERE recipe_id = ? AND category_id = ?', 'ii', [$recipeId, (int)$tag['id']]);
      }
      respond(['ok' => true]);
    }

    // New personal tag, or a tag for one of the user's cookbooks. Same name (any capitals) in the
    // same place → the existing tag is returned instead of a duplicate.
    case 'createTag': {
      requirePost();
      $b = body();
      $name = tagName((string)($b['name'] ?? ''));
      $norm = normalizeName($name);
      if (($b['scope'] ?? 'user') === 'cookbook') {
        $cookbookId = intParam($b, 'cookbookId');
        requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
        $existing = q1("SELECT id, name FROM sft_categories WHERE scope = 'cookbook' AND cookbook_id = ? AND normalized_name = ?", 'is', [$cookbookId, $norm]);
        $id = $existing ? (int)$existing['id'] : exec_("INSERT INTO sft_categories (name, normalized_name, kind, scope, cookbook_id) VALUES (?, ?, 'descriptor', 'cookbook', ?)",
          'ssi', [$name, $norm, $cookbookId]);
      } else {
        $existing = q1("SELECT id, name FROM sft_categories WHERE scope = 'user' AND owner_user_id = ? AND normalized_name = ?", 'is', [$uid, $norm]);
        $id = $existing ? (int)$existing['id'] : exec_("INSERT INTO sft_categories (name, normalized_name, kind, scope, owner_user_id) VALUES (?, ?, 'descriptor', 'user', ?)",
          'ssi', [$name, $norm, $uid]);
      }
      respond(['ok' => true, 'tag' => ['id' => $id, 'name' => $existing ? $existing['name'] : $name], 'existed' => (bool)$existing]);
    }

    case 'renameTag': {
      requirePost();
      $b = body();
      $tag = requireTagManage($uid, intParam($b, 'id'));
      $name = tagName((string)($b['name'] ?? ''));
      $norm = normalizeName($name);
      $clash = q1('SELECT id FROM sft_categories WHERE scope = ? AND COALESCE(owner_user_id, 0) = ? AND COALESCE(cookbook_id, 0) = ?
                   AND normalized_name = ? AND id <> ?', 'siisi',
        [$tag['scope'], (int)$tag['owner_user_id'], (int)$tag['cookbook_id'], $norm, (int)$tag['id']]);
      if ($clash) { fail('There\'s already a tag with that name', 409, 'name'); }
      exec_('UPDATE sft_categories SET name = ?, normalized_name = ? WHERE id = ?', 'ssi', [$name, $norm, (int)$tag['id']]);
      respond(['ok' => true]);
    }

    case 'deleteTag': {
      requirePost();
      $tag = requireTagManage($uid, intParam(body(), 'id'));
      exec_('DELETE FROM sft_categories WHERE id = ?', 'i', [(int)$tag['id']]);   // links go with it (cascade)
      respond(['ok' => true]);
    }

    // Tags people can filter a cookbook by: built-in, the cookbook's own, and the user's own.
    case 'cookbookTags': {
      $cookbookId = intParam($_GET, 'id');
      $role = requireCookbookRole($uid, $cookbookId, ['owner', 'editor', 'viewer']);
      $rows = q(
        "SELECT c.id, c.name, c.scope, COUNT(*) AS n FROM sft_categories c
           JOIN sft_recipe_categories rc ON rc.category_id = c.id
           JOIN sft_cookbook_recipes cr ON cr.recipe_id = rc.recipe_id AND cr.cookbook_id = ?
           JOIN sft_recipes r ON r.id = rc.recipe_id AND r.deleted_at IS NULL
          WHERE c.scope = 'system' OR (c.scope = 'cookbook' AND c.cookbook_id = ?) OR (c.scope = 'user' AND c.owner_user_id = ?)
          GROUP BY c.id, c.name, c.scope ORDER BY c.name", 'iii', [$cookbookId, $cookbookId, $uid]);
      $unused = q("SELECT id, name FROM sft_categories WHERE scope = 'cookbook' AND cookbook_id = ? AND id NOT IN
                    (SELECT category_id FROM sft_recipe_categories) ORDER BY name", 'i', [$cookbookId]);
      respond(['ok' => true, 'canManage' => in_array($role, ['owner', 'editor'], true),
        'tags' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'scope' => $r['scope'], 'count' => (int)$r['n']], $rows),
        'unused' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $unused)]);
    }

    // ---- Paste-a-recipe import (ARCHITECTURE.md §7.1) ----------------------------
    // The reader runs in the browser; the server keeps the original text, what the reader made
    // of it, and what was finally saved, so nothing pasted is ever lost.

    // "You may already have this": recipes the user can see with a similar title or most of
    // the same ingredients.
    case 'similarRecipes': {
      requirePost();
      $b = body();
      $cookbookId = isset($b['cookbookId']) && $b['cookbookId'] !== '' ? (int)$b['cookbookId'] : null;
      respond(['ok' => true, 'matches' => similarRecipes($uid, (string)($b['title'] ?? ''), (array)($b['ingredients'] ?? []), $cookbookId)]);
    }

    case 'importRecipe': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      $raw = importRawText($b);
      $clean = cleanRecipeInput((array)($b['recipe'] ?? []));
      db()->begin_transaction();
      $id = createRecipe($uid, $cookbookId, $clean, 'Imported from pasted text');
      recordImport($uid, $id, $raw, $b, 'created', $clean);
      db()->commit();
      respond(['ok' => true, 'id' => $id]);
    }

    // The pasted recipe was already there: add that one to the cookbook instead (no copy).
    case 'importLinkExisting': {
      requirePost();
      $b = body();
      $cookbookId = intParam($b, 'cookbookId');
      $recipeId = intParam($b, 'recipeId');
      requireCookbookRole($uid, $cookbookId, ['owner', 'editor']);
      requireViewRecipe($uid, $recipeId);
      $raw = importRawText($b);
      db()->begin_transaction();
      exec_('INSERT IGNORE INTO sft_cookbook_recipes (cookbook_id, recipe_id, added_by) VALUES (?, ?, ?)', 'iii', [$cookbookId, $recipeId, $uid]);
      recordImport($uid, $recipeId, $raw, $b, 'linked_existing', null);
      db()->commit();
      respond(['ok' => true]);
    }

    // The original pasted text of a recipe, for anyone who can see the recipe.
    case 'recipeImport': {
      $id = intParam($_GET, 'id');
      requireViewRecipe($uid, $id);
      $row = q1("SELECT i.raw_text, i.imported_at, i.parser_version, u.id AS uid, u.username, u.display_name
                   FROM sft_recipe_imports i JOIN users u ON u.id = i.imported_by
                  WHERE i.recipe_id = ? AND i.outcome = 'created' AND i.parser_version LIKE 'paste%' ORDER BY i.id DESC LIMIT 1", 'i', [$id]);
      respond(['ok' => true, 'import' => $row ? [
        'text' => $row['raw_text'], 'importedAt' => $row['imported_at'], 'parser' => $row['parser_version'],
        'by' => publicUser(['id' => $row['uid'], 'username' => $row['username'], 'display_name' => $row['display_name']]),
      ] : null]);
    }

    // ---- Admin -------------------------------------------------------------

    // One-time Senior Family Cookbook import (docs/IMPORT-SENIOR-FAMILY.md), for hosts
    // without SSH. Hub admins only. {"dryRun": true} rolls everything back.
    case 'adminImportSeniorFamily': {
      requirePost();
      if (!(bool)$user['is_admin']) { fail('Only a Hub admin can run the import', 403); }
      require_once __DIR__ . '/lib/import_senior_family.php';
      set_time_limit(300);
      $report = importSeniorFamily($uid, (bool)(body()['dryRun'] ?? true));
      respond(['ok' => true, 'report' => $report]);
    }

    default:
      fail('Unknown action', 404);
  }
} catch (AppError $e) {
  // An open transaction is rolled back automatically when the connection closes.
  respond(['ok' => false, 'error' => $e->getMessage()] + ($e->field !== null ? ['field' => $e->field] : []), $e->status);
} catch (Throwable $e) {
  error_log('senior-family-table: ' . $e->getMessage());
  respond(['ok' => false, 'error' => 'Something went wrong on the server. Please try again.'], 500);
}
