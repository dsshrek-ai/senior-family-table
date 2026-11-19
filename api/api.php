<?php
// The Senior Family Table — API.
// Sign-in is My Apps Hub SSO; every action checks the permission rules in lib/core.php
// (docs/ARCHITECTURE.md §3.2). Guessed IDs always get "not found".

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/recipes.php';

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
        'SELECT r.id, r.title, r.description, r.servings, cr.display_order,
                (SELECT us.is_favorite FROM sft_recipe_user_state us WHERE us.recipe_id = r.id AND us.user_id = ?) AS fav
           FROM sft_cookbook_recipes cr JOIN sft_recipes r ON r.id = cr.recipe_id AND r.deleted_at IS NULL
          WHERE cr.cookbook_id = ? ORDER BY cr.display_order, r.title',
        'ii', [$uid, $id]
      );
      $out = [];
      foreach ($recipes as $r) {
        $out[] = [
          'id' => (int)$r['id'], 'title' => $r['title'], 'description' => $r['description'],
          'servings' => $r['servings'] !== null ? (float)$r['servings'] : null, 'favorite' => (bool)$r['fav'],
          'tags' => array_map(fn($t) => $t['name'], visibleRecipeTags($uid, (int)$r['id'])),
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

      $recipe += [
        'myRole' => $role, 'canEdit' => in_array($role, ['co_owner', 'editor'], true), 'canManage' => $role === 'co_owner',
        'updatedBy' => $updatedBy ? publicUser($updatedBy) : null,
        'tags' => visibleRecipeTags($uid, $id),
        'cookbooks' => array_map(fn($c) => ['id' => (int)$c['id'], 'title' => $c['title'], 'role' => $c['role']], $cookbooks),
        'notes' => array_map(fn($n) => [
          'id' => (int)$n['id'], 'visibility' => $n['visibility'], 'text' => $n['note_text'],
          'author' => publicUser(['id' => $n['author_id'], 'username' => $n['username'], 'display_name' => $n['display_name']]),
          'mine' => (int)$n['author_id'] === $uid, 'createdAt' => $n['created_at'], 'updatedAt' => $n['updated_at'],
        ], $notes),
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
                                OR (c.scope = 'cookbook' AND EXISTS (SELECT 1 FROM sft_cookbook_members m WHERE m.cookbook_id = c.cookbook_id AND m.user_id = ?)))))";
        $types .= 'sssii';
        array_push($params, $like, $like, $like, $uid, $uid);
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
